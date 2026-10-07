import type { City, Place } from "./api";

/**
 * Local-only daily commute helpers. These snapshots contain place labels and
 * ids only; they deliberately have no location samples, device identifiers,
 * or server-side history counterpart.
 */
export const PERSONAL_COMMUTE_SCHEMA_VERSION = 1;
export const RECENT_COMMUTES_STORAGE_KEY = "biyahe.recent-commutes.v1";
export const FAVORITE_PLACES_STORAGE_KEY = "biyahe.favorite-places.v1";
export const FAVORITE_COMMUTES_STORAGE_KEY = "biyahe.favorite-commutes.v1";
export const MAX_RECENT_COMMUTES = 8;
export const MAX_FAVORITE_PLACES = 12;
export const MAX_FAVORITE_COMMUTES = 12;
const MAX_STORAGE_CHARS = 180_000;

export type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem">;

export type PlaceSnapshot = {
  id: number;
  name: string;
  slug: string;
  category: string;
  location: Place["location"];
  city?: Pick<City, "id" | "name" | "slug" | "country_code">;
  verification: Place["verification"];
};

export type RecentCommute = {
  id: string;
  origin: PlaceSnapshot;
  destination: PlaceSnapshot;
  usedAt: string;
};

export type FavoritePlace = {
  id: string;
  label: string;
  place: PlaceSnapshot;
  savedAt: string;
};

export type FavoriteCommute = {
  id: string;
  label: string;
  origin: PlaceSnapshot;
  destination: PlaceSnapshot;
  savedAt: string;
};

type Envelope<T> = {
  version: 1;
  items: T[];
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isLocation(value: unknown): value is Place["location"] {
  return value === null || (isRecord(value)
    && typeof value.lat === "number"
    && Number.isFinite(value.lat)
    && value.lat >= -90
    && value.lat <= 90
    && typeof value.lng === "number"
    && Number.isFinite(value.lng)
    && value.lng >= -180
    && value.lng <= 180);
}

function isVerification(value: unknown): value is Place["verification"] {
  return isRecord(value)
    && ["DRAFT", "UNVERIFIED", "VERIFIED", "STALE", "RETIRED"].includes(String(value.state))
    && typeof value.is_demo === "boolean"
    && typeof value.trusted === "boolean";
}

function isPlaceSnapshot(value: unknown): value is PlaceSnapshot {
  if (!isRecord(value)
    || typeof value.id !== "number"
    || !Number.isInteger(value.id)
    || value.id < 1
    || typeof value.name !== "string"
    || value.name.length === 0
    || value.name.length > 240
    || typeof value.slug !== "string"
    || value.slug.length === 0
    || value.slug.length > 240
    || typeof value.category !== "string"
    || value.category.length > 100
    || !isLocation(value.location)
    || !isVerification(value.verification)) {
    return false;
  }

  if (value.city !== undefined && (!isRecord(value.city)
    || typeof value.city.id !== "number"
    || typeof value.city.name !== "string"
    || typeof value.city.slug !== "string"
    || typeof value.city.country_code !== "string")) {
    return false;
  }

  return true;
}

function isDate(value: unknown): value is string {
  return typeof value === "string" && Number.isFinite(Date.parse(value));
}

function validLabel(value: unknown): value is string {
  return typeof value === "string" && value.trim().length > 0 && value.trim().length <= 60;
}

function isRecentCommute(value: unknown): value is RecentCommute {
  return isRecord(value)
    && typeof value.id === "string"
    && value.id.length <= 80
    && isPlaceSnapshot(value.origin)
    && isPlaceSnapshot(value.destination)
    && isDate(value.usedAt);
}

function isFavoritePlace(value: unknown): value is FavoritePlace {
  return isRecord(value)
    && typeof value.id === "string"
    && value.id.length <= 80
    && validLabel(value.label)
    && isPlaceSnapshot(value.place)
    && isDate(value.savedAt);
}

function isFavoriteCommute(value: unknown): value is FavoriteCommute {
  return isRecord(value)
    && typeof value.id === "string"
    && value.id.length <= 100
    && validLabel(value.label)
    && isPlaceSnapshot(value.origin)
    && isPlaceSnapshot(value.destination)
    && isDate(value.savedAt);
}

function parseItems<T>(raw: string | null, isItem: (value: unknown) => value is T, maximum: number): T[] {
  if (!raw || raw.length > MAX_STORAGE_CHARS) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isRecord(parsed) || parsed.version !== PERSONAL_COMMUTE_SCHEMA_VERSION || !Array.isArray(parsed.items)) return [];
    return parsed.items.filter(isItem).slice(0, maximum);
  } catch {
    return [];
  }
}

function serializeItems<T>(items: T[], isItem: (value: unknown) => value is T, maximum: number): string {
  const serialized = JSON.stringify({
    version: PERSONAL_COMMUTE_SCHEMA_VERSION,
    items: items.filter(isItem).slice(0, maximum),
  } satisfies Envelope<T>);
  if (serialized.length > MAX_STORAGE_CHARS) throw new Error("Personal commute data exceeds the local storage limit.");
  return serialized;
}

export function placeSnapshot(place: Place): PlaceSnapshot {
  return {
    id: place.id,
    name: place.name,
    slug: place.slug,
    category: place.category,
    location: place.location,
    city: place.city ? {
      id: place.city.id,
      name: place.city.name,
      slug: place.city.slug,
      country_code: place.city.country_code,
    } : undefined,
    verification: place.verification,
  };
}

