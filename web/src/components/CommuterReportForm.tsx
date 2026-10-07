"use client";

import { useState } from "react";
import type { CommuterReportCategory, Place, Route, Stop } from "@/lib/api";
import { submitCommuterReport } from "@/lib/api";
import styles from "@/app/page.module.css";

const CATEGORIES: { value: CommuterReportCategory; label: string }[] = [
  { value: "ROUTE_NOT_SEEN", label: "Route not seen" },
  { value: "SERVICE_STOPPED", label: "Service stopped" },
  { value: "REROUTE", label: "Possible reroute" },
  { value: "STOP_BLOCKED", label: "Stop blocked" },
  { value: "FLOODING", label: "Flooding" },
  { value: "ROAD_CLOSURE", label: "Road closure" },
  { value: "TRANSFER_PROBLEM", label: "Transfer problem" },
  { value: "FARE_OBSERVATION", label: "Fare observation" },
  { value: "OTHER", label: "Other" },
];

type Props = { routes: Route[]; stops: Stop[]; places: Place[] };

export default function CommuterReportForm({ routes, stops, places }: Props) {
  const [category, setCategory] = useState<CommuterReportCategory>("OTHER");
  const [description, setDescription] = useState("");
  const [routeId, setRouteId] = useState("");
  const [stopId, setStopId] = useState("");
  const [placeId, setPlaceId] = useState("");
  const [attachLocation, setAttachLocation] = useState(false);
  const [lat, setLat] = useState("");
  const [lng, setLng] = useState("");
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setStatus(null);
    try {
      await submitCommuterReport({
        category,
        description: description.trim(),
        observed_at: new Date().toISOString(),
        ...(routeId ? { route_id: Number(routeId) } : {}),
        ...(stopId ? { stop_id: Number(stopId) } : {}),
        ...(placeId ? { place_id: Number(placeId) } : {}),
        ...(attachLocation && lat && lng ? { location: { lat: Number(lat), lng: Number(lng) } } : {}),
      });
      setDescription("");
      setStatus("Submitted for review. This report does not change route data automatically.");
    } catch {
      setStatus("The report could not be submitted right now.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className={styles.reportForm} onSubmit={submit}>
      <div className={styles.formGrid}>
        <label>What happened?
          <select value={category} onChange={(event) => setCategory(event.target.value as CommuterReportCategory)}>
            {CATEGORIES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label>Route (optional)
          <select value={routeId} onChange={(event) => setRouteId(event.target.value)}><option value="">Not specified</option>{routes.map((route) => <option key={route.id} value={route.id}>{route.code} · {route.name}</option>)}</select>
        </label>
        <label>Stop (optional)
          <select value={stopId} onChange={(event) => setStopId(event.target.value)}><option value="">Not specified</option>{stops.map((stop) => <option key={stop.id} value={stop.id}>{stop.name}</option>)}</select>
        </label>
        <label>Place (optional)
          <select value={placeId} onChange={(event) => setPlaceId(event.target.value)}><option value="">Not specified</option>{places.map((place) => <option key={place.id} value={place.id}>{place.name}</option>)}</select>
        </label>
      </div>
      <label>Short description
        <textarea required minLength={3} maxLength={1000} value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Share only what you observed." />
      </label>
      <label className={styles.checkboxLabel}><input type="checkbox" checked={attachLocation} onChange={(event) => setAttachLocation(event.target.checked)} /> Attach a location I enter (no live location is collected).</label>
      {attachLocation && <div className={styles.formGrid}><label>Latitude<input inputMode="decimal" value={lat} onChange={(event) => setLat(event.target.value)} /></label><label>Longitude<input inputMode="decimal" value={lng} onChange={(event) => setLng(event.target.value)} /></label></div>}
      <button type="submit" className={styles.secondaryButton} disabled={busy || description.trim().length < 3}>{busy ? "Submitting…" : "Send report for review"}</button>
      {status && <p className={styles.status} role="status">{status}</p>}
    </form>
  );
}
