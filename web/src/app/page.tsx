"use client";

import dynamic from "next/dynamic";
import { useEffect, useState } from "react";
import type { Place, Route, RoutePattern, Stop } from "@/lib/api";
import { getRoutePattern, listPlaces, listRoutes, listStops, searchPlaces } from "@/lib/api";
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
      <label htmlFor={label}>{label}</label>
      <input
        id={label}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder="Search a place or local name"
        autoComplete="off"
      />
      {value.length > 1 && options.length > 0 && (
        <div className={styles.suggestions} role="listbox">
          {options.map((place) => (
            <button type="button" key={place.id} onClick={() => onPick(place)}>
              <span>{place.name}</span>
              <small>{place.aliases?.[0]?.alias ?? place.category}</small>
            </button>
          ))}
        </div>
      )}
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
  const [selectedPattern, setSelectedPattern] = useState<RoutePattern | null>(null);
  const [selectedStop, setSelectedStop] = useState<Stop | null>(null);
  const [message, setMessage] = useState("Loading the reviewed Cebu network slice…");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all([listPlaces(controller.signal), listStops(controller.signal), listRoutes(controller.signal)])
      .then(([loadedPlaces, loadedStops, loadedRoutes]) => {
        setPlaces(loadedPlaces);
        setStops(loadedStops);
        setRoutes(loadedRoutes);
        setMessage("Reviewed places and stops are shown with route associations still marked UNVERIFIED.");
      })
      .catch(() => setMessage("The API is not connected. Start the Laravel API to browse transport data."))
      .finally(() => setLoading(false));

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

  function chooseOrigin(place: Place) {
    setOrigin(place);
    setOriginText(place.name);
    setOriginOptions([]);
  }

  function chooseDestination(place: Place) {
    setDestination(place);
    setDestinationText(place.name);
    setDestinationOptions([]);
  }

  function explainRoutingStatus() {
    setMessage(
      origin && destination
        ? "Journey planning is intentionally deferred. Browse a route pattern and its ordered stops instead."
        : "Choose both a starting place and destination; journey planning is intentionally deferred.",
    );
  }

  async function showPattern(pattern: RoutePattern) {
    setSelectedPattern(pattern);
    if (!pattern.stops) {
      try {
        setSelectedPattern(await getRoutePattern(pattern.id));
      } catch {
        setMessage("This pattern could not be loaded from the API.");
      }
    }
  }

  return (
    <main className={styles.page}>
      <section className={styles.hero}>
        <div className={styles.brandRow}>
          <div className={styles.brandMark}>B</div>
          <div>
            <p className={styles.eyebrow}>Philippine transport knowledge</p>
            <h1>BIYAHE</h1>
          </div>
        </div>
        <p className={styles.intro}>Make the next ride feel less uncertain.</p>
        <div className={styles.searchCard}>
          <SearchField label="From" value={originText} onChange={(value) => { setOrigin(null); setOriginText(value); }} options={originOptions} onPick={chooseOrigin} />
          <SearchField label="To" value={destinationText} onChange={(value) => { setDestination(null); setDestinationText(value); }} options={destinationOptions} onPick={chooseDestination} />
          <button className={styles.primaryButton} type="button" onClick={explainRoutingStatus}>Find route</button>
          <p className={styles.status} role="status">{message}</p>
        </div>
      </section>

      <section className={styles.exploration} aria-labelledby="explore-heading">
        <div className={styles.sectionHeading}>
          <div>
            <p className={styles.eyebrow}>First service area</p>
            <h2 id="explore-heading">Explore Cebu</h2>
          </div>
          <span className={styles.fixtureBadge}>REVIEW SLICE</span>
        </div>
        <p className={styles.sectionCopy}>Place and stop geography is reviewed against OpenStreetMap. Route geometry and service stop ordering remain explicitly UNVERIFIED until owner review.</p>
        <div className={styles.mapPanel}>
          <MapView places={places} stops={stops} selectedPattern={selectedPattern} onStopSelect={setSelectedStop} />
          <div className={styles.mapLegend}><span><i className={styles.placeDot} /> places</span><span><i className={styles.stopDot} /> stops</span><span><i className={styles.routeDot} /> selected pattern</span></div>
        </div>
        {selectedStop && (
          <div className={styles.infoCard}>
            <div><p className={styles.cardKicker}>BOARDING POINT</p><h3>{selectedStop.name}</h3></div>
            <p>{selectedStop.boarding_notes ?? "No commuter note has been recorded for this fixture."}</p>
            <button type="button" onClick={() => setSelectedStop(null)}>Dismiss</button>
          </div>
        )}
        {selectedPattern?.stops && (
          <div className={styles.infoCard}>
            <div><p className={styles.cardKicker}>ORDERED PATTERN STOPS</p><h3>{selectedPattern.name}</h3></div>
            <ol>
              {selectedPattern.stops.map((patternStop) => <li key={patternStop.sequence}>{patternStop.sequence}. {patternStop.stop.name}</li>)}
            </ol>
          </div>
        )}
      </section>

      <section className={styles.routeSection} aria-labelledby="routes-heading">
        <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Transport network</p><h2 id="routes-heading">Route patterns</h2></div></div>
        {loading ? <p className={styles.emptyState}>Loading routes…</p> : routes.length === 0 ? <p className={styles.emptyState}>No routes are available. Start the API and seed the development fixture.</p> : (
          <div className={styles.routeGrid}>
            {routes.map((route) => (
              <article className={styles.routeCard} key={route.id}>
                <div className={styles.routeCode}>{route.code}</div>
                <div><h3>{route.name}</h3><p>{route.vehicle_type?.name ?? "Transport service"} · {route.patterns?.length ?? 0} pattern(s) · {route.verification.state}</p></div>
                <div className={styles.patternList}>
                  {route.patterns?.map((pattern) => (
                    <button type="button" key={pattern.id} className={selectedPattern?.id === pattern.id ? styles.patternActive : ""} onClick={() => showPattern(pattern)}>
                      <span>{pattern.name}</span><small>{pattern.direction}</small>
                    </button>
                  ))}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <footer className={styles.footer}><span>BIYAHE Batch 002</span><span>Verified local knowledge is the destination.</span></footer>
    </main>
  );
}
