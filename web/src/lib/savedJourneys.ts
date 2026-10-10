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
export type SavedJourneyStoreRead = {
  journeys: SavedJourney[];
  writable: boolean;
  problem: string | null;
};

const LEG_TYPES = new Set(["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"]);
const VERIFICATION_STATES = new Set(["DRAFT", "UNVERIFIED", "VERIFIED", "STALE", "RETIRED"]);
const DISTANCE_TYPES = new Set(["PEDESTRIAN_NETWORK", "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS", "STRAIGHT_LINE_ESTIMATE", "NOT_REQUIRED", "UNAVAILABLE"]);
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

function isVerification(value: unknown): boolean {
  return isRecord(value)
    && typeof value.state === "string"
    && VERIFICATION_STATES.has(value.state)
    && typeof value.is_demo === "boolean"
    && typeof value.trusted === "boolean";
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
    && (value.type === "PLACE" || value.type === "STOP")
    && typeof value.name === "string"
    && value.name.length <= 500
    && (value.location === null || value.location === undefined || isLocation(value.location))
    && isVerification(value.verification);
}

function containsForbiddenKey(value: unknown, depth = 0): boolean {
  if (depth > 28) return true;
  if (Array.isArray(value)) return value.some((child) => containsForbiddenKey(child, depth + 1));
  if (!isRecord(value)) return false;
  for (const [key, child] of Object.entries(value)) {
    if (FORBIDDEN_SNAPSHOT_KEYS.has(key)) return true;
    if (containsForbiddenKey(child, depth + 1)) return true;
  }
  return false;
}

function isNullableString(value: unknown): boolean {
  return value === null || typeof value === "string";
}

function isNullableFiniteNumber(value: unknown): boolean {
  return value === null || (typeof value === "number" && Number.isFinite(value) && value >= 0);
}

function isGeometry(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  if (!isRecord(value) || !Array.isArray(value.coordinates)) return false;
  const validCoordinate = (coordinate: unknown) => Array.isArray(coordinate)
    && coordinate.length >= 2
    && coordinate.length <= 3
    && coordinate.slice(0, 2).every((part) => typeof part === "number" && Number.isFinite(part))
    && (coordinate[0] as number) >= -180 && (coordinate[0] as number) <= 180
    && (coordinate[1] as number) >= -90 && (coordinate[1] as number) <= 90;
  if (value.type === "LineString") {
    return value.coordinates.length >= 2 && value.coordinates.length <= 20_000
      && value.coordinates.every(validCoordinate);
  }
  if (value.type === "MultiLineString") {
    return value.coordinates.length > 0 && value.coordinates.length <= 1_000
      && value.coordinates.every((line) => Array.isArray(line) && line.length >= 2
        && line.length <= 20_000 && line.every(validCoordinate));
  }
  return false;
}

function isOptionalVerification(value: unknown): boolean {
  return value === null || value === undefined || isVerification(value);
}

function isOptionalJourneyPoint(value: unknown): boolean {
  return value === null || value === undefined || isJourneyPoint(value);
}

function isJourneyLegSnapshot(value: unknown): boolean {
  if (!isRecord(value) || typeof value.type !== "string" || !LEG_TYPES.has(value.type)
    || value.offline_walking_preview !== undefined
    || !isJourneyPoint(value.origin) || !isJourneyPoint(value.destination)
    || !Array.isArray(value.warnings) || !value.warnings.every((warning) => typeof warning === "string")
    || !isNullableFiniteNumber(value.distance_meters)
    || typeof value.distance_is_estimate !== "boolean"
    || !(value.distance_type === null || value.distance_type === undefined
      || (typeof value.distance_type === "string" && DISTANCE_TYPES.has(value.distance_type)))
    || !isNullableString(value.routing_status)
    || !isNullableString(value.routing_source)
    || !isGeometry(value.geometry)
    || !isOptionalJourneyPoint(value.boarding_stop)
    || !isOptionalJourneyPoint(value.alighting_stop)
    || !isOptionalVerification(value.boarding_association)
    || !isOptionalVerification(value.alighting_association)) return false;

  if (value.route !== null && value.route !== undefined
    && (!isRecord(value.route) || typeof value.route.code !== "string"
      || typeof value.route.name !== "string" || !isVerification(value.route.verification))) return false;
  if (value.pattern !== null && value.pattern !== undefined
    && (!isRecord(value.pattern) || typeof value.pattern.direction !== "string"
      || !isVerification(value.pattern.verification))) return false;
  return true;
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
    || !Number.isInteger(value.transfer_count)
    || (value.transfer_count as number) < 0
    || (value.transfer_count as number) > 20
    || !isRecord(value.ranking)
    || typeof value.ranking.walking_distance_meters !== "number"
    || !Number.isFinite(value.ranking.walking_distance_meters)
    || !Number.isInteger(value.ranking.ride_stop_count)
    || typeof value.ranking.policy !== "string"
    || !Array.isArray(value.ranking.reasons)
    || !value.ranking.reasons.every((reason) => typeof reason === "string")
    || !isRecord(value.trust)
    || !["VERIFIED", "MIXED", "UNVERIFIED"].includes(String(value.trust.state))
    || !isNullableString(value.trust.warning)
    || !Array.isArray(value.warnings)
    || !value.warnings.every((warning) => typeof warning === "string")
    || !isRecord(value.fare)
    || !["AVAILABLE", "UNAVAILABLE"].includes(String(value.fare.status))
    || typeof value.fare.label !== "string"
    || !isNullableFiniteNumber(value.fare.amount)
    || !isNullableString(value.fare.currency)
    || value.offline_walking_preview !== undefined
    || containsForbiddenKey(value)) {
    return false;
  }

  return value.legs.every(isJourneyLegSnapshot);
}

