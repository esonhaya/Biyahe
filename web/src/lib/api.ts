export type VerificationState = "DRAFT" | "UNVERIFIED" | "VERIFIED" | "STALE" | "RETIRED";

export type LocationPoint = {
  lat: number;
  lng: number;
};

export type Provenance = {
  type: string;
  title: string;
  publisher: string | null;
  url: string | null;
  accessed_at: string | null;
  checked_at: string | null;
  effective_from: string | null;
  effective_until: string | null;
  license: string | null;
  verification_method: string | null;
  verified_by: string | null;
  notes: string | null;
};

export type City = {
  id: number;
  name: string;
  slug: string;
  country_code: string;
  is_active: boolean;
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
};

export type Place = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  category: string;
  location: LocationPoint | null;
  city?: City;
  aliases?: { id: number; alias: string; is_active: boolean }[];
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
  provenance?: Provenance;
};

export type Stop = {
  id: number;
  name: string;
  code: string | null;
  category: string;
  location: LocationPoint | null;
  is_boarding_point: boolean;
  boarding_notes: string | null;
  city?: City;
  nearby_place?: Place;
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
  provenance?: Provenance;
  distance_meters?: number | null;
};

export type RoutePatternStop = {
  sequence: number;
  boarding_allowed: boolean;
  alighting_allowed: boolean;
  instruction: string | null;
  service_metadata: Record<string, unknown> | null;
  stop: Stop;
  provenance?: Provenance;
};

export type RoutePattern = {
  id: number;
  name: string;
  direction: string;
  service_notes: string | null;
  geometry: {
    type: "MultiLineString";
    coordinates: number[][][];
  } | null;
  route?: { id: number; code: string; name: string };
  stops?: RoutePatternStop[];
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
  provenance?: Provenance;
};

export type Route = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  city?: City;
  operator?: { id: number; name: string };
  vehicle_type?: { code: string; name: string };
  patterns?: RoutePattern[];
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
  provenance?: Provenance;
};

type ApiPage<T> = {
  data: T[];
  meta?: { current_page?: number; last_page?: number; per_page?: number; total?: number };
};

const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1").replace(/\/$/, "");

async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(apiUrl + path, {
    headers: { Accept: "application/json" },
    signal,
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error("API request failed with status " + response.status);
  }

  return response.json() as Promise<T>;
}

export async function listPlaces(signal?: AbortSignal): Promise<Place[]> {
  const page = await getJson<ApiPage<Place>>("/places?per_page=50", signal);
  return page.data;
}

export async function searchPlaces(query: string, signal?: AbortSignal): Promise<Place[]> {
  const page = await getJson<{ data: Place[] }>("/places/search?q=" + encodeURIComponent(query), signal);
  return page.data;
}

export async function listStops(signal?: AbortSignal): Promise<Stop[]> {
  const page = await getJson<ApiPage<Stop>>("/stops?per_page=100", signal);
  return page.data;
}

export async function listRoutes(signal?: AbortSignal): Promise<Route[]> {
  const page = await getJson<ApiPage<Route>>("/routes?per_page=50", signal);
  return page.data;
}

export async function getRoutePattern(patternId: number, signal?: AbortSignal): Promise<RoutePattern> {
  const response = await getJson<{ data: RoutePattern }>("/route-patterns/" + patternId, signal);
  return response.data;
}
