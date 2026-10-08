"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import type { DisruptionSummary, Journey, JourneyDisruptionImpact, JourneyLeg, RoutePatternStop } from "@/lib/api";
import { getRoutePattern } from "@/lib/api";
import DisruptionNotice from "@/components/DisruptionNotice";
import { matchesEntity } from "@/lib/disruptions";
import {
  generateCommuteInstructions,
  instructionForRideMode,
  type CommuteJourneyModel,
} from "@/lib/commuteInstructions";
import {
  applyRideModeAction,
  classifyLocation,
  createRideModeSession,
  currentRideModeLeg,
  evaluateRideModeLocation,
  isUsableLocationQuality,
  journeyPatternIds,
  parseRideModeSession,
  replaceSessionJourney,
  RIDE_MODE_STORAGE_KEY,
  serializeRideModeSession,
  type LocationQuality,
  type RideModeAction,
  type RideModeGuidancePhase,
  type RideModeLocationEvaluation,
  type RideModeMapOverlay,
  type RideModeSession,
  type RideModeStatus,
} from "@/lib/rideMode";
import styles from "@/app/page.module.css";

type LocationMode = "OFF" | "REQUESTING" | "ACTIVE" | "DENIED" | "UNAVAILABLE";

type RideModeProps = {
  selectedJourney: Journey | null;
  offlineSnapshot?: boolean;
  allowPatternFetch?: boolean;
  activeAdvisories?: DisruptionSummary[];
  onRefreshAdvisories?: () => void;
  onReplan?: () => void;
  onRestoreJourney: (journey: Journey) => void;
  onMapStateChange: (overlay: RideModeMapOverlay | null) => void;
};

function distanceLabel(meters: number | null): string {
  if (meters === null) return "Distance unavailable";
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
}

function statusLabel(status: RideModeStatus): string {
  switch (status) {
    case "PLANNED": return "Ready to start";
    case "ACTIVE": return "Ride Mode active";
    case "COMPLETED": return "Journey complete";
    case "CANCELLED": return "Ride Mode cancelled";
  }
}

function phaseLabel(phase: RideModeGuidancePhase | null): string {
  switch (phase) {
    case "APPROACHING": return "APPROACHING — your mapped get-off area is getting closer.";
    case "GET_READY": return "GET READY — your stop is approaching.";
    case "GET_OFF_AREA": return "GET-OFF AREA — your mapped get-off area is nearby.";
    default: return "RIDING — follow the selected route and direction.";
  }
}

function nextLegAfter(journey: Journey, index: number): JourneyLeg | null {
  return journey.legs[index + 1] ?? null;
}

function nextActionText(session: RideModeSession, model: CommuteJourneyModel | null): string {
  const leg = currentRideModeLeg(session);
  if (leg && model) {
    if (leg.type === "RIDE") {
      const sameLeg = model.instructions.filter((instruction) => instruction.legIndex === session.currentLegIndex);
      if (session.currentLegState === "WAITING_TO_BOARD") {
        const ride = sameLeg.find((instruction) => instruction.type === "RIDE");
        if (ride) return ride.primaryAction;
      }
      if (session.currentLegState === "RIDING") {
        const alight = sameLeg.find((instruction) => instruction.type === "ALIGHT");
        if (alight) return alight.primaryAction;
      }
    }
  }
  const nextInstruction = model?.instructions.find((instruction) => instruction.legIndex > session.currentLegIndex && instruction.type !== "ARRIVE");
  if (nextInstruction) return nextInstruction.primaryAction;
  if (!leg) return "Review the journey details.";
  const next = nextLegAfter(session.journey, session.currentLegIndex);
  if (leg.type === "RIDE") {
    if (!next) return "Confirm when you have arrived.";
    if (next.type === "TRANSFER_WALK") return `Get off near ${leg.alighting_stop?.name ?? leg.destination.name}, then walk to ${next.destination.name}.`;
    return `Get off near ${leg.alighting_stop?.name ?? leg.destination.name}, then continue to ${next.destination.name}.`;
  }
  if (leg.type === "WALK_EGRESS") return "Confirm when you have arrived.";
  if (next?.type === "RIDE") return `Then board ${next.route?.code ?? "the mapped route"} toward ${next.pattern?.direction ?? "the displayed direction"}.`;
  return "Continue with the next mapped leg.";
}