export function restorePlace(snapshot: PlaceSnapshot): Place {
  return {
    id: snapshot.id,
    name: snapshot.name,
    slug: snapshot.slug,
    description: null,
    category: snapshot.category,
    location: snapshot.location,
    city: snapshot.city ? {
      ...snapshot.city,
      is_active: true,
      verification: { state: snapshot.verification.state, is_demo: snapshot.verification.is_demo, trusted: snapshot.verification.trusted },
    } : undefined,
    aliases: [],
    verification: snapshot.verification,
  };
}

function pairId(origin: Place, destination: Place): string {
  return `${origin.id}:${destination.id}`;
}

export function parseRecentCommutes(raw: string | null): RecentCommute[] {
  return parseItems(raw, isRecentCommute, MAX_RECENT_COMMUTES);
}

export function parseFavoritePlaces(raw: string | null): FavoritePlace[] {
  return parseItems(raw, isFavoritePlace, MAX_FAVORITE_PLACES);
}

export function parseFavoriteCommutes(raw: string | null): FavoriteCommute[] {
  return parseItems(raw, isFavoriteCommute, MAX_FAVORITE_COMMUTES);
}

export function loadRecentCommutes(storage: StorageLike): RecentCommute[] {
  try { return parseRecentCommutes(storage.getItem(RECENT_COMMUTES_STORAGE_KEY)); } catch { return []; }
}

export function loadFavoritePlaces(storage: StorageLike): FavoritePlace[] {
  try { return parseFavoritePlaces(storage.getItem(FAVORITE_PLACES_STORAGE_KEY)); } catch { return []; }
}

export function loadFavoriteCommutes(storage: StorageLike): FavoriteCommute[] {
  try { return parseFavoriteCommutes(storage.getItem(FAVORITE_COMMUTES_STORAGE_KEY)); } catch { return []; }
}

export function recordRecentCommute(existing: RecentCommute[], origin: Place, destination: Place, usedAt = new Date().toISOString()): RecentCommute[] {
  const item: RecentCommute = { id: pairId(origin, destination), origin: placeSnapshot(origin), destination: placeSnapshot(destination), usedAt };
  return [item, ...existing.filter((candidate) => candidate.id !== item.id)].slice(0, MAX_RECENT_COMMUTES);
}

export function recordRecentCommuteForStorage(storage: StorageLike, origin: Place, destination: Place): void {
  const current = loadRecentCommutes(storage);
  persistRecentCommutes(storage, recordRecentCommute(current, origin, destination));
}

export function upsertFavoritePlace(existing: FavoritePlace[], place: Place, label: string, savedAt = new Date().toISOString()): FavoritePlace[] {
  const cleanLabel = label.trim().slice(0, 60);
  if (!cleanLabel) throw new Error("A favorite place needs a label.");
  const item: FavoritePlace = { id: `place:${place.id}`, label: cleanLabel, place: placeSnapshot(place), savedAt };
  return [item, ...existing.filter((candidate) => candidate.place.id !== place.id)].slice(0, MAX_FAVORITE_PLACES);
}

export function upsertFavoriteCommute(existing: FavoriteCommute[], origin: Place, destination: Place, label: string, savedAt = new Date().toISOString()): FavoriteCommute[] {
  const cleanLabel = label.trim().slice(0, 60);
  if (!cleanLabel) throw new Error("A favorite commute needs a label.");
  const item: FavoriteCommute = { id: `commute:${pairId(origin, destination)}`, label: cleanLabel, origin: placeSnapshot(origin), destination: placeSnapshot(destination), savedAt };
  return [item, ...existing.filter((candidate) => candidate.id !== item.id)].slice(0, MAX_FAVORITE_COMMUTES);
}

export function removeFavoritePlace(existing: FavoritePlace[], id: string): FavoritePlace[] {
  return existing.filter((item) => item.id !== id);
}

export function removeFavoriteCommute(existing: FavoriteCommute[], id: string): FavoriteCommute[] {
  return existing.filter((item) => item.id !== id);
}

export function persistRecentCommutes(storage: StorageLike, items: RecentCommute[]): void {
  storage.setItem(RECENT_COMMUTES_STORAGE_KEY, serializeItems(items, isRecentCommute, MAX_RECENT_COMMUTES));
}

export function persistFavoritePlaces(storage: StorageLike, items: FavoritePlace[]): void {
  storage.setItem(FAVORITE_PLACES_STORAGE_KEY, serializeItems(items, isFavoritePlace, MAX_FAVORITE_PLACES));
}

export function persistFavoriteCommutes(storage: StorageLike, items: FavoriteCommute[]): void {
  storage.setItem(FAVORITE_COMMUTES_STORAGE_KEY, serializeItems(items, isFavoriteCommute, MAX_FAVORITE_COMMUTES));
}

export const commuterToolLimits = Object.freeze({
  maxRecentCommutes: MAX_RECENT_COMMUTES,
  maxFavoritePlaces: MAX_FAVORITE_PLACES,
  maxFavoriteCommutes: MAX_FAVORITE_COMMUTES,
  schemaVersion: PERSONAL_COMMUTE_SCHEMA_VERSION,
});
