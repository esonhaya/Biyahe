"use client";

import { useEffect, useState } from "react";
import { completeFieldSession, getFieldMissions, startFieldSession, syncFieldOutbox } from "@/lib/fieldApi";
import {
  FIELD_TOKEN_KEY,
  hasPendingFieldDrafts,
  loadFieldCache,
  loadFieldOutbox,
  queueFieldObservation,
  removeFieldOutbox,
  saveFieldCache,
  type FieldClaim,
  type FieldLocation,
  type FieldMission,
  type FieldObservationDraft,
  observedPrecisionLabel,
} from "@/lib/fieldMode";
import styles from "./FieldMode.module.css";

const OBSERVATION_TYPES = ["ROUTE_SEEN", "ROUTE_NOT_SEEN", "SIGNBOARD", "BOARDING_LOCATION", "ALIGHTING_LOCATION", "STOP_SERVED", "STOP_NOT_SERVED", "SEQUENCE", "DIRECTION", "TRANSFER", "FARE_OBSERVATION", "OTHER"];

function newId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
  return `field-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export default function FieldMode() {
  const [open, setOpen] = useState(false);
  const [online, setOnline] = useState(true);
  const [token, setToken] = useState("");
  const [missions, setMissions] = useState<FieldMission[]>([]);
  const [selectedMission, setSelectedMission] = useState<FieldMission | null>(null);
  const [selectedClaim, setSelectedClaim] = useState<FieldClaim | null>(null);
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [type, setType] = useState(OBSERVATION_TYPES[0]);
  const [precision, setPrecision] = useState("COULD_NOT_CONFIRM");
  const [factText, setFactText] = useState("");
  const [notes, setNotes] = useState("");
  const [location, setLocation] = useState<FieldLocation | undefined>();
  const [outboxCount, setOutboxCount] = useState(0);
  const [cachedAt, setCachedAt] = useState<string | null>(null);
  const [staleMissionSnapshot, setStaleMissionSnapshot] = useState(false);
  const [status, setStatus] = useState("Field Mode is closed. It requires an authorized field operator.");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const update = () => setOnline(window.navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => { window.removeEventListener("online", update); window.removeEventListener("offline", update); };
  }, []);

  const cache = typeof window === "undefined" ? { missions: [], cachedAt: null } : loadFieldCache(window.localStorage);

  async function loadMissions() {
    setBusy(true);
    try {
      const activeToken = token || (typeof window !== "undefined" ? window.sessionStorage.getItem(FIELD_TOKEN_KEY) ?? "" : "");
      if (!online) {
        setMissions(cache.missions);
        setCachedAt(cache.cachedAt);
        setStaleMissionSnapshot(cache.missions.length > 0);
        setStatus(cache.missions.length ? "Offline mission snapshot loaded. Claim status is not current." : "No mission snapshot is available offline.");
        return;
      }
      const loaded = await getFieldMissions(activeToken);
      setMissions(loaded);
      setCachedAt(new Date().toISOString());
      setStaleMissionSnapshot(false);
      saveFieldCache(window.localStorage, loaded);
      setStatus("Connected mission list loaded. No observation is accepted until it is submitted and reviewed.");
    } catch {
      setMissions(cache.missions);
      setCachedAt(cache.cachedAt);
      setStaleMissionSnapshot(cache.missions.length > 0);
      setStatus(cache.missions.length ? "Field API unavailable. Using a stale mission snapshot; new claims may have changed." : "Field API unavailable. Ask an admin for field-operator access.");
    } finally {
      setBusy(false);
    }
  }

  function toggleOpen() {
    const next = !open;
    setOpen(next);
    if (next) {
      try {
        setToken(window.sessionStorage.getItem(FIELD_TOKEN_KEY) ?? "");
        setOutboxCount(loadFieldOutbox(window.localStorage).length);
      } catch { /* browser storage is optional */ }
      void loadMissions();
    }
  }

  function saveToken(value: string) {
    setToken(value);
    try { window.sessionStorage.setItem(FIELD_TOKEN_KEY, value.slice(0, 300)); } catch { /* session storage is optional */ }
  }

  async function beginMission(mission: FieldMission) {
    setSelectedMission(mission);
    setSelectedClaim(mission.claims[0] ?? null);
    setFactText("");
    setNotes("");
    setLocation(undefined);
    if (mission.session?.id) {
      setSessionId(mission.session.id);
      setStatus(online ? "Resumed your assigned field session." : "Resumed the saved field session offline. Drafts stay on this device until sync.");
      return;
    }
    if (!online) { setStatus("Start this mission while online before collecting offline observations."); return; }
    setBusy(true);
    try {
      const response = await startFieldSession(token, mission.id);
      setSessionId(response.session_id);
      setSelectedMission(response.data);
      setSelectedClaim(response.data.claims[0] ?? null);
      setMissions((current) => current.map((item) => item.id === response.data.id ? response.data : item));
      saveFieldCache(window.localStorage, missions.map((item) => item.id === response.data.id ? response.data : item));
      setStatus("Field session started. Record only the selected claim and keep location capture deliberate.");
    } catch {
      setStatus("This mission could not start. Check field-operator authorization and connectivity.");
    } finally { setBusy(false); }
  }

  function captureLocation() {
    if (!navigator.geolocation) { setStatus("This browser does not provide foreground location. The observation can still be saved without it."); return; }
    setBusy(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocation({ lat: position.coords.latitude, lng: position.coords.longitude, accuracy_m: Math.min(Math.max(position.coords.accuracy || 0, 0), 5000) });
        setBusy(false);
        setStatus("One explicit observation location is attached. No continuous tracking is active.");
      },
      () => { setBusy(false); setStatus("Location was not captured. Nothing was stored and no permission is retried automatically."); },
      { enableHighAccuracy: false, maximumAge: 0, timeout: 10000 },
    );
  }

  async function submitObservation() {
    if (!selectedMission || !selectedClaim || !sessionId) { setStatus("Start a mission and choose a claim first."); return; }
    const draft: FieldObservationDraft = {
      stable_id: newId(),
      session_id: sessionId,
      claim_id: selectedClaim.stable_id,
      type,
      observed_at: new Date().toISOString(),
      fact: { description: factText.trim().slice(0, 1000) || null, observed_precision: precision },
      notes: notes.trim().slice(0, 2000) || null,
      ...(location ? { location, accuracy_m: location.accuracy_m } : {}),
      schema_version: 1,
    };
    try {
      queueFieldObservation(window.localStorage, draft);
      setOutboxCount(loadFieldOutbox(window.localStorage).length);
      setFactText("");
      setNotes("");
      setLocation(undefined);
      setStatus(online ? "Observation saved locally; attempting one bounded sync." : "Observation saved as a local draft. It has not been received by the server.");
      if (online) await syncOutbox();
    } catch {
      setStatus("This observation could not be stored. Browser storage may be full; no server claim was made.");
    }
  }

  async function syncOutbox(): Promise<boolean> {
    if (!online || !sessionId) return false;
    const items = loadFieldOutbox(window.localStorage).filter((item) => item.session_id === sessionId);
    if (!items.length) return true;
    setBusy(true);
    try {
      const response = await syncFieldOutbox(token, sessionId, items);
      const removed = [...response.data.accepted, ...response.data.idempotent];
      if (removed.length) removeFieldOutbox(window.localStorage, removed);
      const remaining = loadFieldOutbox(window.localStorage);
      setOutboxCount(remaining.length);
      const pendingForSession = hasPendingFieldDrafts(remaining, sessionId);
      setStatus(pendingForSession || response.data.rejected.length
        ? `${removed.length} observation(s) received; local drafts still need attention before this mission can be submitted.`
        : `${removed.length} observation(s) received by the server. Canonical transport facts are unchanged.`);
      return !pendingForSession && response.data.rejected.length === 0;
    } catch {
      setStatus("Sync did not complete. The local outbox remains; do not assume server receipt.");
      return false;
    } finally { setBusy(false); }
  }

  async function completeMission() {
    if (!sessionId || !online) { setStatus("Connect before submitting a completed mission. Local drafts remain available."); return; }
    const synced = await syncOutbox();
    if (!synced) {
      setStatus("Mission not submitted: local observations for this mission remain unsynced or need attention.");
      return;
    }
    setBusy(true);
    try { await completeFieldSession(token, sessionId); setStatus("Mission submitted for review. Submission does not promote any claim."); } catch { setStatus("The mission could not be submitted; observations remain in the session."); }
    finally { setBusy(false); }
  }

  return (
    <section className={styles.section} id="field-mode" aria-labelledby="field-mode-heading">
      <div className={styles.panel}>
        <div className={styles.heading}>
          <div><p className={styles.eyebrow}>Trusted collection only</p><h2 id="field-mode-heading">Field Verification Mode</h2></div>
          <button type="button" className={styles.toggle} onClick={toggleOpen} aria-expanded={open}>{open ? "Close field mode" : "Open field mode"}</button>
        </div>
        <p className={styles.copy}>Use a planned mission to record a narrow observation. Field Mode is separate from Ride Mode: it never turns commute GPS into evidence and never promotes facts automatically.</p>
        {open && <>
          <div className={`${styles.status} ${!online ? styles.statusWarn : ""}`} role="status">{online ? "ONLINE" : "OFFLINE SNAPSHOT"} · {status}</div>
          <div className={styles.actions}>
            <input className={styles.secondary} aria-label="Field operator token" type="password" value={token} onChange={(event) => saveToken(event.target.value)} placeholder="Field operator token (optional with account login)" />
            <button type="button" className={styles.secondary} onClick={() => void loadMissions()} disabled={busy}>Refresh missions</button>
          </div>
          {cachedAt && <p className={styles.small}>Last local mission snapshot: {new Date(cachedAt).toLocaleString()} · {outboxCount} local observation(s) waiting.</p>}
          <div className={styles.grid}>
            <div><h3 className={styles.subheading}>Planned missions</h3><div className={styles.missionList}>{missions.map((mission) => <button type="button" key={mission.id} className={`${styles.missionButton} ${selectedMission?.id === mission.id ? styles.selected : ""}`} onClick={() => void beginMission(mission)}><strong>{mission.title}</strong><span>{mission.area} · {mission.claims.length} claims · priority {mission.priority}</span></button>)}{!missions.length && <p className={styles.small}>No missions are available in this snapshot.</p>}</div></div>
            <div>{selectedMission && <><h3 className={styles.subheading}>{selectedMission.title}</h3><p className={styles.small}>{selectedMission.instructions}</p><p className={styles.small}><strong>Safety:</strong> {selectedMission.safety_notes}</p>{staleMissionSnapshot && <p className={styles.statusWarn} role="status">Offline mission snapshot{cachedAt ? ` from ${new Date(cachedAt).toLocaleString()}` : ""}. Claim status may have changed; verify before recording an observation.</p>}<div className={styles.checklist}>{selectedMission.claims.slice(0, 120).map((claim) => <button type="button" key={claim.stable_id} className={`${styles.claimButton} ${selectedClaim?.stable_id === claim.stable_id ? styles.selected : ""}`} onClick={() => setSelectedClaim(claim)}><strong>{claim.claim_type.replaceAll("_", " ")}</strong><span>{claim.prompt}</span></button>)}</div><div className={styles.form}><label>Observation type<select value={type} onChange={(event) => setType(event.target.value)}>{OBSERVATION_TYPES.map((item) => <option key={item}>{item.replaceAll("_", " ")}</option>)}</select></label><label>Observed precision<select value={precision} onChange={(event) => setPrecision(event.target.value)}><option value="COULD_NOT_CONFIRM">{observedPrecisionLabel("COULD_NOT_CONFIRM")}</option><option value="BOARDING_AREA_OBSERVED">{observedPrecisionLabel("BOARDING_AREA_OBSERVED")}</option><option value="EXACT_POINT_OBSERVED">{observedPrecisionLabel("EXACT_POINT_OBSERVED")}</option><option value="APPROXIMATE_ONLY">{observedPrecisionLabel("APPROXIMATE_ONLY")}</option></select></label><label>Structured note<textarea value={factText} onChange={(event) => setFactText(event.target.value)} maxLength={1000} placeholder="Signboard, direction, sequence relation, boarding area, or other narrow fact" /></label><label>Additional notes<textarea value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={2000} placeholder="Safety/context note; do not include unrelated personal information" /></label><div className={styles.actions}><button type="button" className={styles.secondary} onClick={captureLocation} disabled={busy}>Attach one foreground location</button><button type="button" className={styles.primary} onClick={() => void submitObservation()} disabled={busy}>Save observation</button><button type="button" className={styles.secondary} onClick={() => void syncOutbox()} disabled={busy || !online}>Sync outbox</button><button type="button" className={styles.secondary} onClick={() => void completeMission()} disabled={busy || !online}>Submit mission for review</button></div>{location && <p className={styles.small}>One location attached · accuracy {Math.round(location.accuracy_m)} m · reviewer will not treat poor accuracy as exact.</p>}</div></>}</div>
          </div>
        </>}
      </div>
    </section>
  );
}
