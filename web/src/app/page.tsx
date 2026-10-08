"use client";

import dynamic from "next/dynamic";
import { useEffect, useRef, useState } from "react";
import type { CanIRideResponse, DisruptionSummary, Journey, JourneyResponse, PedestrianAccessLeg, Place, PulseResponse, Route, RoutePattern, Stop } from "@/lib/api";
import { canIRide, getPulse, getRoutePattern, listPlaces, listRoutes, listStops, planJourney, replanJourney, searchPlaces } from "@/lib/api";
import BiyahePulse from "@/components/BiyahePulse";
import CommuteCard from "@/components/CommuteCard";
import CommuterReportForm from "@/components/CommuterReportForm";
import DisruptionNotice from "@/components/DisruptionNotice";
import PersonalCommuteTools from "@/components/PersonalCommuteTools";
import ReplanPanel from "@/components/ReplanPanel";
import RideMode from "@/components/RideMode";
import NearbyRoutes from "@/components/NearbyRoutes";
import FieldMode from "@/components/FieldMode";
import type { CommuteInstruction } from "@/lib/commuteInstructions";
import { hasActiveRideModeSession } from "@/lib/rideMode";
import type { RideModeMapOverlay } from "@/lib/rideMode";
import { loadRecentCommutes, persistRecentCommutes, recordRecentCommute } from "@/lib/commuterTools";
import {
  loadSavedJourneys,
  persistSavedJourneys,
  removeSavedJourney,
  upsertSavedJourney,
  type SavedJourney,
} from "@/lib/savedJourneys";
import styles from "./page.module.css";

const ONBOARDING_KEY = "biyahe.onboarding.dismissed.v1";

const MapView = dynamic(() => import("@/components/MapView"), {
  ssr: false,
  loading: () => <div className="map-frame map-loading">Loading map…</div>,
});

type SearchFieldProps = {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: Place[];
  onPick: (place: Place) => void;
  onClear: () => void;
  onSubmit: () => void;
  loading?: boolean;
};

