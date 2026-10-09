import type { Journey, JourneyPoint } from "./api";

export const SAVED_JOURNEYS_STORAGE_KEY = "biyahe.saved-journeys.v1";
export const SAVED_JOURNEY_SCHEMA_VERSION = 1;
export const MAX_SAVED_JOURNEYS = 20;
const MAX_SNAPSHOT_CHARS = 900_000;
const MAX_TOTAL_STORAGE_CHARS = 4_000_000;

export type SavedJourney = {
  version: 1;
  id: string;
  savedAt: string;
  journey: Journey;
};

type SavedJourneyEnvelope = {
  version: 1;
  journeys: SavedJourney[];
};

export type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem">;

const LEG_TYPES = new Set(["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"]);
const FORBIDDEN_SNAPSHOT_KEYS = new Set([
  "currentLocation",
  "locationSamples",
  "rawGps",
  "gpsHistory",
  "movementTrace",
  "deviceCoordinates",
  "rideLocationSamples",
]);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isLocation(value: unknown): boolean {
  return isRecord(value)
    && typeof value.lat === "number"
    && Number.isFinite(value.lat)
    && value.lat >= -90
    && value.lat <= 90
    && typeof value.lng === "number"
    && Number.isFinite(value.lng)
    && value.lng >= -180
    && value.lng <= 180;
}

function isJourneyPoint(value: unknown): value is JourneyPoint {
  return isRecord(value)
    && typeof value.id === "number"
    && Number.isFinite(value.id)
    && typeof value.name === "string"
    && value.name.length <= 500
    && (value.location === null || value.location === undefined || isLocation(value.location));
}

function containsForbiddenKey(value: unknown, depth = 0): boolean {
  if (depth > 28 || !isRecord(value)) return false;
  for (const [key, child] of Object.entries(value)) {
    if (FORBIDDEN_SNAPSHOT_KEYS.has(key)) return true;
    if (containsForbiddenKey(child, depth + 1)) return true;
  }
  return false;
}

export function isJourneySnapshot(value: unknown): value is Journey {
  if (!isRecord(value)
    || typeof value.id !== "string"
    || value.id.length === 0
    || value.id.length > 240
    || !isJourneyPoint(value.origin)
    || !isJourneyPoint(value.destination)
    || !Array.isArray(value.legs)
    || value.legs.length === 0
    || value.legs.length > 30
    || value.offline_walking_preview !== undefined
    || containsForbiddenKey(value)) {
    return false;
  }

  return value.legs.every((leg: unknown) => {
    if (!isRecord(leg) || typeof leg.type !== "string" || !LEG_TYPES.has(leg.type)
      || leg.offline_walking_preview !== undefined) return false;
    return isJourneyPoint(leg.origin) && isJourneyPoint(leg.destination);
  });
}

function isSavedJourney(value: unknown): value is SavedJourney {
  return isRecord(value)
    && value.version === SAVED_JOURNEY_SCHEMA_VERSION
    && typeof value.id === "string"
    && value.id.length > 0
    && typeof value.savedAt === "string"
    && Number.isFinite(Date.parse(value.savedAt))
    && isJourneySnapshot(value.journey);
}

export function savedSnapshotAgeLabel(savedAt: string, now = Date.now()): string {
  const timestamp = Date.parse(savedAt);
  if (!Number.isFinite(timestamp)) return "saved date unavailable";
  const ageMs = Math.max(0, now - timestamp);
  if (ageMs < 60 * 60 * 1000) return "saved less than an hour ago";
  if (ageMs < 24 * 60 * 60 * 1000) return "saved less than a day ago";
  const ageDays = Math.floor(ageMs / (24 * 60 * 60 * 1000));
  return `saved ${ageDays} day${ageDays === 1 ? "" : "s"} ago`;
}

function snapshotSize(value: SavedJourney): number {
  return JSON.stringify(value).length;
}

export function createSavedJourney(journey: Journey, savedAt = new Date().toISOString()): SavedJourney {
  if (journey.offline_walking_preview || journey.legs.some((leg) => leg.offline_walking_preview)) {
    throw new Error("Remove the development-only local graph preview before saving this journey.");
  }
  return {
    version: SAVED_JOURNEY_SCHEMA_VERSION,
    id: journey.id,
    savedAt,
    journey,
  };
}

export function parseSavedJourneys(raw: string | null): SavedJourney[] {
  if (!raw || raw.length > MAX_TOTAL_STORAGE_CHARS) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isRecord(parsed) || parsed.version !== SAVED_JOURNEY_SCHEMA_VERSION || !Array.isArray(parsed.journeys)) return [];
    return parsed.journeys
      .filter(isSavedJourney)
      .filter((saved) => snapshotSize(saved) <= MAX_SNAPSHOT_CHARS)
      .slice(0, MAX_SAVED_JOURNEYS);
  } catch {
    return [];
  }
}

export function loadSavedJourneys(storage: StorageLike): SavedJourney[] {
  try {
    return parseSavedJourneys(storage.getItem(SAVED_JOURNEYS_STORAGE_KEY));
  } catch {
    return [];
  }
}

export function serializeSavedJourneys(journeys: SavedJourney[]): string {
  const valid = journeys
    .filter(isSavedJourney)
    .filter((saved) => snapshotSize(saved) <= MAX_SNAPSHOT_CHARS)
    .slice(0, MAX_SAVED_JOURNEYS);
  const envelope: SavedJourneyEnvelope = { version: SAVED_JOURNEY_SCHEMA_VERSION, journeys: valid };
  const serialized = JSON.stringify(envelope);
  if (serialized.length > MAX_TOTAL_STORAGE_CHARS) {
    throw new Error("Saved journeys exceed the local storage limit.");
  }
  return serialized;
}

export function upsertSavedJourney(existing: SavedJourney[], journey: Journey, savedAt = new Date().toISOString()): SavedJourney[] {
  const saved = createSavedJourney(journey, savedAt);
  if (snapshotSize(saved) > MAX_SNAPSHOT_CHARS) {
    throw new Error("This journey snapshot is too large to save safely.");
  }
  return [saved, ...existing.filter((item) => item.id !== saved.id)].slice(0, MAX_SAVED_JOURNEYS);
}

export function removeSavedJourney(existing: SavedJourney[], id: string): SavedJourney[] {
  return existing.filter((saved) => saved.id !== id);
}

export function persistSavedJourneys(storage: StorageLike, journeys: SavedJourney[]): void {
  storage.setItem(SAVED_JOURNEYS_STORAGE_KEY, serializeSavedJourneys(journeys));
}

export const savedJourneyLimits = Object.freeze({
  maxJourneys: MAX_SAVED_JOURNEYS,
  maxSnapshotChars: MAX_SNAPSHOT_CHARS,
  maxTotalChars: MAX_TOTAL_STORAGE_CHARS,
});