function currentActionText(session: RideModeSession, evaluation: RideModeLocationEvaluation | null, instruction: ReturnType<typeof instructionForRideMode>): string {
  const leg = currentRideModeLeg(session);
  if (session.status === "COMPLETED") return "ARRIVED";
  if (!leg) return "REVIEW JOURNEY";
  if (leg.type === "WALK_ACCESS") return session.currentLegState === "WALKING" ? "WALK TO BOARDING AREA" : "START WALK TO BOARDING AREA";
  if (leg.type === "TRANSFER_WALK") return session.currentLegState === "WALKING" ? "WALK TO NEXT BOARDING AREA" : "START NEXT LEG";
  if (leg.type === "WALK_EGRESS") return session.currentLegState === "WALKING" ? "FINAL WALK" : "START FINAL WALK";
  if (session.currentLegState === "WAITING_TO_BOARD") return instruction?.primaryAction.toUpperCase() ?? `BOARD ${leg.route?.code ?? "THE DISPLAYED ROUTE"} TOWARD ${leg.pattern?.direction ?? "THE DISPLAYED DIRECTION"}`;
  if (evaluation?.guidancePhase && evaluation.accepted) return phaseLabel(evaluation.guidancePhase);
  if (evaluation && !evaluation.accepted && ["POOR", "STALE"].includes(evaluation.quality)) return "RIDING — LIVE POSITION IS TOO UNCERTAIN FOR PROXIMITY GUIDANCE";
  const remaining = evaluation?.stopProgress?.stopsRemaining ?? leg.stop_count;
  return remaining === null || remaining === undefined
    ? "RIDING — FOLLOW THE SELECTED ROUTE"
    : `RIDING — ${remaining} MAPPED STOPS BEFORE YOUR GET-OFF`;
}

function trustWarning(leg: JourneyLeg): string | null {
  if (leg.type === "TRANSFER_WALK") {
    return "Walking access is mapped, but this transfer has not been field-verified.";
  }
  if (leg.type === "RIDE" && leg.trust !== "VERIFIED") {
    return "Some route or stop details still need checking; transport trust is unchanged by Ride Mode.";
  }
  return null;
}