function SearchField({ id, label, value, onChange, options, onPick, onClear, onSubmit, loading = false }: SearchFieldProps) {
  const [activeIndex, setActiveIndex] = useState(0);
  const menuId = `${id}-suggestions`;
  const hasQuery = value.trim().length > 1;

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === "ArrowDown" && options.length > 0) {
      event.preventDefault();
      setActiveIndex((index) => (index + 1) % options.length);
    } else if (event.key === "ArrowUp" && options.length > 0) {
      event.preventDefault();
      setActiveIndex((index) => (index - 1 + options.length) % options.length);
    } else if (event.key === "Enter") {
      event.preventDefault();
      if (options[activeIndex]) onPick(options[activeIndex]);
      else onSubmit();
    } else if (event.key === "Escape") {
      onClear();
    }
  }

  return (
    <div className={styles.searchField}>
      <label htmlFor={id}>{label}</label>
      <div className={styles.searchInputWrap}>
        <input
          id={id}
          value={value}
          onChange={(event) => { onChange(event.target.value); setActiveIndex(0); }}
          onKeyDown={handleKeyDown}
          placeholder={label === "To" ? "Where are you going?" : "Starting place (optional)"}
          autoComplete="off"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={hasQuery}
          aria-controls={hasQuery ? menuId : undefined}
          aria-activedescendant={hasQuery && options[activeIndex] ? `${id}-option-${options[activeIndex].id}` : undefined}
        />
        {value && <button type="button" className={styles.clearSearch} onClick={onClear} aria-label={`Clear ${label.toLowerCase()}`}>×</button>}
      </div>
      {hasQuery && (
        <div className={styles.suggestions} id={menuId} role="listbox" aria-label={`${label} suggestions`}>
          {loading && <p className={styles.suggestionStatus}>Searching mapped places…</p>}
          {!loading && options.length === 0 && <p className={styles.suggestionStatus}>No mapped place matched that name. Try a landmark or alias.</p>}
          {!loading && options.map((place, index) => (
            <button
              type="button"
              key={place.id}
              id={`${id}-option-${place.id}`}
              onClick={() => onPick(place)}
              role="option"
              aria-selected={index === activeIndex}
              className={index === activeIndex ? styles.suggestionActive : undefined}
            >
              <span>{place.name}</span>
              <small>{place.city?.name ?? "Cebu coverage"} · {place.aliases?.[0]?.alias ?? place.category}</small>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function publicTrustLabel(state: string | null | undefined): string {
  switch (state) {
    case "VERIFIED": return "Confirmed details";
    case "MIXED": return "Mapped, some details need checking";
    case "UNVERIFIED": return "Mapped, details not fully checked";
    default: return "Mapped route details";
  }
}

function ProductOnboarding({ onDismiss }: { onDismiss: () => void }) {
  return (
    <aside className={styles.onboarding} aria-labelledby="onboarding-heading">
      <div>
        <p className={styles.cardKicker}>FIRST TIME HERE?</p>
        <h2 id="onboarding-heading">A simple way to start</h2>
        <p>Search a destination, compare mapped rides, then save or start a manual Ride Mode guide.</p>
      </div>
      <ol>
        <li><strong>Search</strong><span>Choose a place or local landmark.</span></li>
        <li><strong>Check</strong><span>Read boarding and trust notes before you go.</span></li>
        <li><strong>Ride</strong><span>Confirm boarding yourself; location is optional and foreground-only.</span></li>
      </ol>
      <button type="button" className={styles.textButton} onClick={onDismiss}>Got it — hide this</button>
    </aside>
  );
}

function QuickNav() {
  return (
    <nav className={styles.quickNav} aria-label="Biyahe sections">
      <a href="#planning">Plan</a>
      <a href="#saved">Saved</a>
      <a href="#pulse">Pulse</a>
      <a href="#ride-mode">Ride Mode</a>
      <a href="#explore">Explore</a>
    </nav>
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

function canIRideReasonLabel(reasonCode: string): string {
  switch (reasonCode) {
    case "DIRECT_SERVICE_SUPPORTED": return "Why: the selected route has a compatible direction and stop order.";
    case "OPPOSITE_DIRECTION_ONLY": return "Why: this route is known for the opposite direction between these places.";
    case "SERVICE_NOT_SUPPORTED": return "Why: no supported stop sequence is known for this route between these places.";
    case "BOARDING_ACCESS_UNSUPPORTED": return "Why: a supported boarding point is not mapped near the starting place.";
    case "ALIGHTING_ACCESS_UNSUPPORTED": return "Why: a supported alighting point is not mapped near the destination.";
    case "DIRECTION_DATA_INSUFFICIENT": return "Why: the route identity is known, but its usable direction data is incomplete.";
    case "ACTIVE_ADVISORY": return "Why: a reviewed temporary advisory affects this route.";
    case "COMMUNITY_SIGNAL_UNCERTAIN": return "Why: recent commuter signals mention this route, but they are not authoritative.";
    case "ACTIVE_HARD_CLOSURE": return "Why: a reviewed temporary closure currently affects this route or access point.";
    default: return "Why: Biyahe does not have enough verified route evidence to be more specific.";
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
      <p className={styles.sectionCopy}>{canIRideReasonLabel(result.reason_code)}</p>
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
  const [originSearching, setOriginSearching] = useState(false);
  const [destinationSearching, setDestinationSearching] = useState(false);
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
  const [online, setOnline] = useState(true);
  const [personalRefreshToken, setPersonalRefreshToken] = useState(0);
  const [activeRideResume, setActiveRideResume] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [journeyFilter, setJourneyFilter] = useState<"ALL" | "DIRECT" | "ONE_TRANSFER" | "TWO_TRANSFER">("ALL");
  const [routeQuery, setRouteQuery] = useState("");
  const originRequestRef = useRef(0);
  const destinationRequestRef = useRef(0);

  useEffect(() => {
    const updateOnline = () => setOnline(window.navigator.onLine);
    updateOnline();
    window.addEventListener("online", updateOnline);
    window.addEventListener("offline", updateOnline);
    const timer = window.setTimeout(() => {
      try {
        setActiveRideResume(hasActiveRideModeSession(window.localStorage));
      } catch {
        setActiveRideResume(false);
      }
    }, 0);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener("online", updateOnline);
      window.removeEventListener("offline", updateOnline);
    };
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      try {
        setShowOnboarding(window.localStorage.getItem(ONBOARDING_KEY) !== "1");
      } catch {
        setShowOnboarding(true);
      }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

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
    const query = originText.trim();
    if (query.length < 2 || origin?.name === originText) return;
    const controller = new AbortController();
    const requestId = ++originRequestRef.current;
    const timer = window.setTimeout(() => {
      searchPlaces(query, controller.signal)
        .then((results) => { if (requestId === originRequestRef.current) setOriginOptions(results); })
        .catch(() => { if (requestId === originRequestRef.current) setOriginOptions([]); })
        .finally(() => { if (requestId === originRequestRef.current) setOriginSearching(false); });
    }, 250);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [originText, origin]);

  useEffect(() => {
    const query = destinationText.trim();
    if (query.length < 2 || destination?.name === destinationText) return;
    const controller = new AbortController();
    const requestId = ++destinationRequestRef.current;
    const timer = window.setTimeout(() => {
      searchPlaces(query, controller.signal)
        .then((results) => { if (requestId === destinationRequestRef.current) setDestinationOptions(results); })
        .catch(() => { if (requestId === destinationRequestRef.current) setDestinationOptions([]); })
        .finally(() => { if (requestId === destinationRequestRef.current) setDestinationSearching(false); });
    }, 250);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [destinationText, destination]);

  function changeOrigin(value: string) {
    setOrigin(null);
    setOriginText(value);
    setOriginOptions([]);
    setOriginSearching(value.trim().length >= 2);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
    setError(null);
  }

  function changeDestination(value: string) {
    setDestination(null);
    setDestinationText(value);
    setDestinationOptions([]);
    setDestinationSearching(value.trim().length >= 2);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
    setError(null);
  }

  function chooseOrigin(place: Place) {
    setOrigin(place);
    setOriginText(place.name);
    setOriginOptions([]);
    setOriginSearching(false);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
    setError(null);
  }

  function chooseDestination(place: Place) {
    setDestination(place);
    setDestinationText(place.name);
    setDestinationOptions([]);
    setDestinationSearching(false);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
    setError(null);
  }

  function clearOrigin() {
    changeOrigin("");
  }

  function clearDestination() {
    changeDestination("");
  }

  function swapPlaces() {
    const nextOrigin = destination;
    const nextDestination = origin;
    setOrigin(nextOrigin);
    setDestination(nextDestination);
    setOriginText(nextOrigin?.name ?? "");
    setDestinationText(nextDestination?.name ?? "");
    setOriginOptions([]);
    setDestinationOptions([]);
    setOriginSearching(false);
    setDestinationSearching(false);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
    setReplanResult(null);
    setError(null);
    setStatus("Places swapped. Find a route when you are ready.");
  }

  async function searchJourney(from: Place, to: Place) {
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
      const response = await planJourney(from.id, to.id);
      setJourneys(response.data);
      setSelectedJourney(response.data[0] ?? null);
      setNoRouteMessage(response.meta.no_route?.message ?? null);
      setStatus(response.meta.no_route?.message ?? `${response.data.length} supported journey${response.data.length === 1 ? "" : "s"} found.`);
      try {
        const recent = recordRecentCommute(loadRecentCommutes(window.localStorage), from, to);
        persistRecentCommutes(window.localStorage, recent);
        setPersonalRefreshToken((value) => value + 1);
      } catch {
        // A blocked browser store must not make a valid route unusable.
      }
    } catch {
      setError("Journey search is unavailable right now. Check the API connection and try again.");
      setStatus("No journey search result yet.");
    } finally {
      setLoadingJourney(false);
    }
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
    if (!online) {
      setError("New route search needs connectivity. Open a saved journey or choose a local commute shortcut instead.");
      return;
    }
    await searchJourney(origin, destination);
  }

  function chooseLocalCommute(from: Place, to: Place) {
    setOrigin(from);
    setDestination(to);
    setOriginText(from.name);
    setDestinationText(to.name);
    setOriginOptions([]);
    setDestinationOptions([]);
    if (online) {
      void searchJourney(from, to);
    } else {
      setStatus("Selected a local commute. Connect to refresh its route.");
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

  function dismissOnboarding() {
    setShowOnboarding(false);
    try {
      window.localStorage.setItem(ONBOARDING_KEY, "1");
    } catch {
      // A blocked browser store must not block route planning.
    }
  }

  const visibleJourneys = journeys.filter((journey) => {
    if (journeyFilter === "ALL") return true;
    const transfers = journey.legs.filter((leg) => leg.type === "TRANSFER_WALK").length;
    return journeyFilter === "DIRECT" ? transfers === 0 : journeyFilter === "ONE_TRANSFER" ? transfers === 1 : transfers === 2;
  });
  const visibleRoutes = routes.filter((route) => {
    const query = routeQuery.trim().toLowerCase();
    return !query || route.code.toLowerCase().includes(query) || route.name.toLowerCase().includes(query);
  });

  return (
    <main className={styles.page}>
      <a className={styles.skipLink} href="#planning">Skip to journey planning</a>
      <section className={styles.hero}>
        <header className={styles.topBar}>
          <div className={styles.brandRow}>
            <div className={styles.brandMark}>B</div>
            <div>
              <p className={styles.eyebrow}>Cebu commuter guidance</p>
              <h1>BIYAHE</h1>
            </div>
          </div>
          <div className={styles.topBarActions}>
            <span className={`${styles.connectionPill} ${online ? styles.connectionOnline : styles.connectionOffline}`} role="status">
              {online ? "ONLINE · current search" : "OFFLINE · local tools only"}
            </span>
            <QuickNav />
          </div>
        </header>
        <div className={styles.heroCopy}>
          <p className={styles.intro}>Where are you going?</p>
          <p className={styles.heroSubcopy}>Plan around mapped Cebu routes, clear boarding guidance, and honest uncertainty.</p>
        </div>
        <form className={styles.searchCard} id="planning" onSubmit={(event) => { event.preventDefault(); void findRoute(); }}>
          <div className={styles.searchInputsRow}>
            <SearchField id="destination-search" label="To" value={destinationText} onChange={changeDestination} options={destinationOptions} onPick={chooseDestination} onClear={clearDestination} onSubmit={() => { void findRoute(); }} loading={destinationSearching} />
            <button type="button" className={styles.swapButton} onClick={swapPlaces} disabled={!origin && !destination} aria-label="Swap origin and destination">⇄</button>
            <SearchField id="origin-search" label="From" value={originText} onChange={changeOrigin} options={originOptions} onPick={chooseOrigin} onClear={clearOrigin} onSubmit={() => { void findRoute(); }} loading={originSearching} />
          </div>
          <button className={styles.primaryButton} type="submit" disabled={loadingJourney || loadingNetwork || !online}>
            {loadingJourney ? "Finding…" : online ? "Find route" : "Offline"}
          </button>
          <p className={styles.status} role="status">{status}</p>
          {error && <p className={styles.error} role="alert">{error}</p>}
        </form>
        {showOnboarding && <ProductOnboarding onDismiss={dismissOnboarding} />}
        {activeRideResume && <div className={styles.activeRideBanner} role="status">
          <div><strong>Continue your active ride</strong><span>Ride Mode progress is saved on this device. Live location is not restored as current.</span></div>
          <button type="button" className={styles.secondaryButton} onClick={() => document.getElementById("ride-mode-heading")?.scrollIntoView({ behavior: "smooth" })}>Open Ride Mode</button>
        </div>}
      </section>

      <PersonalCommuteTools
        origin={origin}
        destination={destination}
        savedJourneyCount={savedJourneys.length}
        online={online}
        refreshToken={personalRefreshToken}
        onSelectPlace={(place, role) => { if (role === "origin") chooseOrigin(place); else chooseDestination(place); }}
        onSelectCommute={chooseLocalCommute}
      />

      <section className={styles.resultsSection} id="journeys" aria-labelledby="results-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>What to do next</p><h2 id="results-heading">Journey results</h2></div>
          {selectedJourney && <span className={styles.fixtureBadge}>{publicTrustLabel(selectedJourney.trust.state)}</span>}
        </div>
        {journeys.length > 1 && (
          <div className={styles.compareBar} role="group" aria-label="Filter journey options">
            <span>Compare by rides</span>
            {(["ALL", "DIRECT", "ONE_TRANSFER", "TWO_TRANSFER"] as const).map((filter) => (
              <button key={filter} type="button" className={journeyFilter === filter ? styles.compareActive : styles.secondaryButton} onClick={() => setJourneyFilter(filter)}>
                {filter === "ALL" ? "All" : filter === "DIRECT" ? "Direct" : filter === "ONE_TRANSFER" ? "1 transfer" : "2 transfers"}
              </button>
            ))}
          </div>
        )}
        {journeys.length === 0 ? (
          <p className={styles.emptyState}>{loadingJourney ? "Looking for direct, one- or two-transfer rides…" : noRouteMessage ?? "Your supported journeys will appear here."}</p>
        ) : visibleJourneys.length === 0 ? (
          <p className={styles.emptyState}>No journey matches this comparison filter. Show all options to review the other mapped choices.</p>
        ) : (
          <div className={styles.journeyGrid}>
            {visibleJourneys.map((journey, index) => <JourneyCard
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
        <section className={styles.detailSection} id="journey-detail" aria-labelledby="instructions-heading">
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

      <BiyahePulse
        pulse={pulse}
        loading={loadingPulse}
        selectedJourney={selectedJourney}
        onRefresh={() => { void refreshAdvisories(); }}
        onFilter={setPulseFilter}
        onAdvisorySelect={setSelectedDisruption}
      />

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
          setActiveRideResume(true);
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

      <FieldMode />

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

      <section className={styles.exploration} id="map" aria-labelledby="map-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>Journey map</p><h2 id="map-heading">See the boarding points</h2></div>
        </div>
        <p className={styles.sectionCopy}>Transit and computed pedestrian paths are shown separately. If a walking path is unavailable, Biyahe labels straight-line estimates and does not draw a street route.</p>
        <div className={styles.mapTextAlternative} role="note"><strong>Map reading guide:</strong> the text instructions below are the accessible alternative. A pin marked as a boarding area is not an exact waiting point unless the data says so.</div>
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
              <div><p className={styles.cardKicker}>ORDERED PATTERN STOPS</p><h3>{selectedPattern.name}</h3><p>{selectedPattern.direction} · {publicTrustLabel(selectedPattern.verification.state)}</p></div>
            <ol>
              {selectedPattern.stops.map((patternStop) => <li key={patternStop.sequence}>{patternStop.sequence}. {patternStop.stop.name} · {patternStop.boarding_allowed ? "board" : "no board"} / {patternStop.alighting_allowed ? "alight" : "no alight"}</li>)}
            </ol>
          </div>
        )}
      </section>

      <section className={styles.routeSection} id="explore" aria-labelledby="routes-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Transport network</p><h2 id="routes-heading">Review route patterns</h2></div></div>
        <p className={styles.sectionCopy}>Browse the mapped network when you want to explore. A mapped route is not a guarantee of current service or an exact boarding bay.</p>
        <label className={styles.routeSearch} htmlFor="route-search">Find a route<input id="route-search" value={routeQuery} onChange={(event) => setRouteQuery(event.target.value)} placeholder="Code or route name" /></label>
        {loadingNetwork ? <p className={styles.emptyState}>Loading route patterns…</p> : routes.length === 0 ? <p className={styles.emptyState}>No route patterns are available from the API.</p> : visibleRoutes.length === 0 ? <p className={styles.emptyState}>No mapped route matches that search.</p> : (
          <div className={styles.routeGrid}>
            {visibleRoutes.map((route) => (
            <article className={styles.routeCard} key={route.id}>
                <div className={styles.routeCode}>{route.code}</div>
                <div><h3>{route.name}</h3><p>{route.vehicle_type?.name ?? "Transport service"} · {route.patterns?.length ?? 0} direction(s) · {publicTrustLabel(route.verification.state)}</p><p>{activeAdvisories.some((advisory) => advisory.affected_entities.routes.some((affected) => affected.id === route.id)) ? "Active reviewed advisory" : "No known active reviewed advisory"}</p></div>
                <div className={styles.patternList}>
                  {route.patterns?.map((pattern) => (
                    <button type="button" key={pattern.id} className={selectedPattern?.id === pattern.id ? styles.patternActive : ""} onClick={() => showPattern(pattern)}>
                      <span>{pattern.name}</span><small>{pattern.direction} · {publicTrustLabel(pattern.verification.state)}</small>
                    </button>
                  ))}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <NearbyRoutes />

      <footer className={styles.footer}><span>BIYAHE Batch 021</span><span>© OpenStreetMap contributors · operational advisories remain temporary overlays.</span></footer>
    </main>
  );
}
