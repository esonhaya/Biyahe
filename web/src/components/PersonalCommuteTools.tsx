"use client";

import { useEffect, useState } from "react";
import type { Place } from "@/lib/api";
import {
  loadFavoriteCommutes,
  loadFavoritePlaces,
  loadRecentCommutes,
  persistFavoriteCommutes,
  persistFavoritePlaces,
  recordRecentCommute,
  removeFavoriteCommute,
  removeFavoritePlace,
  restorePlace,
  upsertFavoriteCommute,
  upsertFavoritePlace,
  persistRecentCommutes,
  type FavoriteCommute,
  type FavoritePlace,
  type RecentCommute,
} from "@/lib/commuterTools";
import styles from "@/app/page.module.css";

type PersonalCommuteToolsProps = {
  origin: Place | null;
  destination: Place | null;
  savedJourneyCount: number;
  online: boolean;
  refreshToken?: number;
  onSelectPlace: (place: Place, role: "origin" | "destination") => void;
  onSelectCommute: (origin: Place, destination: Place) => void;
};

function placeLabel(place: { name: string; category: string }): string {
  return `${place.name} · ${place.category.replaceAll("_", " ").toLowerCase()}`;
}

export default function PersonalCommuteTools({
  origin,
  destination,
  savedJourneyCount,
  online,
  refreshToken = 0,
  onSelectPlace,
  onSelectCommute,
}: PersonalCommuteToolsProps) {
  const [recents, setRecents] = useState<RecentCommute[]>([]);
  const [favoritePlaces, setFavoritePlaces] = useState<FavoritePlace[]>([]);
  const [favoriteCommutes, setFavoriteCommutes] = useState<FavoriteCommute[]>([]);
  const [placeLabelText, setPlaceLabelText] = useState("");
  const [commuteLabelText, setCommuteLabelText] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  function restore() {
    try {
      setRecents(loadRecentCommutes(window.localStorage));
      setFavoritePlaces(loadFavoritePlaces(window.localStorage));
      setFavoriteCommutes(loadFavoriteCommutes(window.localStorage));
    } catch {
      setRecents([]);
      setFavoritePlaces([]);
      setFavoriteCommutes([]);
      setMessage("Saved commute tools are unavailable in this browser.");
    }
  }

  useEffect(() => {
    const timer = window.setTimeout(restore, 0);
    return () => window.clearTimeout(timer);
  }, [refreshToken]);

  function savePlace(place: Place | null) {
    if (!place) return;
    try {
      const next = upsertFavoritePlace(favoritePlaces, place, placeLabelText || "Favorite place");
      persistFavoritePlaces(window.localStorage, next);
      setFavoritePlaces(next);
      setPlaceLabelText("");
      setMessage(`${place.name} saved locally.`);
    } catch {
      setMessage("This favorite place could not be saved; browser storage may be full.");
    }
  }

  function saveCommute() {
    if (!origin || !destination) return;
    try {
      const next = upsertFavoriteCommute(favoriteCommutes, origin, destination, commuteLabelText || `${origin.name} → ${destination.name}`);
      persistFavoriteCommutes(window.localStorage, next);
      setFavoriteCommutes(next);
      setCommuteLabelText("");
      setMessage("Favorite commute saved locally. It will request a fresh route when online.");
    } catch {
      setMessage("This favorite commute could not be saved; browser storage may be full.");
    }
  }

  function recordAndPersist(originPlace: Place, destinationPlace: Place) {
    try {
      const next = recordRecentCommute(recents, originPlace, destinationPlace);
      persistRecentCommutes(window.localStorage, next);
      setRecents(next);
    } catch {
      setMessage("This search could not be added to local recents.");
    }
  }

  function removePlace(item: FavoritePlace) {
    try {
      const next = removeFavoritePlace(favoritePlaces, item.id);
      persistFavoritePlaces(window.localStorage, next);
      setFavoritePlaces(next);
    } catch {
      setMessage("The favorite place could not be removed.");
    }
  }

  function removeCommute(item: FavoriteCommute) {
    try {
      const next = removeFavoriteCommute(favoriteCommutes, item.id);
      persistFavoriteCommutes(window.localStorage, next);
      setFavoriteCommutes(next);
    } catch {
      setMessage("The favorite commute could not be removed.");
    }
  }

  function chooseRecent(item: RecentCommute) {
    const from = restorePlace(item.origin);
    const to = restorePlace(item.destination);
    recordAndPersist(from, to);
    onSelectCommute(from, to);
  }

  function chooseFavorite(item: FavoriteCommute) {
    const from = restorePlace(item.origin);
    const to = restorePlace(item.destination);
    recordAndPersist(from, to);
    onSelectCommute(from, to);
  }

  return (
    <section className={styles.personalSection} aria-labelledby="personal-heading">
      <div className={styles.sectionHeading}>
        <div><p className={styles.eyebrow}>On this device</p><h2 id="personal-heading">Your places &amp; commutes</h2></div>
        <span className={styles.fixtureBadge}>{savedJourneyCount} saved</span>
      </div>
      <p className={styles.sectionCopy}>Recents, favorite labels, and favorite commutes stay in this browser. A favorite commute asks for a fresh route when online; a saved journey is a specific offline snapshot.</p>
      {!online && <p className={styles.snapshotNote} role="status">Offline: saved journeys and local commute shortcuts remain available. New route search needs connectivity.</p>}

      {(favoritePlaces.length > 0 || origin || destination) && (
        <div className={styles.personalGrid}>
          {favoritePlaces.length > 0 && <div className={styles.personalGroup}>
            <h3>Favorite places</h3>
            {favoritePlaces.map((item) => <div className={styles.personalRow} key={item.id}>
              <button type="button" className={styles.quickButton} onClick={() => onSelectPlace(restorePlace(item.place), "origin")}><strong>{item.label}</strong><span>{placeLabel(item.place)}</span></button>
              <button type="button" className={styles.iconButton} onClick={() => removePlace(item)} aria-label={`Remove ${item.label}`}>Remove</button>
            </div>)}
          </div>}
          {(origin || destination) && <div className={styles.personalGroup}>
            <h3>Assign a place label</h3>
            <input className={styles.smallInput} value={placeLabelText} onChange={(event) => setPlaceLabelText(event.target.value)} maxLength={60} placeholder="Home, work, school…" aria-label="Favorite place label" />
            <div className={styles.inlineActions}>
              <button type="button" className={styles.secondaryButton} disabled={!origin} onClick={() => savePlace(origin)}>Save From</button>
              <button type="button" className={styles.secondaryButton} disabled={!destination} onClick={() => savePlace(destination)}>Save To</button>
            </div>
          </div>}
        </div>
      )}

      {favoriteCommutes.length > 0 && <div className={styles.personalGroup}>
        <h3>Favorite commutes</h3>
        <div className={styles.quickGrid}>
          {favoriteCommutes.map((item) => <div className={styles.quickCard} key={item.id}>
            <button type="button" className={styles.quickButton} onClick={() => chooseFavorite(item)}><strong>{item.label}</strong><span>{item.origin.name} → {item.destination.name}</span></button>
            <button type="button" className={styles.iconButton} onClick={() => removeCommute(item)} aria-label={`Remove ${item.label}`}>Remove</button>
          </div>)}
        </div>
      </div>}

      {recents.length > 0 && <div className={styles.personalGroup}>
        <h3>Recent commutes</h3>
        <div className={styles.quickGrid}>
          {recents.slice(0, 4).map((item) => <button type="button" className={styles.quickButton} key={item.id} onClick={() => chooseRecent(item)}><strong>{item.origin.name} → {item.destination.name}</strong><span>Used {new Date(item.usedAt).toLocaleDateString()}</span></button>)}
        </div>
        <button type="button" className={styles.textButton} onClick={() => { try { window.localStorage.removeItem("biyahe.recent-commutes.v1"); setRecents([]); } catch { setMessage("Recent searches could not be cleared."); } }}>Clear recent commutes</button>
      </div>}

      {origin && destination && <div className={styles.personalSaveRow}>
        <label htmlFor="favorite-commute-label">Save this commute</label>
        <input id="favorite-commute-label" className={styles.smallInput} value={commuteLabelText} onChange={(event) => setCommuteLabelText(event.target.value)} maxLength={60} placeholder={`${origin.name} → ${destination.name}`} />
        <button type="button" className={styles.secondaryButton} onClick={saveCommute}>Save favorite commute</button>
      </div>}
      {message && <p className={styles.status} role="status">{message}</p>}
    </section>
  );
}