function isSavedJourney(value: unknown): value is SavedJourney {
  return isRecord(value)
    && value.version === SAVED_JOURNEY_SCHEMA_VERSION
    && typeof value.id === "string"
    && value.id.length > 0
    && typeof value.savedAt === "string"
    && Number.isFinite(Date.parse(value.savedAt))
    && isJourneySnapshot(value.journey)
    && value.id === value.journey.id;
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

function inspectSavedJourneyValue(raw: string | null): SavedJourneyStoreRead {
  if (raw === null) return { journeys: [], writable: true, problem: null };
  if (raw.length > MAX_TOTAL_STORAGE_CHARS) {
    return { journeys: [], writable: false, problem: "Saved journey data is larger than the safe read limit. Existing local data was preserved; saves and deletes are disabled." };
  }
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isRecord(parsed) || parsed.version !== SAVED_JOURNEY_SCHEMA_VERSION || !Array.isArray(parsed.journeys)) {
      return { journeys: [], writable: false, problem: "Saved journey data uses an unreadable format. Existing local data was preserved; saves and deletes are disabled." };
    }
    if (parsed.journeys.length > MAX_SAVED_JOURNEYS
      || !parsed.journeys.every((saved) => isSavedJourney(saved) && snapshotSize(saved) <= MAX_SNAPSHOT_CHARS)) {
      return { journeys: [], writable: false, problem: "One or more saved journeys could not be read safely. Existing local data was preserved; saves and deletes are disabled." };
    }
    const journeys = parsed.journeys as SavedJourney[];
    const savedIds = new Set<string>();
    const containsDuplicateId = journeys.some((saved) => {
      if (savedIds.has(saved.id)) return true;
      savedIds.add(saved.id);
      return false;
    });
    if (containsDuplicateId) {
      return {
        journeys,
        writable: false,
        problem: "Saved journey data contains duplicate records for the same journey. Existing local data was preserved; back it up before clearing or repairing it.",
      };
    }
    return { journeys, writable: true, problem: null };
  } catch {
    return { journeys: [], writable: false, problem: "Saved journey data could not be parsed. Existing local data was preserved; saves and deletes are disabled." };
  }
}

export function readSavedJourneyStore(storage: StorageLike): SavedJourneyStoreRead {
  try {
    return inspectSavedJourneyValue(storage.getItem(SAVED_JOURNEYS_STORAGE_KEY));
  } catch {
    return { journeys: [], writable: false, problem: "Saved journey storage could not be read. Existing local data was preserved; saves and deletes are disabled." };
  }
}

export function parseSavedJourneys(raw: string | null): SavedJourney[] {
  const result = inspectSavedJourneyValue(raw);
  return result.writable ? result.journeys : [];
}

export function loadSavedJourneys(storage: StorageLike): SavedJourney[] {
  return readSavedJourneyStore(storage).journeys;
}

export function serializeSavedJourneys(journeys: SavedJourney[]): string {
  if (journeys.length > MAX_SAVED_JOURNEYS
    || !journeys.every((saved) => isSavedJourney(saved) && snapshotSize(saved) <= MAX_SNAPSHOT_CHARS)) {
    throw new Error("Saved journeys contain unreadable or oversized records; existing data was not replaced.");
  }
  if (new Set(journeys.map((saved) => saved.id)).size !== journeys.length) {
    throw new Error("Saved journeys contain duplicate records for the same journey.");
  }
  const envelope: SavedJourneyEnvelope = { version: SAVED_JOURNEY_SCHEMA_VERSION, journeys };
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
  const existing = readSavedJourneyStore(storage);
  if (!existing.writable) {
    throw new Error(existing.problem ?? "Saved journey data cannot be replaced safely.");
  }
  storage.setItem(SAVED_JOURNEYS_STORAGE_KEY, serializeSavedJourneys(journeys));
}

export const savedJourneyLimits = Object.freeze({
  maxJourneys: MAX_SAVED_JOURNEYS,
  maxSnapshotChars: MAX_SNAPSHOT_CHARS,
  maxTotalChars: MAX_TOTAL_STORAGE_CHARS,
});
