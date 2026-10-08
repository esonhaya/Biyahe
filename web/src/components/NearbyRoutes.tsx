"use client";

import { useState } from "react";
import { listNearbyRoutes, type NearbyRoute } from "@/lib/api";
import styles from "@/app/page.module.css";

function boardingLabel(precision: NearbyRoute["boarding_candidates"][number]["stop"]["boarding_precision"]): string {
  switch (precision) {
    case "EXACT_BOARDING_POINT": return "exact point mapped";
    case "BOARDING_AREA": return "boarding area mapped";
    case "APPROXIMATE_LOCATION": return "approximate location";
    default: return "exact boarding not verified";
  }
}

function trustLabel(state: NearbyRoute["verification"]["state"]): string {
  switch (state) {
    case "VERIFIED": return "confirmed details";
    default: return "mapped details not fully checked";
  }
}

export default function NearbyRoutes() {
  const [routes, setRoutes] = useState<NearbyRoute[]>([]);
  const [status, setStatus] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  function findNearby() {
    setRoutes([]);
    if (!navigator.geolocation) {
      setStatus("This browser does not provide foreground location. Use route search instead.");
      return;
    }
    setLoading(true);
    setStatus("Requesting one foreground location…");
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setStatus("Loading mapped boarding candidates for this location…");
        void listNearbyRoutes(position.coords.latitude, position.coords.longitude, { radius: 1000, limit: 12 })
          .then((response) => {
            setRoutes(response.data);
            setStatus(response.data.length === 0
              ? "No mapped boarding candidates were found within 1 km. This does not mean no service exists."
              : `${response.data.length} route${response.data.length === 1 ? "" : "s"} near mapped boarding candidates.`);
          })
          .catch(() => {
            setRoutes([]);
            setStatus("Nearby routes could not be loaded. No location was saved.");
          })
          .finally(() => setLoading(false));
      },
      (error) => {
        setLoading(false);
        setRoutes([]);
        setStatus(error.code === error.PERMISSION_DENIED
          ? "Location permission was denied. Search a place instead; no location was saved."
          : "Your current location is unavailable. Search a place instead; no location was saved.");
      },
      { enableHighAccuracy: false, maximumAge: 60_000, timeout: 10_000 },
    );
  }

  return (
    <section className={styles.nearbySection} id="nearby-routes" aria-labelledby="nearby-routes-heading">
      <div className={styles.sectionHeading}>
        <div><p className={styles.eyebrow}>Optional foreground location</p><h2 id="nearby-routes-heading">What can I ride here?</h2></div>
      </div>
      <p className={styles.sectionCopy}>Use one current location to find nearby mapped boarding candidates. Biyahe does not save this location, track you, or claim that a vehicle is present.</p>
      <button type="button" className={styles.secondaryButton} onClick={findNearby} disabled={loading}>{loading ? "Checking nearby routes…" : "Find routes near me"}</button>
      {status && <p className={styles.status} role="status">{status}</p>}
      {status && /denied|unavailable|does not provide|could not be loaded/i.test(status) && <a className={styles.textButton} href="#planning">Search a place instead</a>}
      {routes.length > 0 && <div className={styles.nearbyGrid}>
        {routes.map((route) => {
          const candidate = route.boarding_candidates[0];
          return (
            <article className={styles.nearbyCard} key={route.id}>
              <div className={styles.routeCode}>{route.code}</div>
              <div>
                <h3>{route.name}</h3>
                <p>{route.vehicle_type.name} · {Math.round(route.nearest_distance_meters)} m to a mapped candidate · {trustLabel(route.verification.state)}</p>
                {candidate && <p><strong>{candidate.pattern.direction}</strong> · {candidate.stop.name} · {boardingLabel(candidate.stop.boarding_precision)}</p>}
                {route.active_advisories.length > 0 && <p className={styles.warning}>Active reviewed advisory: {route.active_advisories[0].headline}</p>}
                <small>{route.nearby_note}</small>
              </div>
            </article>
          );
        })}
      </div>}
    </section>
  );
}
