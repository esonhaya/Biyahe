"use client";

import dynamic from "next/dynamic";
import { useEffect, useMemo, useRef, useState } from "react";
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
import { hasActiveRideModeSession, offlineWalkingRequestIdentity } from "@/lib/rideMode";
import type { RideModeMapOverlay } from "@/lib/rideMode";
import { isRequestCurrentForIdentity, RequestGate, type RequestTicket } from "@/lib/requestGate";
import { LatestPlaceSearch } from "@/lib/latestPlaceSearch";
import { moveSuggestionSelection, selectedSuggestionIndex } from "@/lib/searchKeyboard";
import { journeyGeometryDisclosure } from "@/lib/journeyMapPresentation";
import { journeyOptionState } from "@/lib/journeyComparison";
import { canIRidePresentation } from "@/lib/canIRidePresentation";
import { loadRecentCommutes, persistRecentCommutes, recordRecentCommute } from "@/lib/commuterTools";
import {
  loadSavedJourneys,
  persistSavedJourneys,
  readSavedJourneyStore,
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
  selectedPlace: Place | null;
  onChange: (value: string) => void;
  options: Place[];
  onPick: (place: Place) => void;
  onClear: () => void;
  onSubmit: () => void;
  onRetrySearch: () => void;
  loading?: boolean;
  searchError?: boolean;
};

