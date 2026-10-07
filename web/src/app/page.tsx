"use client";

import dynamic from "next/dynamic";
import { useEffect, useState } from "react";
import type { CanIRideResponse, DisruptionSummary, Journey, JourneyResponse, PedestrianAccessLeg, Place, PulseResponse, Route, RoutePattern, Stop } from "@/lib/api";
import { canIRide, getPulse, getRoutePattern, listPlaces, listRoutes, listStops, planJourney, replanJourney, searchPlaces } from "@/lib/api";
import BiyahePulse from "@/components/BiyahePulse";
import CommuteCard from "@/components/CommuteCard";
import CommuterReportForm from "@/components/CommuterReportForm";
import DisruptionNotice from "@/components/DisruptionNotice";
import ReplanPanel from "@/components/ReplanPanel";
import RideMode from "@/components/RideMode";
import type { CommuteInstruction } from "@/lib/commuteInstructions";
import type { RideModeMapOverlay } from "@/lib/rideMode";
import {
  loadSavedJourneys,
  persistSavedJourneys,
  removeSavedJourney,
  upsertSavedJourney,
  type SavedJourney,
} from "@/lib/savedJourneys";
import styles from "./page.module.css";

const MapView = dynamic(() => import("@/components/MapView"), {
  ssr: false,
  loading: () => <div className="map-frame map-loading">Loading map…</div>,
});

type SearchFieldProps = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: Place[];
  onPick: (place: Place) => void;
};

