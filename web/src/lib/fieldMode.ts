import type { LocationPoint } from "./api";

export const FIELD_MODE_SCHEMA_VERSION = 1;
export const FIELD_CACHE_KEY = "biyahe.field-missions.v1";
export const FIELD_OUTBOX_KEY = "biyahe.field-outbox.v1";
export const FIELD_TOKEN_KEY = "biyahe.field-operator-session.v1";
export const MAX_FIELD_MISSIONS = 8;
export const MAX_FIELD_CLAIMS_PER_MISSION = 120;
export const MAX_FIELD_OUTBOX = 50;
export const MAX_FIELD_TEXT = 2000;

export type FieldClaim = {
  id: number;
  stable_id: string;
  claim_type: string;
  target_type: string;
  target_stable_id: string;
  prompt: string;
  status: string;
  priority_score: number;
  journeys_affected: number;
  affected_od_pairs: string[];
  route_code: string | null;
  direction: string | null;
  stop_name: string | null;
  pattern_stable_id: string | null;
  stop_stable_id: string | null;
  research_status: string | null;
};

export type FieldMission = {
  id: number;
  stable_id: string;
  title: string;
  area: string;
  status: string;
  priority: number;
  route_scope: string[];
  instructions: string | null;
  safety_notes: string | null;
  claim_checklist: string[];
  claims: FieldClaim[];
  session: { id: number; stable_id: string; status: string; observation_count: number } | null;
  offline_note: string;
  schema_version: number;
};

export type FieldLocation = LocationPoint & { accuracy_m: number };

export type FieldObservationDraft = {
  stable_id: string;
  session_id: number;
  claim_id: string;
  type: string;
  observed_at: string;
  fact: Record<string, string | number | boolean | null>;
  notes: string | null;
  location?: FieldLocation;
  accuracy_m?: number;
  schema_version: number;
};

type FieldCacheEnvelope = { version: 1; cached_at: string; missions: FieldMission[] };
type FieldOutboxEnvelope = { version: 1; items: FieldObservationDraft[] };
export type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem">;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function boundedText(value: unknown, max = MAX_FIELD_TEXT): value is string {
  return typeof value === "string" && value.length <= max;
}

function isLocation(value: unknown): value is FieldLocation {
  return isRecord(value)
    && typeof value.lat === "number" && Number.isFinite(value.lat) && value.lat >= -90 && value.lat <= 90
    && typeof value.lng === "number" && Number.isFinite(value.lng) && value.lng >= -180 && value.lng <= 180
    && typeof value.accuracy_m === "number" && Number.isFinite(value.accuracy_m) && value.accuracy_m >= 0 && value.accuracy_m <= 5000;
}

function isClaim(value: unknown): value is FieldClaim {
  return isRecord(value)
    && Number.isInteger(value.id) && typeof value.stable_id === "string"
    && boundedText(value.prompt) && typeof value.claim_type === "string"
    && typeof value.target_type === "string" && typeof value.target_stable_id === "string"
    && typeof value.status === "string" && typeof value.priority_score === "number"
    && typeof value.journeys_affected === "number" && Array.isArray(value.affected_od_pairs)
    && value.affected_od_pairs.every((item) => typeof item === "string")
    && (value.route_code === null || typeof value.route_code === "string")
    && (value.direction === null || typeof value.direction === "string")
    && (value.stop_name === null || typeof value.stop_name === "string")
    && (value.pattern_stable_id === null || typeof value.pattern_stable_id === "string")
    && (value.stop_stable_id === null || typeof value.stop_stable_id === "string")
    && (value.research_status === null || typeof value.research_status === "string");
}

function isMission(value: unknown): value is FieldMission {
  return isRecord(value)
    && Number.isInteger(value.id) && typeof value.stable_id === "string"
    && boundedText(value.title, 180) && boundedText(value.area, 180)
    && typeof value.status === "string" && typeof value.priority === "number"
    && Array.isArray(value.route_scope) && value.route_scope.every((item) => typeof item === "string")
    && (value.instructions === null || boundedText(value.instructions))
    && (value.safety_notes === null || boundedText(value.safety_notes))
    && Array.isArray(value.claim_checklist) && value.claim_checklist.every((item) => typeof item === "string")
    && Array.isArray(value.claims) && value.claims.length <= MAX_FIELD_CLAIMS_PER_MISSION && value.claims.every(isClaim)
    && (value.session === null || (isRecord(value.session) && Number.isInteger(value.session.id) && typeof value.session.stable_id === "string" && typeof value.session.status === "string" && typeof value.session.observation_count === "number"))
    && typeof value.offline_note === "string" && value.schema_version === FIELD_MODE_SCHEMA_VERSION;
}

