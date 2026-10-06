"use client";

import dynamic from "next/dynamic";
import { useEffect, useState } from "react";
import type { CanIRideResponse, Journey, PedestrianAccessLeg, Place, Route, RoutePattern, Stop } from "@/lib/api";
import { canIRide, getRoutePattern, listPlaces, listRoutes, listStops, planJourney, searchPlaces } from "@/lib/api";
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

function formatWalkLeg(leg: Journey["legs"][number]) {
  if (leg.distance_type === "NOT_REQUIRED") return "No separate walk mapped";
  if (leg.access_classification === "NO_WALKABLE_ACCESS") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · mapped access is unresolved`;
  }
  if (leg.access_classification === "GRAPH_COVERAGE_GAP") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · walking access could not be confirmed from map data`;
  }
  if (leg.access_classification === "ROUTING_UNAVAILABLE") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · pedestrian routing unavailable`;
  }
  if (leg.routing_status === "NO_ROUTE") {
    return `${formatDistance(leg.distance_meters)} straight-line estimate · no pedestrian path mapped`;
  }
  if (leg.distance_type === "PEDESTRIAN_NETWORK") {
    const duration = leg.estimated_duration_seconds === null ? "" : ` · about ${Math.max(1, Math.round(leg.estimated_duration_seconds / 60))} min estimated`;
    return `${formatDistance(leg.distance_meters)} pedestrian path${duration}`;
  }
  if (leg.distance_type === "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS") {
    const duration = leg.estimated_duration_seconds === null ? "" : ` · about ${Math.max(1, Math.round(leg.estimated_duration_seconds / 60))} min estimated`;
    return `${formatDistance(leg.distance_meters)} mapped path with approximate endpoint connectors${duration}`;
  }
  return `${formatDistance(leg.distance_meters)} straight-line estimate · no pedestrian route shown`;
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

function JourneyCard({ journey, recommended, onSelect }: { journey: Journey; recommended: boolean; onSelect: () => void }) {
  return (
    <article className={`${styles.journeyCard} ${recommended ? styles.recommended : ""}`}>
      <div className={styles.journeyCardHeader}>
        <div>
          <p className={styles.cardKicker}>{recommended ? "RECOMMENDED" : "ALTERNATIVE"}</p>
          <h3>
            {journey.transfer_count === 0
              ? "Direct ride"
              : journey.transfer_count + " transfer" + (journey.transfer_count === 1 ? "" : "s")}
          </h3>
        </div>
        <span className={styles.trustBadge}>{trustLabel(journey.trust.state)}</span>
      </div>
      {journey.ranking.reasons.length > 0 && <p className={styles.rankingReasons}>{journey.ranking.reasons.join(" · ")}</p>}
      <div className={styles.legList}>
        {journey.legs.map((leg, index) => {
          if (leg.type === "RIDE") {
            return (
              <div className={styles.rideLeg} key={`${journey.id}-${index}`}>
                <div className={styles.legMarker}>{leg.route?.code ?? "Ride"}</div>
                <div className={styles.legBody}>
                  <p className={styles.legLabel}>Ride · {leg.pattern?.direction ?? "direction not recorded"}</p>
                  <p><strong>Board at:</strong> {leg.boarding_stop?.name ?? leg.origin.name}</p>
                  {leg.boarding_instruction && <p>{leg.boarding_instruction}</p>}
                  {leg.driver_instruction && <p><strong>{leg.driver_instruction}</strong></p>}
                  <p><strong>{leg.get_off_guidance ?? `Get off near ${leg.alighting_stop?.name ?? leg.destination.name}.`}</strong></p>
                  {leg.stop_count !== null && <small>{leg.stop_count} stops · {leg.route?.name ?? "Route details"}</small>}
                </div>
              </div>
            );
          }

          if (leg.type === "TRANSFER_WALK") {
            return (
              <div className={styles.walkLeg} key={`${journey.id}-${index}`}>
                <div className={styles.legMarker}>↳</div>
                <div className={styles.legBody}>
                  <p className={styles.legLabel}>Transfer walk</p>
                  <p>{leg.instruction ?? `From ${leg.origin.name} to ${leg.destination.name}`}</p>
                  <small>{formatWalkLeg(leg)}</small>
                  {leg.warnings.map((warning) => <small className={styles.warning} key={warning}>{warning}</small>)}
                </div>
              </div>
            );
          }

          return (
            <div className={styles.walkLeg} key={`${journey.id}-${index}`}>
              <div className={styles.legMarker}>⌁</div>
              <div className={styles.legBody}>
                <p className={styles.legLabel}>{leg.type === "WALK_ACCESS" ? "Walk to boarding point" : "Walk to destination"}</p>
                <p>{leg.instruction ?? `Walk from ${leg.origin.name} to ${leg.destination.name}.`}</p>
                <small>{formatWalkLeg(leg)}</small>
                {leg.warnings.map((warning) => <small className={styles.warning} key={warning}>{warning}</small>)}
              </div>
            </div>
          );
        })}
      </div>
      <div className={styles.journeyFooter}>
        <span>Fare: {journey.fare.label}</span>
        <button type="button" onClick={onSelect} className={styles.secondaryButton}>Show on map</button>
      </div>
      {journey.warnings.map((warning) => <p className={styles.warning} key={warning}>{warning}</p>)}
    </article>
  );
}

function CanIRideResultCard({ result }: { result: CanIRideResponse["data"] }) {
  const option = result.option ?? result.opposite_direction;
  const tone = result.status === "YES"
    ? styles.decisionYes
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
    setNoRouteMessage(null);
    setCanRideResult(null);
  }

  function changeDestination(value: string) {
    setDestination(null);
    setDestinationText(value);
    setDestinationOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
  }

  function chooseOrigin(place: Place) {
    setOrigin(place);
    setOriginText(place.name);
    setOriginOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
  }

  function chooseDestination(place: Place) {
    setDestination(place);
    setDestinationText(place.name);
    setDestinationOptions([]);
    setJourneys([]);
    setSelectedJourney(null);
    setSelectedPattern(null);
    setNoRouteMessage(null);
    setCanRideResult(null);
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
    setNoRouteMessage(null);
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

      <section className={styles.resultsSection} aria-labelledby="results-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>What to do next</p><h2 id="results-heading">Journey results</h2></div>
          {selectedJourney && <span className={styles.fixtureBadge}>{trustLabel(selectedJourney.trust.state)}</span>}
        </div>
        {journeys.length === 0 ? (
          <p className={styles.emptyState}>{loadingJourney ? "Looking for direct, one- or two-transfer rides…" : noRouteMessage ?? "Your supported journeys will appear here."}</p>
        ) : (
          <div className={styles.journeyGrid}>
            {journeys.map((journey, index) => <JourneyCard key={journey.id} journey={journey} recommended={index === 0} onSelect={() => { setSelectedPattern(null); setSelectedJourney(journey); }} />)}
          </div>
        )}
      </section>

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

      <section className={styles.exploration} aria-labelledby="map-heading">
        <div className={styles.sectionHeading}>
          <div><p className={styles.eyebrow}>Journey map</p><h2 id="map-heading">See the boarding points</h2></div>
        </div>
        <p className={styles.sectionCopy}>Transit and computed pedestrian paths are shown separately. If a walking path is unavailable, Biyahe labels straight-line estimates and does not draw a street route.</p>
        <div className={styles.mapPanel}>
          <MapView places={places} stops={stops} journey={selectedJourney} selectedPattern={selectedPattern} onStopSelect={setSelectedStop} />
          <div className={styles.mapLegend}><span><i className={styles.placeDot} /> places</span><span><i className={styles.stopDot} /> stops</span><span><i className={styles.routeDot} /> transit route</span><span><i className={styles.walkingDot} /> walking path</span><span><i className={styles.transferDot} /> transfer</span></div>
        </div>
        <p className={styles.mapAttribution}>Computed pedestrian paths follow mapped OpenStreetMap ways (© OpenStreetMap contributors); they do not verify transit boarding or current ground conditions.</p>
        {selectedStop && (
          <div className={styles.infoCard}>
            <div><p className={styles.cardKicker}>STOP DETAIL</p><h3>{selectedStop.name}</h3></div>
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
                <div><h3>{route.name}</h3><p>{route.vehicle_type?.name ?? "Transport service"} · {route.patterns?.length ?? 0} pattern(s) · {route.verification.state}</p></div>
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

      <footer className={styles.footer}><span>BIYAHE Batch 005</span><span>© OpenStreetMap contributors · route confidence is shown, not hidden.</span></footer>
    </main>
  );
}
