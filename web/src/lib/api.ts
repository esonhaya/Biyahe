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
  boarding_side: string | null;
  direction_note: string | null;
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
  verification: JourneyPoint["verification"];
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

export type JourneyLegType = "WALK_ACCESS" | "RIDE" | "TRANSFER_WALK" | "WALK_EGRESS";

export type JourneyTrustState = "VERIFIED" | "MIXED" | "UNVERIFIED";

export type JourneyPoint = {
  type: "PLACE" | "STOP";
  id: number;
  name: string;
  slug?: string;
  code?: string | null;
  location: LocationPoint | null;
  is_boarding_point?: boolean;
  boarding_notes?: string | null;
  boarding_side?: string | null;
  direction_note?: string | null;
  nearby_place?: { id: number; name: string } | null;
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
};

export type JourneyLeg = {
  type: JourneyLegType;
  origin: JourneyPoint;
  destination: JourneyPoint;
  distance_meters: number | null;
  distance_is_estimate: boolean;
  instruction: string | null;
  route: { id: number; code: string; name: string; vehicle_type: { code: string; name: string } | null; verification: JourneyPoint["verification"] } | null;
  pattern: { id: number; name: string; direction: string; verification: JourneyPoint["verification"] } | null;
  boarding_stop: JourneyPoint | null;
  alighting_stop: JourneyPoint | null;
  stop_count: number | null;
  boarding_sequence: number | null;
  alighting_sequence: number | null;
  boarding_instruction: string | null;
  boarding_association: JourneyPoint["verification"] | null;
  alighting_association: JourneyPoint["verification"] | null;
  driver_instruction: string | null;
  get_off_guidance: string | null;
  trust: JourneyTrustState | null;
  provenance: Provenance[];
  geometry: RoutePattern["geometry"];
};

export type Journey = {
  id: string;
  origin: JourneyPoint;
  destination: JourneyPoint;
  legs: JourneyLeg[];
  transfer_count: number;
  ranking: { walking_distance_meters: number; ride_stop_count: number; policy: string; reasons: string[] };
  trust: { state: JourneyTrustState; warning: string | null };
  warnings: string[];
  fare: { status: "UNAVAILABLE" | "AVAILABLE"; label: string; amount: number | null; currency: string | null };
  provenance: Provenance[];
};

export type JourneyResponse = {
  data: Journey[];
  meta: {
    origin: JourneyPoint;
    destination: JourneyPoint;
    result_count: number;
    max_transfers: number;
    distance_unit: "meters";
    walking_distance_type: "straight_line_estimate";
    boarding_candidates: number;
    alighting_candidates: number;
    no_route: { code: string; message: string } | null;
  };
};

export type CanIRideOption = {
  pattern: { id: number; name: string; direction: string; verification: JourneyPoint["verification"] };
  boarding_stop: JourneyPoint & { boarding_notes?: string | null; boarding_side?: string | null; direction_note?: string | null };
  alighting_stop: JourneyPoint;
  boarding_association: JourneyPoint["verification"];
  alighting_association: JourneyPoint["verification"];
  boarding_instruction: string;
  driver_instruction: string;
  get_off_guidance: string;
  stop_count: number;
  boarding_sequence: number;
  alighting_sequence: number;
  access_distance_meters: number;
  egress_distance_meters: number;
  trust: JourneyTrustState;
  provenance: Provenance[];
};

export type CanIRideResponse = {
  data: {
    origin: JourneyPoint;
    destination: JourneyPoint;
    status: "YES" | "NO" | "DIRECTION_DEPENDENT" | "UNKNOWN";
    message: string;
    route: { id: number; code: string; name: string; vehicle_type: { code: string; name: string } | null; verification: JourneyPoint["verification"] } | null;
    option: CanIRideOption | null;
    opposite_direction: CanIRideOption | null;
    trust: JourneyTrustState | null;
    warnings: string[];
    provenance: Provenance[];
  };
  meta: {
    distance_unit: "meters";
    walking_distance_type: "straight_line_estimate";
    access_radius_meters: number;
  };
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

export async function planJourney(
  fromPlaceId: number,
  toPlaceId: number,
  signal?: AbortSignal,
): Promise<JourneyResponse> {
  const params = new URLSearchParams({
    from_place_id: String(fromPlaceId),
    to_place_id: String(toPlaceId),
  });

  return getJson<JourneyResponse>("/journeys?" + params.toString(), signal);
}

export async function canIRide(
  routeCode: string,
  fromPlaceId: number,
  toPlaceId: number,
  signal?: AbortSignal,
): Promise<CanIRideResponse> {
  const params = new URLSearchParams({
    route_code: routeCode,
    from_place_id: String(fromPlaceId),
    to_place_id: String(toPlaceId),
  });

  return getJson<CanIRideResponse>("/can-i-ride?" + params.toString(), signal);
}