export function loadFieldCache(storage: StorageLike): { missions: FieldMission[]; cachedAt: string | null } {
  try {
    const parsed: unknown = JSON.parse(storage.getItem(FIELD_CACHE_KEY) ?? "null");
    if (!isRecord(parsed) || parsed.version !== FIELD_MODE_SCHEMA_VERSION || !Array.isArray(parsed.missions)) return { missions: [], cachedAt: null };
    const missions = parsed.missions.filter(isMission).slice(0, MAX_FIELD_MISSIONS);
    return { missions, cachedAt: typeof parsed.cached_at === "string" ? parsed.cached_at : null };
  } catch {
    return { missions: [], cachedAt: null };
  }
}

export function saveFieldCache(storage: StorageLike, missions: FieldMission[], cachedAt = new Date().toISOString()): void {
  const bounded = missions.filter(isMission).slice(0, MAX_FIELD_MISSIONS);
  const value: FieldCacheEnvelope = { version: FIELD_MODE_SCHEMA_VERSION, cached_at: cachedAt, missions: bounded };
  const serialized = JSON.stringify(value);
  if (serialized.length > 220_000) throw new Error("Field mission cache is too large.");
  storage.setItem(FIELD_CACHE_KEY, serialized);
}

function isObservation(value: unknown): value is FieldObservationDraft {
  return isRecord(value)
    && typeof value.stable_id === "string" && value.stable_id.length >= 8 && value.stable_id.length <= 150
    && typeof value.session_id === "number" && Number.isInteger(value.session_id) && value.session_id > 0
    && typeof value.claim_id === "string" && value.claim_id.length > 0 && value.claim_id.length <= 150
    && typeof value.type === "string" && typeof value.observed_at === "string" && Number.isFinite(Date.parse(value.observed_at))
    && isRecord(value.fact) && Object.keys(value.fact).length <= 30
    && (value.notes === null || boundedText(value.notes))
    && (value.location === undefined || isLocation(value.location))
    && (value.accuracy_m === undefined || (typeof value.accuracy_m === "number" && value.accuracy_m >= 0 && value.accuracy_m <= 5000))
    && value.schema_version === FIELD_MODE_SCHEMA_VERSION;
}

export function loadFieldOutbox(storage: StorageLike): FieldObservationDraft[] {
  try {
    const parsed: unknown = JSON.parse(storage.getItem(FIELD_OUTBOX_KEY) ?? "null");
    if (!isRecord(parsed) || parsed.version !== FIELD_MODE_SCHEMA_VERSION || !Array.isArray(parsed.items)) return [];
    return parsed.items.filter(isObservation).slice(0, MAX_FIELD_OUTBOX);
  } catch {
    return [];
  }
}

export function saveFieldOutbox(storage: StorageLike, items: FieldObservationDraft[]): void {
  const bounded = items.filter(isObservation).slice(0, MAX_FIELD_OUTBOX);
  const serialized = JSON.stringify({ version: FIELD_MODE_SCHEMA_VERSION, items: bounded } satisfies FieldOutboxEnvelope);
  if (serialized.length > 180_000) throw new Error("Field outbox is full.");
  storage.setItem(FIELD_OUTBOX_KEY, serialized);
}

export function queueFieldObservation(storage: StorageLike, item: FieldObservationDraft): FieldObservationDraft[] {
  if (!isObservation(item)) throw new Error("Invalid field observation draft.");
  const current = loadFieldOutbox(storage);
  const duplicate = current.find((entry) => entry.stable_id === item.stable_id);
  if (duplicate && JSON.stringify(duplicate) !== JSON.stringify(item)) throw new Error("This submission ID already contains another observation.");
  const next = duplicate ? current : [item, ...current];
  saveFieldOutbox(storage, next);
  return next;
}

export function removeFieldOutbox(storage: StorageLike, stableIds: string[]): FieldObservationDraft[] {
  const blocked = new Set(stableIds);
  const next = loadFieldOutbox(storage).filter((item) => !blocked.has(item.stable_id));
  saveFieldOutbox(storage, next);
  return next;
}

export function observedPrecisionLabel(value: string): string {
  if (value === "EXACT_POINT_OBSERVED") return "Exact point observed (review may downgrade)";
  if (value === "BOARDING_AREA_OBSERVED") return "Boarding area observed";
  if (value === "APPROXIMATE_ONLY") return "Approximate only";
  return "Could not confirm";
}