function SearchField({ id, label, value, selectedPlace, onChange, options, onPick, onClear, onSubmit, onRetrySearch, loading = false, searchError = false }: SearchFieldProps) {
  const [activeIndex, setActiveIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const menuId = `${id}-suggestions`;
  const selectionContextId = `${id}-selected-context`;
  const hasQuery = value.trim().length > 1 && selectedPlace === null;
  const hasSuggestions = hasQuery && options.length > 0 && !loading && !searchError;
  const selectedIndex = selectedSuggestionIndex(activeIndex, options.length);

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    const nextIndex = moveSuggestionSelection(selectedIndex, options.length, event.key);
    if (nextIndex !== null) {
      event.preventDefault();
      setActiveIndex(nextIndex);
    } else if (event.key === "Enter") {
      event.preventDefault();
      if (options[selectedIndex]) onPick(options[selectedIndex]);
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
          ref={inputRef}
          id={id}
          value={value}
          onChange={(event) => { onChange(event.target.value); setActiveIndex(0); }}
          onKeyDown={handleKeyDown}
          placeholder={label === "From" ? "Your starting place" : "Where are you going?"}
          autoComplete="off"
          role="combobox"
          aria-autocomplete="list"
          aria-busy={loading}
          aria-expanded={hasSuggestions}
          aria-controls={hasSuggestions ? menuId : undefined}
          aria-activedescendant={hasSuggestions && options[selectedIndex] ? `${id}-option-${options[selectedIndex].id}` : undefined}
          aria-describedby={selectedPlace ? selectionContextId : undefined}
        />
        {value && <button type="button" className={styles.clearSearch} onClick={() => { onClear(); inputRef.current?.focus(); }} aria-label={`Clear ${label.toLowerCase()}`}>×</button>}
      </div>
      {selectedPlace && <p id={selectionContextId} className={styles.searchSelection}>
        Selected: {selectedPlace.city?.name ?? "Cebu coverage"} · {selectedPlace.category.toLowerCase().replace(/_/g, " ")}
        {selectedPlace.description?.trim() ? ` · ${selectedPlace.description.trim()}` : ""}
      </p>}
      {hasQuery && (
        <div className={styles.suggestions}>
          {loading && <p className={styles.suggestionStatus} role="status" aria-live="polite">Searching mapped places…</p>}
          {!loading && searchError && <div className={styles.suggestionFailure} role="status" aria-live="polite">
            <p className={styles.suggestionStatus}>Place matches could not be loaded. Check the connection and try again.</p>
            <button type="button" className={styles.suggestionRetry} onClick={onRetrySearch}>Retry place search</button>
          </div>}
          {!loading && !searchError && options.length === 0 && <p className={styles.suggestionStatus} role="status" aria-live="polite">No mapped place matched that name. Try a landmark or alias.</p>}
          {hasSuggestions && <div id={menuId} role="listbox" aria-label={`${label} suggestions`}>
            {options.map((place, index) => {
              const alternateName = place.aliases?.find((item) => item.is_active && item.alias.toLowerCase() !== place.name.toLowerCase())?.alias;

              return <button
                type="button"
                key={place.id}
                id={`${id}-option-${place.id}`}
                onClick={() => onPick(place)}
                role="option"
                aria-selected={index === selectedIndex}
                className={index === selectedIndex ? styles.suggestionActive : undefined}
              >
                <span>{place.name}</span>
                <small>{place.city?.name ?? "Cebu coverage"} · {place.category.toLowerCase().replace(/_/g, " ")}</small>
                {place.description?.trim() && <small className={styles.suggestionDescription}>{place.description.trim()}</small>}
                {alternateName && <small>Also known as {alternateName}</small>}
              </button>;
            })}
          </div>}
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

function JourneyCard({ journey, recommended, selected, onSelect, onInstructionFocus }: {
  journey: Journey;
  recommended: boolean;
  selected: boolean;
  onSelect: () => void;
  onInstructionFocus: (instruction: CommuteInstruction) => void;
}) {
  return (
    <div className={`${styles.journeyCard} ${recommended ? styles.recommended : ""} ${selected ? styles.journeyCardSelected : ""}`}>
      <CommuteCard
        journey={journey}
        variant="compact"
        recommended={recommended}
        selected={selected}
        onSelect={onSelect}
        onInstructionFocus={onInstructionFocus}
      />
    </div>
  );
}

function CanIRideResultCard({ result }: { result: CanIRideResponse["data"] }) {
  const option = result.option ?? result.opposite_direction;
  const presentation = canIRidePresentation(result.status, result.route?.code ?? null);
  const tone = styles[presentation.tone];

  return (
    <div className={styles.decisionResult + " " + tone} role="status">
      <div className={styles.decisionHeader}>
        <div>
          <p className={styles.cardKicker}>CAN I RIDE THIS?</p>
          <h3>{presentation.headline}</h3>
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
  const [originSearchError, setOriginSearchError] = useState(false);
  const [destinationSearchError, setDestinationSearchError] = useState(false);
  const [originSearchRetryToken, setOriginSearchRetryToken] = useState(0);
  const [destinationSearchRetryToken, setDestinationSearchRetryToken] = useState(0);
  const [journeys, setJourneys] = useState<Journey[]>([]);
  const [selectedJourney, setSelectedJourney] = useState<Journey | null>(null);
  const selectedJourneyIdentity = useMemo(
    () => selectedJourney ? offlineWalkingRequestIdentity(selectedJourney) : null,
    [selectedJourney],
  );
  const selectedJourneyIdentityRef = useRef<string | null>(selectedJourneyIdentity);
  selectedJourneyIdentityRef.current = selectedJourneyIdentity;
  const offlinePreviewBaselineRef = useRef<{ requestIdentity: string; journey: Journey } | null>(null);
  const [selectedPattern, setSelectedPattern] = useState<RoutePattern | null>(null);
  const [selectedStop, setSelectedStop] = useState<Stop | null>(null);
  const [status, setStatus] = useState("Choose two places to find a supported journey.");
  const [noRouteMessage, setNoRouteMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loadingNetwork, setLoadingNetwork] = useState(true);
  const [networkLoadError, setNetworkLoadError] = useState(false);
  const [networkReloadToken, setNetworkReloadToken] = useState(0);
  const [loadingJourney, setLoadingJourney] = useState(false);
  const [rideRouteCode, setRideRouteCode] = useState("");
  const [canRideResult, setCanRideResult] = useState<CanIRideResponse["data"] | null>(null);
  const [loadingCanRide, setLoadingCanRide] = useState(false);
  const [rideMapState, setRideMapState] = useState<RideModeMapOverlay | null>(null);
  const [instructionFocus, setInstructionFocus] = useState<CommuteInstruction | null>(null);
  const [savedJourneys, setSavedJourneys] = useState<SavedJourney[]>([]);
  const [savedJourneyProblem, setSavedJourneyProblem] = useState<string | null>(null);
  const [offlineSavedJourneyId, setOfflineSavedJourneyId] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const [activeAdvisories, setActiveAdvisories] = useState<DisruptionSummary[]>([]);
  const [selectedDisruption, setSelectedDisruption] = useState<DisruptionSummary | null>(null);
  const [pulse, setPulse] = useState<PulseResponse["data"] | null>(null);
  const [pulseFilter, setPulseFilter] = useState<"ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR">("ALL");
  const [loadingPulse, setLoadingPulse] = useState(true);
  const [pulseError, setPulseError] = useState<string | null>(null);
  const [pulseReloadToken, setPulseReloadToken] = useState(0);
  const [loadingPattern, setLoadingPattern] = useState(false);
  const [patternLoadError, setPatternLoadError] = useState(false);
  const [replanResult, setReplanResult] = useState<{ journeyIdentity: string; response: JourneyResponse } | null>(null);
  const [replanError, setReplanError] = useState<string | null>(null);
  const [loadingReplan, setLoadingReplan] = useState(false);
  const [online, setOnline] = useState(true);
  const [personalRefreshToken, setPersonalRefreshToken] = useState(0);
  const [activeRideResume, setActiveRideResume] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(false);
  const [journeyFilter, setJourneyFilter] = useState<"ALL" | "DIRECT" | "ONE_TRANSFER" | "TWO_TRANSFER">("ALL");
  const [routeQuery, setRouteQuery] = useState("");
  const [journeyRequestActive, setJourneyRequestActive] = useState(false);
  const originPlaceSearchRef = useRef(new LatestPlaceSearch());
  const destinationPlaceSearchRef = useRef(new LatestPlaceSearch());
  const journeyRequestGateRef = useRef(new RequestGate());
  const activeJourneyRequestRef = useRef<RequestTicket | null>(null);
  const patternRequestGateRef = useRef(new RequestGate());
  const canRideRequestGateRef = useRef(new RequestGate());
  const replanRequestGateRef = useRef(new RequestGate());

  useEffect(() => () => {
    journeyRequestGateRef.current.cancel();
    patternRequestGateRef.current.cancel();
    canRideRequestGateRef.current.cancel();
    replanRequestGateRef.current.cancel();
  }, []);

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
      .catch(() => {
        if (controller.signal.aborted) return;
        setNetworkLoadError(true);
        setError("The mapped transport data could not be loaded. Check the connection and try again.");
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingNetwork(false);
      });

    return () => controller.abort();
  }, [networkReloadToken]);

  function retryNetworkLoad() {
    setError(null);
    setNetworkLoadError(false);
    setLoadingNetwork(true);
    setNetworkReloadToken((token) => token + 1);
  }

  useEffect(() => {
    const controller = new AbortController();
    getPulse({ filter: pulseFilter }, controller.signal).then((loaded) => {
      if (controller.signal.aborted) return;
      setPulse(loaded);
      setActiveAdvisories(loaded.items);
    }).catch(() => {
      if (controller.signal.aborted) return;
      setPulse(null);
      setActiveAdvisories([]);
      setPulseError("Pulse could not be loaded. Current advisory status is unknown; try again when connected.");
    }).finally(() => {
      if (!controller.signal.aborted) setLoadingPulse(false);
    });
    return () => controller.abort();
  }, [pulseFilter, pulseReloadToken]);

  function preparePulseRequest() {
    setLoadingPulse(true);
    setPulseError(null);
    setPulse(null);
    setActiveAdvisories([]);
  }

  function refreshAdvisories() {
    preparePulseRequest();
    setPulseReloadToken((token) => token + 1);
  }

  function filterAdvisories(filter: "ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR") {
    if (filter === pulseFilter) return;
    preparePulseRequest();
    setPulseFilter(filter);
  }

  function cancelJourneySearch() {
    if (!activeJourneyRequestRef.current) return;
    journeyRequestGateRef.current.cancel();
    activeJourneyRequestRef.current = null;
    setJourneyRequestActive(false);
    setLoadingJourney(false);
    setStatus("Journey search cancelled. Your places remain selected.");
  }

  function cancelCanIRideCheck() {
    canRideRequestGateRef.current.cancel();
    setLoadingCanRide(false);
  }

  function resetReplan() {
    replanRequestGateRef.current.cancel();
    setLoadingReplan(false);
    setReplanResult(null);
    setReplanError(null);
  }

  function cancelPatternRequest() {
    patternRequestGateRef.current.cancel();
    setLoadingPattern(false);
    setPatternLoadError(false);
  }

  function scrollToMap() {
    if (typeof window === "undefined" || typeof document === "undefined") return;
    const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
    document.getElementById("map")?.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
  }

  function focusInstructionOnMap(journey: Journey, instruction: CommuteInstruction, offlineSnapshot = false) {
    selectJourney(journey, offlineSnapshot);
    setInstructionFocus(instruction);
    if (offlineSnapshot) setStatus("Opened an offline journey snapshot. It may be out of date.");
    scrollToMap();
  }

  async function requestReplan(journey: Journey | null = selectedJourney) {
    if (!journey) return;
    const journeyIdentity = offlineWalkingRequestIdentity(journey);
    if (offlineSavedJourneyId === journey.id) {
      resetReplan();
      setReplanError("New route search requires connectivity. The saved snapshot remains available offline.");
      return;
    }
    const request = replanRequestGateRef.current.begin();
    setLoadingReplan(true);
    setReplanError(null);
    setReplanResult(null);
    try {
      const response = await replanJourney(journey.origin.id, journey.destination.id, request.signal);
      if (!isRequestCurrentForIdentity(replanRequestGateRef.current, request, journeyIdentity, selectedJourneyIdentityRef.current)) return;
      setReplanResult({ journeyIdentity, response });
    } catch {
      if (isRequestCurrentForIdentity(replanRequestGateRef.current, request, journeyIdentity, selectedJourneyIdentityRef.current)) {
        setReplanError("A current alternative could not be loaded. Your existing journey remains selected.");
        setReplanResult(null);
      }
    } finally {
      if (replanRequestGateRef.current.isCurrent(request)) {
        replanRequestGateRef.current.finish(request);
        setLoadingReplan(false);
      }
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
        const savedStore = readSavedJourneyStore(window.localStorage);
        setSavedJourneys(savedStore.journeys);
        setSavedJourneyProblem(savedStore.problem);
      } catch {
        setSavedJourneys([]);
        setSavedJourneyProblem("Saved journey storage could not be read. Existing local data was preserved; saves and deletes are disabled.");
      }
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    const query = originText.trim();
    const search = originPlaceSearchRef.current;
    if (query.length < 2 || origin?.name === originText) {
      search.cancel();
      return;
    }
    const timer = window.setTimeout(() => {
      void search.search(query, searchPlaces).then((outcome) => {
        if (outcome.status === "stale") return;
        setOriginOptions(outcome.results);
        setOriginSearchError(outcome.status === "error");
        setOriginSearching(false);
      });
    }, 250);
    return () => { window.clearTimeout(timer); search.cancel(); };
  }, [originText, origin, originSearchRetryToken]);

  useEffect(() => {
    const query = destinationText.trim();
    const search = destinationPlaceSearchRef.current;
    if (query.length < 2 || destination?.name === destinationText) {
      search.cancel();
      return;
    }
    const timer = window.setTimeout(() => {
      void search.search(query, searchPlaces).then((outcome) => {
        if (outcome.status === "stale") return;
        setDestinationOptions(outcome.results);
        setDestinationSearchError(outcome.status === "error");
        setDestinationSearching(false);
      });
    }, 250);
    return () => { window.clearTimeout(timer); search.cancel(); };
  }, [destinationText, destination, destinationSearchRetryToken]);

  function retryOriginSearch() {
    if (originText.trim().length < 2) return;
    setOriginOptions([]);
    setOriginSearchError(false);
    setOriginSearching(true);
    setOriginSearchRetryToken((token) => token + 1);
  }

  function retryDestinationSearch() {
    if (destinationText.trim().length < 2) return;
    setDestinationOptions([]);
    setDestinationSearchError(false);
    setDestinationSearching(true);
    setDestinationSearchRetryToken((token) => token + 1);
  }

  function changeOrigin(value: string) {
    originPlaceSearchRef.current.cancel();
    cancelJourneySearch();
    cancelPatternRequest();
    cancelCanIRideCheck();
    resetReplan();
    setOrigin(null);
    setOriginText(value);
    setOriginOptions([]);
    setOriginSearchError(false);
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
    destinationPlaceSearchRef.current.cancel();
    cancelJourneySearch();
    cancelPatternRequest();
    cancelCanIRideCheck();
    resetReplan();
    setDestination(null);
    setDestinationText(value);
    setDestinationOptions([]);
    setDestinationSearchError(false);
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
    originPlaceSearchRef.current.cancel();
    cancelJourneySearch();
    cancelPatternRequest();
    cancelCanIRideCheck();
    resetReplan();
    setOrigin(place);
    setOriginText(place.name);
    setOriginOptions([]);
    setOriginSearchError(false);
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
    destinationPlaceSearchRef.current.cancel();
    cancelJourneySearch();
    cancelPatternRequest();
    cancelCanIRideCheck();
    resetReplan();
    setDestination(place);
    setDestinationText(place.name);
    setDestinationOptions([]);
    setDestinationSearchError(false);
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
    cancelJourneySearch();
    cancelPatternRequest();
    cancelCanIRideCheck();
    resetReplan();
    const nextOrigin = destination;
    const nextDestination = origin;
    setOrigin(nextOrigin);
    setDestination(nextDestination);
    setOriginText(nextOrigin?.name ?? "");
    setDestinationText(nextDestination?.name ?? "");
    setOriginOptions([]);
    setDestinationOptions([]);
    setOriginSearchError(false);
    setDestinationSearchError(false);
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
    resetReplan();
    const request = journeyRequestGateRef.current.begin();
    activeJourneyRequestRef.current = request;
    setJourneyRequestActive(true);
    setLoadingJourney(true);
    setError(null);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setNoRouteMessage(null);
    setReplanResult(null);
    setJourneyFilter("ALL");
    try {
      const response = await planJourney(from.id, to.id, request.signal);
      if (!journeyRequestGateRef.current.isCurrent(request)) return;
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
      if (journeyRequestGateRef.current.isCurrent(request)) {
        setError("Journey search is unavailable right now. Check the API connection and try again.");
        setStatus("No journey search result yet.");
      }
    } finally {
      if (journeyRequestGateRef.current.isCurrent(request)) {
        activeJourneyRequestRef.current = null;
        journeyRequestGateRef.current.finish(request);
        setJourneyRequestActive(false);
        setLoadingJourney(false);
      }
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
    cancelJourneySearch();
    setOrigin(from);
    setDestination(to);
    setOriginText(from.name);
    setDestinationText(to.name);
    setOriginOptions([]);
    setDestinationOptions([]);
    setOriginSearchError(false);
    setDestinationSearchError(false);
    if (online) {
      void searchJourney(from, to);
    } else {
      setStatus("Selected a local commute. Connect to refresh its route.");
    }
  }

  async function showPattern(pattern: RoutePattern) {
    cancelJourneySearch();
    cancelPatternRequest();
    resetReplan();
    const request = patternRequestGateRef.current.begin();
    setLoadingPattern(true);
    setSelectedJourney(null);
    setSelectedPattern(pattern);
    setInstructionFocus(null);
    setOfflineSavedJourneyId(null);
    setPatternLoadError(false);
    if (pattern.stops) {
      patternRequestGateRef.current.finish(request);
      setLoadingPattern(false);
      scrollToMap();
      return;
    }

    try {
      const loaded = await getRoutePattern(pattern.id, request.signal);
      if (!patternRequestGateRef.current.isCurrent(request)) return;
      setSelectedPattern(loaded);
      scrollToMap();
    } catch {
      if (patternRequestGateRef.current.isCurrent(request)) {
        setPatternLoadError(true);
      }
    } finally {
      if (patternRequestGateRef.current.isCurrent(request)) {
        patternRequestGateRef.current.finish(request);
        setLoadingPattern(false);
      }
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
    const request = canRideRequestGateRef.current.begin();
    try {
      const response = await canIRide(rideRouteCode, origin.id, destination.id, request.signal);
      if (!canRideRequestGateRef.current.isCurrent(request)) return;
      setCanRideResult(response.data);
    } catch {
      if (canRideRequestGateRef.current.isCurrent(request)) {
        setError("The Can I Ride check is unavailable right now. Check the API connection and try again.");
        setCanRideResult(null);
      }
    } finally {
      if (canRideRequestGateRef.current.isCurrent(request)) {
        canRideRequestGateRef.current.finish(request);
        setLoadingCanRide(false);
      }
    }
  }

  function selectJourney(journey: Journey, offlineSnapshot = false, preserveJourneyRequest?: RequestTicket) {
    if (!preserveJourneyRequest || !journeyRequestGateRef.current.isCurrent(preserveJourneyRequest)) cancelJourneySearch();
    resetReplan();
    offlinePreviewBaselineRef.current = null;
    cancelPatternRequest();
    setSelectedPattern(null);
    setSelectedJourney(journey);
    setOfflineSavedJourneyId(offlineSnapshot ? journey.id : null);
    setInstructionFocus(null);
    setSaveMessage(null);
  }

  useEffect(() => {
    const baseline = offlinePreviewBaselineRef.current;
    if (baseline && selectedJourney && offlineWalkingRequestIdentity(selectedJourney) !== baseline.requestIdentity) {
      offlinePreviewBaselineRef.current = null;
    }
  }, [selectedJourney]);

  function applyOfflineWalkingPreview(previewJourney: Journey, requestIdentity: string) {
    if (!selectedJourney || offlineWalkingRequestIdentity(selectedJourney) !== requestIdentity
      || offlineWalkingRequestIdentity(previewJourney) !== requestIdentity) return;
    if (!selectedJourney.offline_walking_preview) {
      offlinePreviewBaselineRef.current = { requestIdentity, journey: selectedJourney };
    }
    setOfflineSavedJourneyId(null);
    setSelectedJourney(previewJourney);
  }

  function clearOfflineWalkingPreview(requestIdentity: string) {
    const baseline = offlinePreviewBaselineRef.current;
    if (!baseline || baseline.requestIdentity !== requestIdentity || !selectedJourney
      || offlineWalkingRequestIdentity(selectedJourney) !== requestIdentity) return;
    offlinePreviewBaselineRef.current = null;
    setSelectedJourney(baseline.journey);
  }

  function currentSavedJourneyStorageProblem(): string | null {
    try {
      return readSavedJourneyStore(window.localStorage).problem;
    } catch {
      return "Saved journey storage could not be read. Existing local data was preserved; saves and deletes are disabled.";
    }
  }

  function saveCurrentJourney() {
    if (!selectedJourney) return;
    if (savedJourneyProblem) {
      setSaveMessage(savedJourneyProblem);
      return;
    }
    try {
      const next = upsertSavedJourney(savedJourneys, selectedJourney);
      persistSavedJourneys(window.localStorage, next);
      setSavedJourneys(next);
      setSaveMessage("Journey saved on this device.");
    } catch {
      const problem = currentSavedJourneyStorageProblem();
      if (problem) {
        setSavedJourneyProblem(problem);
        setSaveMessage(problem);
      } else {
        setSaveMessage("This journey could not be saved; browser storage may be full.");
      }
    }
  }

  function deleteSavedJourney(id: string) {
    if (savedJourneyProblem) {
      setSaveMessage(savedJourneyProblem);
      return;
    }
    const next = removeSavedJourney(savedJourneys, id);
    try {
      persistSavedJourneys(window.localStorage, next);
      setSavedJourneys(next);
      if (offlineSavedJourneyId === id) setOfflineSavedJourneyId(null);
      setSaveMessage("Saved journey removed.");
    } catch {
      const problem = currentSavedJourneyStorageProblem();
      if (problem) {
        setSavedJourneyProblem(problem);
        setSaveMessage(problem);
      } else {
        setSaveMessage("The saved journey could not be removed from browser storage.");
      }
    }
  }

  function openSavedJourney(saved: SavedJourney) {
    cancelJourneySearch();
    selectJourney(saved.journey, true);
    setStatus("Opened an offline journey snapshot. It may be out of date.");
  }

  async function refreshSavedJourney(saved: SavedJourney) {
    resetReplan();
    const request = journeyRequestGateRef.current.begin();
    activeJourneyRequestRef.current = request;
    setJourneyRequestActive(true);
    setLoadingJourney(true);
    setError(null);
    try {
      const response = await planJourney(saved.journey.origin.id, saved.journey.destination.id, request.signal);
      if (!journeyRequestGateRef.current.isCurrent(request)) return;
      setJourneys(response.data);
      if (response.data[0]) {
        setStatus(`${response.data.length} current journey${response.data.length === 1 ? "" : "s"} found.`);
        selectJourney(response.data[0], false, request);
      } else {
        setNoRouteMessage(response.meta.no_route?.message ?? "No supported current journey was returned.");
        setStatus("No current route was found. Your saved snapshot remains available below.");
        selectJourney(saved.journey, true, request);
      }
    } catch {
      if (journeyRequestGateRef.current.isCurrent(request)) {
        setError("A current journey could not be loaded. The saved snapshot remains available.");
      }
    } finally {
      if (journeyRequestGateRef.current.isCurrent(request)) {
        activeJourneyRequestRef.current = null;
        journeyRequestGateRef.current.finish(request);
        setJourneyRequestActive(false);
        setLoadingJourney(false);
      }
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
  const plannerRecommendationIdentity = journeys[0]
    ? offlineWalkingRequestIdentity(journeys[0])
    : null;
  const visibleRoutes = routes.filter((route) => {
    const query = routeQuery.trim().toLowerCase();
    return !query || route.code.toLowerCase().includes(query) || route.name.toLowerCase().includes(query);
  });
  const selectedGeometryDisclosure = selectedJourney ? journeyGeometryDisclosure(selectedJourney) : null;

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
            <SearchField id="origin-search" label="From" value={originText} selectedPlace={origin} onChange={changeOrigin} options={originOptions} onPick={chooseOrigin} onClear={clearOrigin} onSubmit={() => { void findRoute(); }} onRetrySearch={retryOriginSearch} loading={originSearching} searchError={originSearchError} />
            <button type="button" className={styles.swapButton} onClick={swapPlaces} disabled={!origin && !destination} aria-label="Swap origin and destination">⇄</button>
            <SearchField id="destination-search" label="To" value={destinationText} selectedPlace={destination} onChange={changeDestination} options={destinationOptions} onPick={chooseDestination} onClear={clearDestination} onSubmit={() => { void findRoute(); }} onRetrySearch={retryDestinationSearch} loading={destinationSearching} searchError={destinationSearchError} />
          </div>
          <div className={styles.searchFormActions}>
            <button className={styles.primaryButton} type="submit" disabled={loadingJourney || loadingNetwork || !online}>
              {loadingJourney ? "Finding…" : online ? "Find route" : "Offline"}
            </button>
            {journeyRequestActive && <button type="button" className={styles.secondaryButton} onClick={cancelJourneySearch}>Cancel request</button>}
          </div>
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

      {savedJourneyProblem && <div className={styles.rideNotice} role="alert" aria-label="Saved journey data needs attention">
        <strong>Saved journey data was not changed.</strong>
        <span>{savedJourneyProblem} Back up this browser’s local data before clearing or repairing it.</span>
      </div>}

      <section className={styles.resultsSection} id="journeys" aria-labelledby="results-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>What to do next</p><h2 id="results-heading">Journey results</h2></div>
          {selectedJourney && <span className={styles.fixtureBadge}>{publicTrustLabel(selectedJourney.trust.state)}</span>}
        </div>
        {journeys.length > 1 && (
          <div className={styles.compareBar} role="group" aria-label="Filter journey options">
            <span>Compare by rides</span>
            {(["ALL", "DIRECT", "ONE_TRANSFER", "TWO_TRANSFER"] as const).map((filter) => (
              <button key={filter} type="button" aria-pressed={journeyFilter === filter} className={journeyFilter === filter ? styles.compareActive : styles.secondaryButton} onClick={() => setJourneyFilter(filter)}>
                {filter === "ALL" ? "All" : filter === "DIRECT" ? "Direct" : filter === "ONE_TRANSFER" ? "1 transfer" : "2 transfers"}
              </button>
            ))}
          </div>
        )}
        {journeys.length === 0 ? (
          <p className={styles.emptyState} role={loadingJourney ? "status" : undefined} aria-live={loadingJourney ? "polite" : undefined}>{loadingJourney ? "Looking for direct, one- or two-transfer rides…" : noRouteMessage ?? "Your supported journeys will appear here."}</p>
        ) : visibleJourneys.length === 0 ? (
          <p className={styles.emptyState}>No journey matches this comparison filter. <button type="button" className={styles.textButton} onClick={() => setJourneyFilter("ALL")}>Show all journeys</button></p>
        ) : (
          <div className={styles.journeyGrid}>
            {visibleJourneys.map((journey) => {
              const optionState = journeyOptionState(
                offlineWalkingRequestIdentity(journey),
                plannerRecommendationIdentity,
                selectedJourneyIdentity,
              );
              return <JourneyCard
                key={journey.id}
                journey={journey}
                recommended={optionState.recommended}
                selected={optionState.selected}
                onSelect={() => selectJourney(journey)}
                onInstructionFocus={(instruction) => focusInstructionOnMap(journey, instruction)}
              />;
            })}
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
            onInstructionFocus={(instruction) => focusInstructionOnMap(selectedJourney, instruction, offlineSavedJourneyId === selectedJourney.id)}
            onSave={saveCurrentJourney}
            onOfflinePreview={applyOfflineWalkingPreview}
            onClearOfflinePreview={clearOfflineWalkingPreview}
            onReplan={selectedJourney.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") ? () => { void requestReplan(selectedJourney); } : undefined}
          />
          {saveMessage && <p className={styles.status} role="status">{saveMessage}</p>}
          {selectedJourney.disruptions?.some((impact) => impact.impact_level !== "INFORMATIONAL") && (
            <ReplanPanel
              currentJourney={selectedJourney}
              result={replanResult?.journeyIdentity === selectedJourneyIdentity ? replanResult.response : null}
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
        error={pulseError}
        selectedJourney={selectedJourney}
        onRefresh={() => { void refreshAdvisories(); }}
        onFilter={filterAdvisories}
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
            {savedJourneys.map((saved, index) => (
              <CommuteCard
                key={`${saved.id}:${saved.savedAt}:${index}`}
                journey={saved.journey}
                variant="saved"
                offlineSnapshot
                savedAt={saved.savedAt}
                isSaved
                onInstructionFocus={(instruction) => focusInstructionOnMap(saved.journey, instruction, true)}
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
          let restoredIsSaved = false;
          try {
            restoredIsSaved = loadSavedJourneys(window.localStorage).some((saved) => saved.id === journey.id);
          } catch {
            restoredIsSaved = false;
          }
          selectJourney(journey, restoredIsSaved);
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
            <select id="ride-route" value={rideRouteCode} onChange={(event) => { cancelCanIRideCheck(); setRideRouteCode(event.target.value); setCanRideResult(null); }}>
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
          <div><p className={styles.eyebrow}>Journey map</p><h2 id="map-heading">See locations and available paths</h2></div>
        </div>
        <p className={styles.sectionCopy}>Transit and computed pedestrian paths are shown separately. If a walking path is unavailable, Biyahe labels straight-line estimates and does not draw a street route.</p>
        <div className={styles.mapTextAlternative} role="note"><strong>Map reading guide:</strong> text steps are the accessible alternative. Boarding-area pins are not exact waiting points, transfer markers do not verify a safe interchange, and estimate-only walking legs have no route line.</div>
        <div className={styles.mapPanel}>
          <MapView places={places} stops={stops} journey={selectedJourney} selectedPattern={selectedPattern} disruptions={activeAdvisories} onDisruptionSelect={setSelectedDisruption} onStopSelect={setSelectedStop} rideMode={rideMapState} instructionFocus={instructionFocus} />
        <div className={styles.mapLegend}><span><i className={styles.placeDot} /> places</span><span><i className={styles.stopDot} /> stops</span><span><i className={styles.routeDot} /> transit route</span><span><i className={styles.walkingDot} /> mapped walking path</span><span><i className={styles.transferDot} /> transfer · unverified</span></div>
        </div>
        {selectedGeometryDisclosure && selectedGeometryDisclosure.rideLegsWithoutGeometry > 0 && <p className={styles.mapTextAlternative} role="status">Transit line geometry is unavailable for {selectedGeometryDisclosure.rideLegsWithoutGeometry} ride leg{selectedGeometryDisclosure.rideLegsWithoutGeometry === 1 ? "" : "s"}; no line is drawn for {selectedGeometryDisclosure.rideLegsWithoutGeometry === 1 ? "that leg" : "those legs"}.</p>}
        {selectedGeometryDisclosure && selectedGeometryDisclosure.mappedWalkingLegsWithoutGeometry > 0 && <p className={styles.mapTextAlternative} role="status">A mapped walking distance is available for {selectedGeometryDisclosure.mappedWalkingLegsWithoutGeometry} leg{selectedGeometryDisclosure.mappedWalkingLegsWithoutGeometry === 1 ? "" : "s"}, but its route line is missing and is not drawn.</p>}
        {selectedGeometryDisclosure && selectedGeometryDisclosure.estimateOnlyWalkingLegs > 0 && <p className={styles.mapTextAlternative} role="status">{selectedGeometryDisclosure.estimateOnlyWalkingLegs} walking leg{selectedGeometryDisclosure.estimateOnlyWalkingLegs === 1 ? " has" : "s have"} no mapped pedestrian path; any distance estimate stays separate and no walking route line is drawn.</p>}
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
        {loadingPattern && selectedPattern && <p className={styles.emptyState} role="status" aria-live="polite">Loading {selectedPattern.direction} stop details…</p>}
        {patternLoadError && selectedPattern && <div className={styles.emptyState} role="alert">
          <p>Could not load the stops for {selectedPattern.name} · {selectedPattern.direction}. The mapped route remains selected.</p>
          <button type="button" className={styles.secondaryButton} onClick={() => { void showPattern(selectedPattern); }}>Retry this direction</button>
        </div>}
      </section>

      <section className={styles.routeSection} id="explore" aria-labelledby="routes-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Transport network</p><h2 id="routes-heading">Review route patterns</h2></div></div>
        <p className={styles.sectionCopy}>Browse the mapped network when you want to explore. A mapped route is not a guarantee of current service or an exact boarding bay.</p>
        <label className={styles.routeSearch} htmlFor="route-search">Find a route<input id="route-search" value={routeQuery} onChange={(event) => setRouteQuery(event.target.value)} placeholder="Code or route name" /></label>
        {loadingNetwork ? <p className={styles.emptyState} role="status">Loading route patterns…</p> : networkLoadError ? (
          <div className={styles.emptyState} role="alert">
            <p>Mapped route data could not be loaded. Retry when you are online.</p>
            <button type="button" className={styles.secondaryButton} onClick={retryNetworkLoad} disabled={!online || loadingNetwork}>Try again</button>
          </div>
        ) : routes.length === 0 ? <p className={styles.emptyState}>No mapped route patterns are currently available.</p> : visibleRoutes.length === 0 ? <p className={styles.emptyState}>No mapped route matches that search. <button type="button" className={styles.textButton} onClick={() => setRouteQuery("")}>Clear route search</button></p> : (
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