export default function RideMode({ selectedJourney, offlineSnapshot = false, allowPatternFetch = true, activeAdvisories = [], onRefreshAdvisories, onReplan, onRestoreJourney, onMapStateChange }: RideModeProps) {
  const [session, setSession] = useState<RideModeSession | null>(null);
  const [hydrated, setHydrated] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [locationMode, setLocationMode] = useState<LocationMode>("OFF");
  const [locationError, setLocationError] = useState<string | null>(null);
  const [evaluation, setEvaluation] = useState<RideModeLocationEvaluation | null>(null);
  const [recenterToken, setRecenterToken] = useState(0);
  const [patternMessage, setPatternMessage] = useState<string | null>(null);
  const [lastQuality, setLastQuality] = useState<LocationQuality>("UNAVAILABLE");
  const [confirmEnd, setConfirmEnd] = useState(false);
  const sessionRef = useRef<RideModeSession | null>(null);
  const watchIdRef = useRef<number | null>(null);
  const previousPhaseRef = useRef<RideModeGuidancePhase | null>(null);
  const hydratedRef = useRef(false);
  const patternLoadKeyRef = useRef<string | null>(null);
  const lastSampleRef = useRef<{ lat: number; lng: number; accuracy: number; timestamp: number } | null>(null);
  const metricsRef = useRef({ locationSamples: 0, samplesAccepted: 0, samplesRejected: 0, progressEvaluations: 0, backendRequestsDuringRide: 0 });
  const instructionJourney = session?.journey ?? selectedJourney;
  const instructionModel = useMemo(() => instructionJourney ? generateCommuteInstructions(instructionJourney) : null, [instructionJourney]);

  useEffect(() => {
    sessionRef.current = session;
  }, [session]);

  function clearLocationWatch() {
    if (watchIdRef.current !== null && typeof navigator !== "undefined" && navigator.geolocation) {
      navigator.geolocation.clearWatch(watchIdRef.current);
      watchIdRef.current = null;
    }
    lastSampleRef.current = null;
  }

  function stopLocationWatch(nextMode: LocationMode = "OFF") {
    clearLocationWatch();
    setLocationMode(nextMode);
  }

  function dispatch(action: RideModeAction) {
    if (!session) return;
    const transition = applyRideModeAction(session, action);
    if (transition.error) {
      setActionError(transition.error);
      return;
    }
    setActionError(null);
    setSession(transition.session);
    setEvaluation(null);
    lastSampleRef.current = null;
    previousPhaseRef.current = null;
    if (transition.session.status !== "ACTIVE") {
      stopLocationWatch();
    }
  }

  useEffect(() => {
    if (hydratedRef.current || typeof window === "undefined") return;
    hydratedRef.current = true;
    const timer = window.setTimeout(() => {
      let stored: string | null = null;
      try {
        stored = window.localStorage.getItem(RIDE_MODE_STORAGE_KEY);
      } catch {
        // Private browsing/storage restrictions must not block manual mode.
      }
      const restored = parseRideModeSession(stored);
      if (restored && restored.status !== "CANCELLED") {
        setSession(restored);
        onRestoreJourney(restored.journey);
      }
      setHydrated(true);
    }, 0);
    return () => window.clearTimeout(timer);
  }, [onRestoreJourney]);

  useEffect(() => {
    if (!hydrated) return;
    try {
      if (session && (session.status === "ACTIVE" || session.status === "PLANNED")) {
        window.localStorage.setItem(RIDE_MODE_STORAGE_KEY, serializeRideModeSession(session));
      } else {
        window.localStorage.removeItem(RIDE_MODE_STORAGE_KEY);
      }
    } catch {
      // The session remains usable in memory when browser storage is blocked.
    }
  }, [hydrated, session]);

  useEffect(() => {
    if (session?.status !== "ACTIVE") {
      clearLocationWatch();
      const timer = window.setTimeout(() => {
        setLocationMode("OFF");
        setEvaluation(null);
        previousPhaseRef.current = null;
      }, 0);
      return () => window.clearTimeout(timer);
    }
    return undefined;
  }, [session?.status]);

  useEffect(() => {
    return () => clearLocationWatch();
  }, []);

  useEffect(() => {
    function stopWhenHidden() {
      if (document.visibilityState !== "hidden") return;
      clearLocationWatch();
      setLocationMode("OFF");
      setEvaluation(null);
    }
    document.addEventListener("visibilitychange", stopWhenHidden);
    return () => document.removeEventListener("visibilitychange", stopWhenHidden);
  }, []);

  useEffect(() => {
    if (locationMode !== "ACTIVE" || !session || session.status !== "ACTIVE" || !lastSampleRef.current) {
      return undefined;
    }
    const timer = window.setInterval(() => {
      const currentSession = sessionRef.current;
      const sample = lastSampleRef.current;
      if (!currentSession || currentSession.status !== "ACTIVE" || !sample) return;
      const nextEvaluation = evaluateRideModeLocation(currentSession, sample, Date.now(), previousPhaseRef.current);
      metricsRef.current.progressEvaluations += 1;
      setLastQuality(nextEvaluation.quality);
      setEvaluation(nextEvaluation);
    }, 5_000);
    return () => window.clearInterval(timer);
  }, [locationMode, session]);

  useEffect(() => {
    if (!session || session.status !== "ACTIVE") {
      onMapStateChange(null);
      return;
    }
    const leg = currentRideModeLeg(session);
    if (!leg) {
      onMapStateChange(null);
      return;
    }
    const usableSample = evaluation && evaluation.accepted && evaluation.sample && isUsableLocationQuality(evaluation.quality)
      ? evaluation.sample
      : null;
    onMapStateChange({
      journeyId: session.journey.id,
      activeLegIndex: session.currentLegIndex,
      currentLocation: usableSample,
      accuracyMeters: usableSample?.accuracy ?? null,
      activeGeometry: leg.geometry,
      boardingLocation: leg.type === "RIDE" ? leg.boarding_stop?.location ?? leg.origin.location : leg.origin.location,
      alightingLocation: leg.type === "RIDE" ? leg.alighting_stop?.location ?? leg.destination.location : leg.destination.location,
      recenterToken,
    });
  }, [evaluation, onMapStateChange, recenterToken, session]);

  useEffect(() => {
    if (!allowPatternFetch || !session || (session.status !== "ACTIVE" && session.status !== "PLANNED")) return;
    const ids = journeyPatternIds(session.journey);
    const missing = ids.filter((id) => !session.journey.legs.some((leg) => leg.pattern?.id === id && (leg.pattern.stops?.length ?? 0) > 0));
    const key = `${session.sessionId}:${missing.join(",")}`;
    if (missing.length === 0 || patternLoadKeyRef.current === key) return;
    patternLoadKeyRef.current = key;
    let cancelled = false;
    (async () => {
      const loaded: Record<string, RoutePatternStop[]> = {};
      for (const id of missing) {
        try {
          const pattern = await getRoutePattern(id);
          if (pattern.stops?.length) loaded[String(id)] = pattern.stops;
        } catch {
          // Sequences already present in the journey remain usable; detail
          // loading is an enhancement and must not block manual Ride Mode.
        }
      }
      if (cancelled || Object.keys(loaded).length === 0) {
        if (!cancelled && missing.length > 0) setPatternMessage("Mapped stop sequence details are unavailable; the planned boarding and get-off stops remain available.");
        return;
      }
      setPatternMessage(null);
      setSession((current) => current ? replaceSessionJourney(current, {
        ...current.journey,
        legs: current.journey.legs.map((leg) => {
          const patternId = leg.pattern?.id;
          const stops = patternId === undefined ? undefined : loaded[String(patternId)];
          return leg.pattern && stops ? { ...leg, pattern: { ...leg.pattern, stops } } : leg;
        }),
      }) : current);
    })();
    return () => { cancelled = true; };
  }, [allowPatternFetch, session]);

  function startSelectedJourney() {
    if (!selectedJourney) {
      setActionError("Choose a journey before starting Ride Mode.");
      return;
    }
    const planned = createRideModeSession(selectedJourney);
    const transition = applyRideModeAction(planned, "ACTIVATE");
    setActionError(transition.error);
    if (!transition.error) {
      setSession(transition.session);
      setEvaluation(null);
      lastSampleRef.current = null;
      previousPhaseRef.current = null;
      setLocationError(null);
      setPatternMessage(null);
    }
  }

  function switchToSelectedJourney() {
    if (!selectedJourney) return;
    stopLocationWatch();
    const planned = createRideModeSession(selectedJourney);
    const transition = applyRideModeAction(planned, "ACTIVATE");
    if (transition.error) {
      setActionError(transition.error);
      return;
    }
    setSession(transition.session);
    setEvaluation(null);
    lastSampleRef.current = null;
    previousPhaseRef.current = null;
    setActionError(null);
  }

  function enableLocation() {
    if (!session || session.status !== "ACTIVE") return;
    if (typeof navigator === "undefined" || !navigator.geolocation) {
      setLocationMode("UNAVAILABLE");
      setLocationError("This browser does not provide foreground location. Manual Ride Mode remains available.");
      return;
    }
    stopLocationWatch("REQUESTING");
    setLocationError(null);
    const watchId = navigator.geolocation.watchPosition(
      (position) => {
        const currentSession = sessionRef.current;
        if (!currentSession || currentSession.status !== "ACTIVE") return;
        const sample = {
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          accuracy: position.coords.accuracy,
          timestamp: position.timestamp,
        };
        lastSampleRef.current = sample;
        metricsRef.current.locationSamples += 1;
        const nextEvaluation = evaluateRideModeLocation(currentSession, sample, Date.now(), previousPhaseRef.current);
        metricsRef.current.progressEvaluations += 1;
        if (nextEvaluation.accepted) metricsRef.current.samplesAccepted += 1;
        else metricsRef.current.samplesRejected += 1;
        if (nextEvaluation.guidancePhase) previousPhaseRef.current = nextEvaluation.guidancePhase;
        setLastQuality(classifyLocation(sample, Date.now()));
        setEvaluation(nextEvaluation);
        setLocationMode("ACTIVE");
      },
      (error) => {
        if (error.code === error.PERMISSION_DENIED) {
          stopLocationWatch("DENIED");
          setLocationError("Location permission was denied. Manual Ride Mode remains available.");
          return;
        }
        setEvaluation(null);
        setLocationMode("UNAVAILABLE");
        setLocationError("Live position is temporarily unavailable. Your journey and manual controls are preserved.");
      },
      { enableHighAccuracy: true, maximumAge: 10_000, timeout: 15_000 },
    );
    watchIdRef.current = watchId;
  }

  const currentLeg = session ? currentRideModeLeg(session) : null;
  const journeyChanged = Boolean(session && selectedJourney && session.journey.id !== selectedJourney.id);
  const currentInstruction = session && currentLeg && instructionModel
    ? instructionForRideMode(instructionModel, session.currentLegIndex, currentLeg.type, session.currentLegState)
    : null;
  const currentText = session ? currentActionText(session, evaluation, currentInstruction) : "START RIDE MODE FOR A SELECTED JOURNEY";
  const currentTrustWarning = currentLeg ? trustWarning(currentLeg) : null;
  const previousLeg = session && session.currentLegIndex > 0 ? session.journey.legs[session.currentLegIndex - 1] : null;
  const previousTransferWarning = previousLeg?.type === "TRANSFER_WALK" ? trustWarning(previousLeg) : null;
  const routeCue = currentLeg?.type === "RIDE"
    ? `${currentInstruction?.routeCode ?? currentLeg.route?.code ?? "Mapped route"}${currentInstruction?.direction ? ` · ${currentInstruction.direction}` : ""}`
    : null;
  const currentAdvisories = currentLeg
    ? activeAdvisories.filter((advisory) => matchesEntity(
      advisory,
      currentLeg.route?.id,
      currentLeg.boarding_stop?.id ?? currentLeg.alighting_stop?.id ?? (currentLeg.origin.type === "STOP" ? currentLeg.origin.id : undefined),
      currentLeg.origin.type === "PLACE" ? currentLeg.origin.id : currentLeg.destination.type === "PLACE" ? currentLeg.destination.id : undefined,
    ))
    : [];
  const locationSummary = useMemo(() => {
    if (!session || session.status !== "ACTIVE") return null;
    if (locationMode === "DENIED") return "Manual mode · location permission denied";
    if (locationMode === "UNAVAILABLE") return "Manual mode · live position unavailable";
    if (locationMode === "REQUESTING") return "Requesting foreground location…";
    if (locationMode === "OFF") return "Manual mode · location not enabled";
    if (lastQuality === "POOR") return "Live position is too imprecise for proximity guidance";
    if (lastQuality === "STALE") return "Live position is stale; waiting for a fresh fix";
    if (evaluation?.corridorState === "POSSIBLY_OFF_ROUTE") return "Your device may be away from the expected corridor; confirm manually.";
    return `Foreground location ${lastQuality.toLowerCase()} · used only on this device`;
  }, [evaluation?.corridorState, lastQuality, locationMode, session]);

  if (!session && !selectedJourney) {
    return (
      <section className={styles.rideModeSection} id="ride-mode" aria-labelledby="ride-mode-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Foreground trip guidance</p><h2 id="ride-mode-heading">Ride Mode</h2></div></div>
        <p className={styles.sectionCopy}>Select a journey above to start a local, manual trip guide. Foreground location is optional and is never saved as a movement trail.</p>
      </section>
    );
  }

  return (
    <section className={styles.rideModeSection} id="ride-mode" aria-labelledby="ride-mode-heading">
      <div className={styles.sectionHeading}>
        <div><p className={styles.eyebrow}>Foreground trip guidance</p><h2 id="ride-mode-heading">Ride Mode</h2></div>
        {session && <span className={styles.fixtureBadge}>{statusLabel(session.status)}</span>}
      </div>
      {!session && selectedJourney && (
        <div className={styles.rideStartCard}>
          <p className={styles.sectionCopy}>{offlineSnapshot ? "This is a saved journey snapshot. Manual Ride Mode can start without the journey API; live replanning needs connectivity." : "Already on this planned journey? Ride Mode keeps progress on this device, supports manual confirmations, and can optionally use foreground location while this page is open."}</p>
          <button type="button" className={styles.primaryButton} onClick={startSelectedJourney}>Start Ride Mode</button>
        </div>
      )}
      {session && (
        <div className={styles.rideModeCard}>
          {journeyChanged && session.status === "ACTIVE" && (
            <div className={styles.rideNotice} role="alert">
              <strong>A different journey is selected.</strong>
              <span>Ride Mode stays on the active journey until you intentionally switch.</span>
              <button type="button" className={styles.secondaryButton} onClick={switchToSelectedJourney}>Switch Ride Mode journey</button>
            </div>
          )}
          <div className={styles.rideActionCard}>
            <p className={styles.cardKicker}>CURRENT ACTION</p>
            <h3 aria-live="polite">{currentText}</h3>
            {session.journey.disruptions?.map((impact: JourneyDisruptionImpact) => <DisruptionNotice key={impact.advisory.stable_id} impact={impact} staleSnapshot={offlineSnapshot} />)}
            {!offlineSnapshot && currentAdvisories.map((advisory) => <DisruptionNotice key={advisory.stable_id} advisory={advisory} />)}
            {!offlineSnapshot && onReplan && session.journey.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") && <button type="button" className={styles.secondaryButton} onClick={onReplan}>Find another way</button>}
            {offlineSnapshot && session.journey.disruptions?.length === 0 && <p className={styles.snapshotNote}>Current disruption status is unavailable offline. This saved journey remains usable as a manual guide.</p>}
            {currentLeg?.type === "RIDE" && routeCue && <p className={styles.rideRouteCue}>{routeCue}</p>}
            {currentLeg?.type === "RIDE" && session.currentLegState === "WAITING_TO_BOARD" && (
              <div className={styles.rideGuidance}>
                {currentInstruction?.secondaryDetail && <p><strong>{currentInstruction.secondaryDetail}</strong></p>}
                {currentInstruction?.uncertainty && <p>{currentInstruction.uncertainty}</p>}
                {currentInstruction?.driverInstruction && <p><strong>{currentInstruction.driverInstruction}</strong></p>}
              </div>
            )}
            {currentLeg?.type === "RIDE" && session.currentLegState === "RIDING" && (
              <div className={styles.rideGuidance}>
                {currentInstruction?.secondaryDetail && <p>{currentInstruction.secondaryDetail}</p>}
                {currentInstruction?.uncertainty && <p>{currentInstruction.uncertainty}</p>}
                {evaluation?.distanceToTargetMeters !== null && evaluation?.distanceToTargetMeters !== undefined && (
                  <p><strong>{distanceLabel(evaluation.distanceToTargetMeters)}</strong> remaining {evaluation.distanceBasis === "ALONG_ROUTE" ? "along the selected route" : "approximately by map distance"}.</p>
                )}
                {evaluation?.stopProgress?.stopsRemaining !== null && evaluation?.stopProgress?.stopsRemaining !== undefined && <p>{evaluation.stopProgress.stopsRemaining} mapped stop(s) remain in the selected sequence.</p>}
                {evaluation?.corridorState === "POSSIBLY_OFF_ROUTE" && <p className={styles.warning}>The device may be away from the expected route corridor. This does not identify the vehicle or change direction.</p>}
              </div>
            )}
            {currentLeg && ["WALK_ACCESS", "TRANSFER_WALK", "WALK_EGRESS"].includes(currentLeg.type) && (
              <div className={styles.rideGuidance}>
                {currentInstruction?.secondaryDetail && <p>{currentInstruction.secondaryDetail}</p>}
                {currentInstruction?.uncertainty && <p>{currentInstruction.uncertainty}</p>}
                {evaluation?.distanceToTargetMeters !== null && evaluation?.distanceToTargetMeters !== undefined && <p><strong>{distanceLabel(evaluation.distanceToTargetMeters)}</strong> approximate device-to-target distance.</p>}
              </div>
            )}
            {currentTrustWarning && <p className={styles.warning}>{currentTrustWarning}</p>}
            {previousTransferWarning && currentLeg?.type === "RIDE" && <p className={styles.warning}>{previousTransferWarning}</p>}
            {currentLeg?.type !== "RIDE" && currentLeg?.type !== undefined && currentLeg.distance_type === "PEDESTRIAN_NETWORK" && <p className={styles.osmNote}>Walking route is based on OpenStreetMap; it does not verify transport access or ground conditions.</p>}
            {currentLeg?.type !== "RIDE" && currentLeg?.access_classification && currentLeg.access_classification !== "NETWORK_ROUTE" && <p className={styles.warning}>Walking access could not be fully confirmed from the available map data.</p>}

            <div className={styles.rideActions}>
              {session.status === "PLANNED" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("ACTIVATE")}>Start Ride Mode</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "WALK_ACCESS" && session.currentLegState === "NOT_STARTED" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("START_WALK")}>Start walk</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "TRANSFER_WALK" && session.currentLegState === "NOT_STARTED" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("START_NEXT_LEG")}>Start next leg</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "WALK_EGRESS" && session.currentLegState === "NOT_STARTED" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("START_NEXT_LEG")}>Start final walk</button>}
              {session.status === "ACTIVE" && (currentLeg?.type === "WALK_ACCESS" || currentLeg?.type === "TRANSFER_WALK") && session.currentLegState === "WALKING" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("AT_BOARDING_AREA")}>I&apos;m at the boarding area</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "RIDE" && session.currentLegState === "WAITING_TO_BOARD" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("BOARDED")}>I boarded</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "RIDE" && session.currentLegState === "RIDING" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("GOT_OFF")}>I got off</button>}
              {session.status === "ACTIVE" && currentLeg?.type === "WALK_EGRESS" && session.currentLegState === "WALKING" && <button type="button" className={styles.primaryButton} onClick={() => dispatch("ARRIVED")}>I&apos;ve arrived</button>}
              {session.status === "ACTIVE" && !confirmEnd && <button type="button" className={styles.secondaryButton} onClick={() => setConfirmEnd(true)}>End Ride Mode</button>}
              {session.status === "ACTIVE" && confirmEnd && <div className={styles.rideNotice} role="group" aria-label="Confirm ending Ride Mode">
                <strong>End this guide and clear its saved progress from this device?</strong>
                <div className={styles.rideActions}>
                  <button type="button" className={styles.secondaryButton} onClick={() => { setConfirmEnd(false); dispatch("CANCEL"); }}>End and clear progress</button>
                  <button type="button" className={styles.secondaryButton} onClick={() => setConfirmEnd(false)}>Keep Ride Mode open</button>
                </div>
              </div>}
            </div>
            {actionError && <p className={styles.error} role="alert">{actionError}</p>}
          </div>

          {session.status === "ACTIVE" && (
            <div className={styles.rideStatusGrid}>
              <div><p className={styles.cardKicker}>NEXT</p><p>{nextActionText(session, instructionModel)}</p></div>
              <div><p className={styles.cardKicker}>LOCATION</p><p>{locationSummary}</p></div>
              {locationMode === "OFF" && <button type="button" className={styles.secondaryButton} onClick={enableLocation}>Enable foreground location</button>}
              {locationMode === "DENIED" && <button type="button" className={styles.secondaryButton} onClick={enableLocation}>Try location again</button>}
              {locationMode === "UNAVAILABLE" && <button type="button" className={styles.secondaryButton} onClick={enableLocation}>Try live position again</button>}
              {evaluation?.accepted && <button type="button" className={styles.secondaryButton} onClick={() => setRecenterToken((token) => token + 1)}>Center map on me</button>}
              {onRefreshAdvisories && !offlineSnapshot && <button type="button" className={styles.secondaryButton} onClick={onRefreshAdvisories}>Check current advisories</button>}
            </div>
          )}
          {locationError && <p className={styles.warning}>{locationError}</p>}
          {patternMessage && <p className={styles.warning}>{patternMessage}</p>}
          {session.status === "COMPLETED" && <p className={styles.osmNote}>Ride Mode is complete. The session has been removed from local persistence and live location has stopped.</p>}
          {session.status === "CANCELLED" && <button type="button" className={styles.primaryButton} onClick={startSelectedJourney}>Start selected journey again</button>}
        </div>
      )}
    </section>
  );
}