function SearchField({ label, value, onChange, options, onPick }: SearchFieldProps) {
  return (
    <div className={styles.searchField}>
      <label htmlFor={label.toLowerCase()}>{label}</label>
      <input
        id={label.toLowerCase()}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder="Search a place or local name"
        autoComplete="off"
        aria-autocomplete="list"
      />
      {value.length > 1 && options.length > 0 && (
        <div className={styles.suggestions} role="listbox">
          {options.map((place) => (
            <button type="button" key={place.id} onClick={() => onPick(place)} role="option" aria-selected={false}>
              <span>{place.name}</span>
              <small>{place.aliases?.[0]?.alias ?? place.category}</small>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function formatDistance(distance: number | null) {
  if (distance === null) {
    return "Distance unavailable";
  }
  return `Approx. ${Math.round(distance)} m`;
}

function formatAccessLeg(leg: PedestrianAccessLeg) {
  if (leg.distance_type === "PEDESTRIAN_NETWORK") {
    return `${formatDistance(leg.distance_meters)} pedestrian path`;
  }
  if (leg.access_classification === "GRAPH_COVERAGE_GAP") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · walking access could not be confirmed`;
  }
  if (leg.access_classification === "NO_WALKABLE_ACCESS") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · mapped access is unresolved`;
  }
  return `${formatDistance(leg.distance_meters)} straight-line estimate · no route shown`;
}

function trustLabel(state: string | null | undefined) {
  switch (state) {
    case "VERIFIED":
      return "Verified route details";
    case "MIXED":
      return "Some details need checking";
    case "UNVERIFIED":
      return "Route details not fully verified";
    default:
      return "Route details unavailable";
  }
}

function JourneyCard({ journey, recommended, onSelect, onInstructionFocus }: {
  journey: Journey;
  recommended: boolean;
  onSelect: () => void;
  onInstructionFocus: (instruction: CommuteInstruction) => void;
}) {
  return (
    <div className={`${styles.journeyCard} ${recommended ? styles.recommended : ""}`}>
      <CommuteCard
        journey={journey}
        variant="compact"
        recommended={recommended}
        onSelect={onSelect}
        onInstructionFocus={onInstructionFocus}
      />
    </div>
  );
}

function CanIRideResultCard({ result }: { result: CanIRideResponse["data"] }) {
  const option = result.option ?? result.opposite_direction;
  const tone = result.status === "YES"
    ? styles.decisionYes
    : result.status === "YES_BUT_AFFECTED"
      ? styles.decisionAffected
      : result.status === "TEMPORARILY_UNAVAILABLE"
        ? styles.decisionNo
        : result.status === "UNCERTAIN_DUE_TO_REPORT"
          ? styles.decisionUncertain
    : result.status === "DIRECTION_DEPENDENT"
      ? styles.decisionDirection
      : result.status === "NO"
        ? styles.decisionNo
        : styles.decisionUnknown;

  return (
    <div className={styles.decisionResult + " " + tone} role="status">
      <div className={styles.decisionHeader}>
        <div>
          <p className={styles.cardKicker}>CAN I RIDE THIS?</p>
          <h3>
            {result.status === "YES"
              ? "YES — ride " + (result.route?.code ?? "this route")
              : result.status === "YES_BUT_AFFECTED"
                ? "YES, BUT CHECK THE ADVISORY"
                : result.status === "TEMPORARILY_UNAVAILABLE"
                  ? "TEMPORARILY UNAVAILABLE"
                  : result.status === "UNCERTAIN_DUE_TO_REPORT"
                    ? "UNCERTAIN — REPORT UNDER REVIEW"
              : result.status === "DIRECTION_DEPENDENT"
                ? "NOT THIS DIRECTION"
                : result.status === "NO"
                  ? "NOT A KNOWN RIDE"
                  : "NOT ENOUGH INFORMATION YET"}
          </h3>
        </div>
        <span className={styles.trustBadge}>{trustLabel(result.trust)}</span>
      </div>
      <p>{result.message}</p>
      {option && (
        <div className={styles.decisionDetails}>
          <p><strong>Direction:</strong> {option.pattern.direction}</p>
          <p><strong>Board near:</strong> {option.boarding_stop.name}</p>
          <p>{option.boarding_instruction}</p>
          <p><strong>{option.driver_instruction}</strong></p>
          <p><strong>{option.get_off_guidance}</strong></p>
          {option.pedestrian_access && <small>Access: {formatAccessLeg(option.pedestrian_access.to_boarding)} · Egress: {formatAccessLeg(option.pedestrian_access.from_alighting)}</small>}
          <small>{option.stop_count} stops · route details {option.trust.toLowerCase()}</small>
        </div>
      )}
      {result.warnings.map((warning) => <p className={styles.warning} key={warning}>{warning}</p>)}
      {result.disruptions?.map((impact) => <DisruptionNotice key={impact.advisory.stable_id} impact={impact} />)}
    </div>
  );
}

export default function Home() {
  const [places, setPlaces] = useState<Place[]>([]);
  const [stops, setStops] = useState<Stop[]>([]);
  const [routes, setRoutes] = useState<Route[]>([]);
  const [origin, setOrigin] = useState<Place | null>(null);
  const [destination, setDestination] = useState<Place | null>(null);
  const [originText, setOriginText] = useState("");
  const [destinationText, setDestinationText] = useState("");
  const [originOptions, setOriginOptions] = useState<Place[]>([]);
  const [destinationOptions, setDestinationOptions] = useState<Place[]>([]);
  const [journeys, setJourneys] = useState<Journey[]>([]);
  const [selectedJourney, setSelectedJourney] = useState<Journey | null>(null);
  const [selectedPattern, setSelectedPattern] = useState<RoutePattern | null>(null);
  const [selectedStop, setSelectedStop] = useState<Stop | null>(null);
  const [status, setStatus] = useState("Choose two places to find a supported journey.");
  const [noRouteMessage, setNoRouteMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loadingNetwork, setLoadingNetwork] = useState(true);
  const [loadingJourney, setLoadingJourney] = useState(false);
  const [rideRouteCode, setRideRouteCode] = useState("");
  const [canRideResult, setCanRideResult] = useState<CanIRideResponse["data"] | null>(null);
  const [loadingCanRide, setLoadingCanRide] = useState(false);
  const [rideMapState, setRideMapState] = useState<RideModeMapOverlay | null>(null);
  const [instructionFocus, setInstructionFocus] = useState<CommuteInstruction | null>(null);
  const [savedJourneys, setSavedJourneys] = useState<SavedJourney[]>([]);
  const [offlineSavedJourneyId, setOfflineSavedJourneyId] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const [activeAdvisories, setActiveAdvisories] = useState<DisruptionSummary[]>([]);
  const [selectedDisruption, setSelectedDisruption] = useState<DisruptionSummary | null>(null);
  const [pulse, setPulse] = useState<PulseResponse["data"] | null>(null);
  const [pulseFilter, setPulseFilter] = useState<"ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR">("ALL");
  const [loadingPulse, setLoadingPulse] = useState(false);
  const [replanResult, setReplanResult] = useState<JourneyResponse | null>(null);
  const [replanError, setReplanError] = useState<string | null>(null);
  const [loadingReplan, setLoadingReplan] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all([listPlaces(controller.signal), listStops(controller.signal), listRoutes(controller.signal)])
      .then(([loadedPlaces, loadedStops, loadedRoutes]) => {
        setPlaces(loadedPlaces);
        setStops(loadedStops);
        setRoutes(loadedRoutes);
        setStatus("Choose two places to find a supported journey.");
      })
      .catch(() => setError("The transport API is unavailable. Try again when the API is running."))
      .finally(() => setLoadingNetwork(false));

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    getPulse({ filter: pulseFilter }, controller.signal).then((loaded) => {
      setPulse(loaded);
      setActiveAdvisories(loaded.items);
    }).catch(() => { setPulse(null); setActiveAdvisories([]); });
    return () => controller.abort();
  }, [pulseFilter]);

  async function refreshAdvisories() {
    setLoadingPulse(true);
    try {
      const loaded = await getPulse({ filter: pulseFilter });
      setPulse(loaded);
      setActiveAdvisories(loaded.items);
    } catch {
      setError("Current advisories could not be refreshed. Existing journey guidance remains available.");
    } finally {
      setLoadingPulse(false);
    }
  }

  async function requestReplan(journey: Journey | null = selectedJourney) {
    if (!journey) return;
    if (offlineSavedJourneyId === journey.id) {
      setReplanError("New route search requires connectivity. The saved snapshot remains available offline.");
      return;
    }
    setLoadingReplan(true);
    setReplanError(null);
    try {
      const response = await replanJourney(journey.origin.id, journey.destination.id);
      setReplanResult(response);
    } catch {
      setReplanError("A current alternative could not be loaded. Your existing journey remains selected.");
      setReplanResult(null);
    } finally {
      setLoadingReplan(false);
    }
  }

  function chooseReplannedJourney(journey: Journey) {
    selectJourney(journey);
    setJourneys((current) => [journey, ...current.filter((candidate) => candidate.id !== journey.id)]);
    setReplanResult(null);
    setStatus("Alternative selected. Confirm any Ride Mode switch explicitly.");
  }

  useEffect(() => {
    const timer = window.setTimeout(() => {
      try {
        setSavedJourneys(loadSavedJourneys(window.localStorage));
      } catch {
        setSavedJourneys([]);
      }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (originText.length < 2 || origin?.name === originText) {
      return;
    }
    const controller = new AbortController();
    searchPlaces(originText, controller.signal).then(setOriginOptions).catch(() => setOriginOptions([]));
    return () => controller.abort();
  }, [originText, origin]);

  useEffect(() => {
    if (destinationText.length < 2 || destination?.name === destinationText) {
      return;
    }
    const controller = new AbortController();
    searchPlaces(destinationText, controller.signal).then(setDestinationOptions).catch(() => setDestinationOptions([]));
    return () => controller.abort();
  }, [destinationText, destination]);

  function changeOrigin(value: string) {
    setOrigin(null);
    setOriginText(value);
    setOriginOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
  }

  function changeDestination(value: string) {
    setDestination(null);
    setDestinationText(value);
    setDestinationOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
  }

  function chooseOrigin(place: Place) {
    setOrigin(place);
    setOriginText(place.name);
    setOriginOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
  }

  function chooseDestination(place: Place) {
    setDestination(place);
    setDestinationText(place.name);
    setDestinationOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
  }

  async function findRoute() {
    if (!origin || !destination) {
      setError("Select a starting place and destination from the suggestions first.");
      return;
    }
    if (origin.id === destination.id) {
      setError("Choose a different destination place.");
      return;
    }

    setLoadingJourney(true);
    setError(null);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setReplanResult(null);
    try {
      const response = await planJourney(origin.id, destination.id);
      setJourneys(response.data);
      setSelectedJourney(response.data[0] ?? null);
      setNoRouteMessage(response.meta.no_route?.message ?? null);
      setStatus(response.meta.no_route?.message ?? `${response.data.length} supported journey${response.data.length === 1 ? "" : "s"} found.`);
    } catch {
      setError("Journey search is unavailable right now. Check the API connection and try again.");
      setStatus("No journey search result yet.");
    } finally {
      setLoadingJourney(false);
    }
  }

  async function showPattern(pattern: RoutePattern) {
    setSelectedJourney(null);
    setSelectedPattern(pattern);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    if (pattern.stops) {
      return;
    }

    try {
      setSelectedPattern(await getRoutePattern(pattern.id));
    } catch {
      setError("This route pattern could not be loaded from the API.");
    }
  }

  async function checkCanIRide() {
    if (!origin || !destination) {
      setError("Select From and To places before checking a route.");
      return;
    }
    if (!rideRouteCode) {
      setError("Choose the route code you can see.");
      return;
    }

    setLoadingCanRide(true);
    setError(null);
    try {
      const response = await canIRide(rideRouteCode, origin.id, destination.id);
      setCanRideResult(response.data);
    } catch {
      setError("The Can I Ride check is unavailable right now. Check the API connection and try again.");
      setCanRideResult(null);
    } finally {
      setLoadingCanRide(false);
    }
  }

  function selectJourney(journey: Journey, offlineSnapshot = false) {
    setSelectedPattern(null);
    setSelectedJourney(journey);
    setOfflineSavedJourneyId(offlineSnapshot ? journey.id : null);
    setInstructionFocus(null);
    setSaveMessage(null);
  }

  function saveCurrentJourney() {
    if (!selectedJourney) return;
    try {
      const next = upsertSavedJourney(savedJourneys, selectedJourney);
      persistSavedJourneys(window.localStorage, next);
      setSavedJourneys(next);
      setSaveMessage("Journey saved on this device.");
    } catch {
      setSaveMessage("This journey could not be saved; browser storage may be full.");
    }
  }

  function deleteSavedJourney(id: string) {
    const next = removeSavedJourney(savedJourneys, id);
    try {
      persistSavedJourneys(window.localStorage, next);
      setSavedJourneys(next);
      if (offlineSavedJourneyId === id) setOfflineSavedJourneyId(null);
      setSaveMessage("Saved journey removed.");
    } catch {
      setSaveMessage("The saved journey could not be removed from browser storage.");
    }
  }

  function openSavedJourney(saved: SavedJourney) {
    selectJourney(saved.journey, true);
    setStatus("Opened an offline journey snapshot. It may be out of date.");
  }

  async function refreshSavedJourney(saved: SavedJourney) {
    setLoadingJourney(true);
    setError(null);
    try {
      const response = await planJourney(saved.journey.origin.id, saved.journey.destination.id);
      setJourneys(response.data);
      setStatus(response.meta.no_route?.message ?? `${response.data.length} current journey${response.data.length === 1 ? "" : "s"} found.`);
      if (response.data[0]) selectJourney(response.data[0]);
    } catch {
      setError("A current journey could not be loaded. The saved snapshot remains available.");
    } finally {
      setLoadingJourney(false);
    }
  }

  return (
    <main className={styles.page}>
      <section className={styles.hero}>
        <div className={styles.brandRow}>
          <div className={styles.brandMark}>B</div>
          <div>
            <p className={styles.eyebrow}>Cebu commuter guidance</p>
            <h1>BIYAHE</h1>
          </div>
        </div>
        <p className={styles.intro}>Make the next ride feel less uncertain.</p>
        <div className={styles.searchCard}>
          <SearchField label="From" value={originText} onChange={changeOrigin} options={originOptions} onPick={chooseOrigin} />
          <SearchField label="To" value={destinationText} onChange={changeDestination} options={destinationOptions} onPick={chooseDestination} />
          <button className={styles.primaryButton} type="button" onClick={findRoute} disabled={loadingJourney || loadingNetwork}>
            {loadingJourney ? "Finding…" : "Find route"}
          </button>
          <p className={styles.status} role="status">{status}</p>
          {error && <p className={styles.error} role="alert">{error}</p>}
        </div>
      </section>

      <BiyahePulse
        pulse={pulse}
        loading={loadingPulse}
        selectedJourney={selectedJourney}
        onRefresh={() => { void refreshAdvisories(); }}
        onFilter={setPulseFilter}
        onAdvisorySelect={setSelectedDisruption}
      />

      <section className={styles.resultsSection} aria-labelledby="results-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>What to do next</p><h2 id="results-heading">Journey results</h2></div>
          {selectedJourney && <span className={styles.fixtureBadge}>{trustLabel(selectedJourney.trust.state)}</span>}
        </div>
        {journeys.length === 0 ? (
          <p className={styles.emptyState}>{loadingJourney ? "Looking for direct, one- or two-transfer rides…" : noRouteMessage ?? "Your supported journeys will appear here."}</p>
        ) : (
          <div className={styles.journeyGrid}>
            {journeys.map((journey, index) => <JourneyCard
              key={journey.id}
              journey={journey}
              recommended={index === 0}
              onSelect={() => selectJourney(journey)}
              onInstructionFocus={(instruction) => { selectJourney(journey); setInstructionFocus(instruction); }}
            />)}
          </div>
        )}
      </section>

      {selectedJourney && (
        <section className={styles.detailSection} aria-labelledby="instructions-heading">
          <div className={styles.sectionHeading}>
            <div><p className={styles.eyebrow}>One shared instruction layer</p><h2 id="instructions-heading">Your commute card</h2></div>
            {offlineSavedJourneyId === selectedJourney.id && <span className={styles.fixtureBadge}>OFFLINE SNAPSHOT</span>}
          </div>
          <p className={styles.sectionCopy}>Follow the steps in order. Route confidence, boarding precision, transfer uncertainty, and walking estimates stay visible.</p>
          <CommuteCard
            journey={selectedJourney}
            variant="detail"
            offlineSnapshot={offlineSavedJourneyId === selectedJourney.id}
            isSaved={savedJourneys.some((saved) => saved.id === selectedJourney.id)}
            onInstructionFocus={setInstructionFocus}
            onSave={saveCurrentJourney}
            onReplan={selectedJourney.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") ? () => { void requestReplan(selectedJourney); } : undefined}
          />
          {saveMessage && <p className={styles.status} role="status">{saveMessage}</p>}
          {selectedJourney.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") && (
            <ReplanPanel
              currentJourney={selectedJourney}
              result={replanResult}
              loading={loadingReplan}
              error={replanError}
              onRequest={() => { void requestReplan(selectedJourney); }}
              onChoose={chooseReplannedJourney}
            />
          )}
        </section>
      )}

      {savedJourneys.length > 0 && (
        <section className={styles.savedSection} aria-labelledby="saved-heading">
          <div className={styles.sectionHeading}>
            <div><p className={styles.eyebrow}>On this device</p><h2 id="saved-heading">Saved journeys</h2></div>
            <span className={styles.fixtureBadge}>{savedJourneys.length}/20</span>
          </div>
          <p className={styles.sectionCopy}>Saved journeys are local snapshots. They can reopen without the journey API, but may be out of date. New route search requires connectivity.</p>
          <div className={styles.savedGrid}>
            {savedJourneys.map((saved) => (
              <CommuteCard
                key={saved.id}
                journey={saved.journey}
                variant="saved"
                offlineSnapshot
                savedAt={saved.savedAt}
                isSaved
                onInstructionFocus={(instruction) => { openSavedJourney(saved); setInstructionFocus(instruction); }}
                onOpen={() => openSavedJourney(saved)}
                onRefresh={() => { void refreshSavedJourney(saved); }}
                onRemove={() => deleteSavedJourney(saved.id)}
                onReplan={saved.journey.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") ? () => { void requestReplan(saved.journey); } : undefined}
              />
            ))}
          </div>
        </section>
      )}

      <RideMode
        selectedJourney={selectedJourney}
        offlineSnapshot={Boolean(selectedJourney && offlineSavedJourneyId === selectedJourney.id)}
        allowPatternFetch={!Boolean(selectedJourney && offlineSavedJourneyId === selectedJourney.id)}
        activeAdvisories={activeAdvisories}
        onRefreshAdvisories={() => { void refreshAdvisories(); }}
        onReplan={selectedJourney && selectedJourney.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") ? () => { void requestReplan(selectedJourney); } : undefined}
        onRestoreJourney={(journey) => {
          setSelectedJourney(journey);
          try {
            const restoredIsSaved = loadSavedJourneys(window.localStorage).some((saved) => saved.id === journey.id);
            setOfflineSavedJourneyId(restoredIsSaved ? journey.id : null);
          } catch {
            setOfflineSavedJourneyId(null);
          }
        }}
        onMapStateChange={setRideMapState}
      />

      <section className={styles.decisionSection} aria-labelledby="can-ride-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>Standing at a stop?</p><h2 id="can-ride-heading">Can I ride this?</h2></div>
        </div>
        <div className={styles.decisionCard}>
          <p className={styles.sectionCopy}>Choose the route code you can see. Biyahe checks its known direction and stop sequence between your selected From and To places.</p>
          <div className={styles.decisionForm}>
            <label htmlFor="ride-route">Jeep / route</label>
            <select id="ride-route" value={rideRouteCode} onChange={(event) => { setRideRouteCode(event.target.value); setCanRideResult(null); }}>
              <option value="">Choose a route</option>
              {routes.map((route) => <option key={route.id} value={route.code}>{route.code} · {route.name}</option>)}
            </select>
            <button type="button" className={styles.primaryButton} onClick={checkCanIRide} disabled={loadingCanRide || !origin || !destination || !rideRouteCode}>
              {loadingCanRide ? "Checking…" : "Check this ride"}
            </button>
          </div>
          {(!origin || !destination) && <p className={styles.emptyState}>Select From and To above to use this check.</p>}
          {canRideResult && <CanIRideResultCard result={canRideResult} />}
        </div>
      </section>

      <section className={styles.reportSection} aria-labelledby="report-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Help keep temporary awareness useful</p><h2 id="report-heading">Report a current commute problem</h2></div></div>
        <p className={styles.sectionCopy}>Reports are signals for review, not automatic route verification or service closures. Do not include personal information.</p>
        <CommuterReportForm routes={routes} stops={stops} places={places} />
      </section>

      <section className={styles.exploration} aria-labelledby="map-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>Journey map</p><h2 id="map-heading">See the boarding points</h2></div>
        </div>
        <p className={styles.sectionCopy}>Transit and computed pedestrian paths are shown separately. If a walking path is unavailable, Biyahe labels straight-line estimates and does not draw a street route.</p>
        <div className={styles.mapPanel}>
          <MapView places={places} stops={stops} journey={selectedJourney} selectedPattern={selectedPattern} disruptions={activeAdvisories} onDisruptionSelect={setSelectedDisruption} onStopSelect={setSelectedStop} rideMode={rideMapState} instructionFocus={instructionFocus} />
          <div className={styles.mapLegend}><span><i className={styles.placeDot} /> places</span><span><i className={styles.stopDot} /> stops</span><span><i className={styles.routeDot} /> transit route</span><span><i className={styles.walkingDot} /> walking path</span><span><i className={styles.transferDot} /> transfer</span></div>
        </div>
        {selectedDisruption && <DisruptionNotice advisory={selectedDisruption} onSelect={() => setSelectedDisruption(null)} />}
        <p className={styles.mapAttribution}>Computed pedestrian paths follow mapped OpenStreetMap ways (© OpenStreetMap contributors); they do not verify transit boarding or current ground conditions.</p>
        {selectedStop && (
          <div className={styles.infoCard}>
            <div><p className={styles.cardKicker}>STOP DETAIL</p><h3>{selectedStop.name}</h3><p>{activeAdvisories.some((advisory) => advisory.affected_entities.stops.some((affected) => affected.id === selectedStop.id)) ? "Active reviewed advisory for this stop" : "No known active reviewed advisory for this stop"}</p></div>
            <p>{selectedStop.boarding_notes ?? "No extra boarding note is recorded."}</p>
            <button type="button" onClick={() => setSelectedStop(null)}>Dismiss</button>
          </div>
        )}
        {selectedPattern?.stops && (
          <div className={styles.infoCard}>
            <div><p className={styles.cardKicker}>ORDERED PATTERN STOPS</p><h3>{selectedPattern.name}</h3><p>{selectedPattern.direction} · {selectedPattern.verification.state}</p></div>
            <ol>
              {selectedPattern.stops.map((patternStop) => <li key={patternStop.sequence}>{patternStop.sequence}. {patternStop.stop.name} · {patternStop.boarding_allowed ? "board" : "no board"} / {patternStop.alighting_allowed ? "alight" : "no alight"}</li>)}
            </ol>
          </div>
        )}
      </section>

      <section className={styles.routeSection} aria-labelledby="routes-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Transport network</p><h2 id="routes-heading">Review route patterns</h2></div></div>
        {loadingNetwork ? <p className={styles.emptyState}>Loading route patterns…</p> : routes.length === 0 ? <p className={styles.emptyState}>No route patterns are available from the API.</p> : (
          <div className={styles.routeGrid}>
            {routes.map((route) => (
            <article className={styles.routeCard} key={route.id}>
                <div className={styles.routeCode}>{route.code}</div>
                <div><h3>{route.name}</h3><p>{route.vehicle_type?.name ?? "Transport service"} · {route.patterns?.length ?? 0} pattern(s) · {route.verification.state}</p><p>{activeAdvisories.some((advisory) => advisory.affected_entities.routes.some((affected) => affected.id === route.id)) ? "Active reviewed advisory" : "No known active reviewed advisory"}</p></div>
                <div className={styles.patternList}>
                  {route.patterns?.map((pattern) => (
                    <button type="button" key={pattern.id} className={selectedPattern?.id === pattern.id ? styles.patternActive : ""} onClick={() => showPattern(pattern)}>
                      <span>{pattern.name}</span><small>{pattern.direction} · {pattern.verification.state}</small>
                    </button>
                  ))}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <footer className={styles.footer}><span>BIYAHE Batch 015</span><span>© OpenStreetMap contributors · operational advisories remain temporary overlays.</span></footer>
    </main>
  );
}
