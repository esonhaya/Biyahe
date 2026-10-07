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

export type NearbyRoute = {
  id: number;
  code: string;
  name: string;
  vehicle_type: { code: string; name: string };
  verification: { state: VerificationState; trusted: boolean };
  nearest_distance_meters: number;
  boarding_candidates: {
    pattern: { id: number; name: string; direction: string };
    stop: {
      id: number;
      name: string;
      code: string | null;
      boarding_precision: JourneyPoint["boarding_precision"];
      boarding_verification_state: VerificationState;
    };
    distance_meters: number;
  }[];
  active_advisories: { stable_id: string; headline: string; severity: string }[];
  nearby_note: string;
};

export type JourneyLegType = "WALK_ACCESS" | "RIDE" | "TRANSFER_WALK" | "WALK_EGRESS";

export type JourneyTrustState = "VERIFIED" | "MIXED" | "UNVERIFIED";

export type PedestrianAccessClassification = "NETWORK_ROUTE" | "STRAIGHT_LINE_ESTIMATE" | "NO_WALKABLE_ACCESS" | "GRAPH_COVERAGE_GAP" | "ROUTING_UNAVAILABLE";

export type DisruptionCategory = "SERVICE_SUSPENDED" | "SERVICE_REDUCED" | "REROUTED" | "STOP_UNAVAILABLE" | "BOARDING_AREA_UNAVAILABLE" | "ROAD_CLOSURE" | "FLOODING" | "EVENT" | "TRAFFIC_DISRUPTION" | "TRANSFER_UNAVAILABLE" | "OTHER";
export type DisruptionStatus = "DRAFT" | "SCHEDULED" | "ACTIVE" | "RESOLVED" | "EXPIRED" | "REJECTED";
export type DisruptionSeverity = "INFO" | "MINOR" | "MAJOR" | "CRITICAL";
export type DisruptionImpactLevel = "INFORMATIONAL" | "POSSIBLY_AFFECTED" | "AFFECTED" | "SERVICE_UNAVAILABLE";
export type CommuterReportCategory = "ROUTE_NOT_SEEN" | "SERVICE_STOPPED" | "REROUTE" | "STOP_BLOCKED" | "FLOODING" | "ROAD_CLOSURE" | "TRANSFER_PROBLEM" | "FARE_OBSERVATION" | "OTHER";

export type DisruptionSummary = {
  id: number;
  stable_id: string;
  category: DisruptionCategory;
  headline: string;
  description: string;
  severity: DisruptionSeverity;
  status: DisruptionStatus;
  starts_at: string | null;
  ends_at: string | null;
  source_class: string;
  source: { type: string; title: string; publisher: string | null; url: string | null; checked_at: string | null; observed_at: string | null; attribution: string; conflict_status?: string | null } | null;
  checked_at: string | null;
  geometry: { type: string; coordinates: unknown } | null;
  affected_entities: {
    routes: { id: number; code: string; name: string }[];
    patterns: { id: number; name: string; direction: string; route_code: string | null }[];
    stops: { id: number; name: string; location: LocationPoint | null }[];
    places: { id: number; name: string; location: LocationPoint | null }[];
    transfers: { id: number; stable_id: string; from_stop_id: number; to_stop_id: number }[];
  };
};

export type JourneyDisruptionImpact = {
  advisory: DisruptionSummary;
  impact_level: DisruptionImpactLevel;
  hard_closure: boolean;
  affected_leg_index: number | null;
  affected_leg_type: JourneyLegType | null;
  affected_legs?: { index: number; type: JourneyLegType; matched_by: string[] }[];
  matched_by: string[];
  warning: string;
};

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
  boarding_precision?: "EXACT_BOARDING_POINT" | "BOARDING_AREA" | "APPROXIMATE_LOCATION" | "UNKNOWN" | null;
  alighting_precision?: "UNKNOWN";
  nearby_place?: { id: number; name: string } | null;
  verification: { state: VerificationState; is_demo: boolean; trusted: boolean };
};

export type JourneyPatternSummary = {
  id: number;
  name: string;
  direction: string;
  verification: JourneyPoint["verification"];
  stops?: RoutePatternStop[];
};

export type JourneyLeg = {
  type: JourneyLegType;
  origin: JourneyPoint;
  destination: JourneyPoint;
  distance_meters: number | null;
  distance_is_estimate: boolean;
  distance_type: "PEDESTRIAN_NETWORK" | "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS" | "STRAIGHT_LINE_ESTIMATE" | "NOT_REQUIRED" | "UNAVAILABLE" | null;
  routing_status: string | null;
  routing_source: string | null;
  access_classification: PedestrianAccessClassification | null;
  estimated_duration_seconds: number | null;
  origin_precision: string | null;
  destination_precision: string | null;
  warnings: string[];
  instruction: string | null;
  route: { id: number; code: string; name: string; vehicle_type: { code: string; name: string } | null; verification: JourneyPoint["verification"] } | null;
  pattern: JourneyPatternSummary | null;
  boarding_stop: JourneyPoint | null;
  alighting_stop: JourneyPoint | null;
  transfer?: { type: "TRANSFER" | "SHARED_STOP"; id: number | null; stable_id: string | null } | null;
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
  geometry: RoutePattern["geometry"] | { type: "LineString"; coordinates: number[][] } | null;
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
  operational_impact?: DisruptionImpactLevel;
  disruptions?: JourneyDisruptionImpact[];
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
    walking_distance_type: "straight_line_estimate" | "pedestrian_network" | "per_leg";
    pedestrian_routing: {
      candidates: number;
      routes_computed: number;
      routes_available: number;
      cache_hits: number;
      no_route_results: number;
      routing_time_milliseconds: number;
      provider_status: string;
    };
    disruption_awareness?: {
      journeys_checked: number;
      active_advisories: number;
      impact_matches: number;
      disruption_queries: number;
      match_time_milliseconds: number;
    };
    replan?: boolean;
    replan_status?: "ALTERNATIVES_FOUND" | "NO_ALTERNATIVE_FOUND" | "ALL_KNOWN_ALTERNATIVES_AFFECTED" | "TEMPORARILY_UNAVAILABLE" | null;
    boarding_candidates: number;
    alighting_candidates: number;
    no_route: { code: string; message: string } | null;
  };
};

export type PulseNetworkStatus = {
  code: "NO_KNOWN_ACTIVE_ADVISORIES" | "MINOR_ISSUES" | "DISRUPTED" | "MAJOR_DISRUPTION" | "STATUS_UNCERTAIN";
  label: string;
  explanation: string;
};

export type PulseItem = DisruptionSummary & {
  freshness_label: string;
  expected_end_label: string | null;
  affected_entity_count: number;
  conflict_status?: string | null;
};

export type PulseCommunitySignal = {
  category: CommuterReportCategory;
  count: number;
  observed_at: string | null;
  freshness_label: string;
  route: { id: number; code: string; name: string } | null;
  stop: { id: number; name: string } | null;
  place: { id: number; name: string } | null;
  message: string;
  conflicted: boolean;
  authoritative: false;
};

export type PulseResponse = {
  data: {
    summary: {
      active_advisories: number;
      major_or_critical: number;
      routes_affected: number;
      stops_affected: number;
      areas_affected: number;
      unresolved_public_signals: number;
      open_conflicts: number;
    };
    network_status: PulseNetworkStatus;
    items: PulseItem[];
    community_signals: PulseCommunitySignal[];
    meta: {
      generated_at: string;
      filter: "ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR";
      temporary_overlay: true;
      community_signals_are_non_authoritative: true;
      journey_context: "ROUTE" | "STOP" | "PLACE" | null;
    };
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
  pedestrian_access: {
    to_boarding: PedestrianAccessLeg;
    from_alighting: PedestrianAccessLeg;
  };
};

export type PedestrianAccessLeg = {
  status: string;
  access_classification: PedestrianAccessClassification;
  distance_meters: number | null;
  distance_type: "PEDESTRIAN_NETWORK" | "STRAIGHT_LINE_ESTIMATE";
  estimated_duration_seconds: number | null;
  geometry: { type: "LineString"; coordinates: number[][] } | null;
  source: string | null;
  precision: JourneyPoint["boarding_precision"] | "NOT_APPLICABLE";
  warnings: string[];
};

export type CanIRideResponse = {
  data: {
    origin: JourneyPoint;
    destination: JourneyPoint;
    status: "YES" | "YES_BUT_AFFECTED" | "TEMPORARILY_UNAVAILABLE" | "UNCERTAIN_DUE_TO_REPORT" | "NO" | "DIRECTION_DEPENDENT" | "UNKNOWN";
    message: string;
    reason_code: string;
    route: { id: number; code: string; name: string; vehicle_type: { code: string; name: string } | null; verification: JourneyPoint["verification"] } | null;
    option: CanIRideOption | null;
    opposite_direction: CanIRideOption | null;
    trust: JourneyTrustState | null;
    warnings: string[];
    operational_impact?: DisruptionImpactLevel;
    disruptions?: JourneyDisruptionImpact[];
    provenance: Provenance[];
  };
  meta: {
    distance_unit: "meters";
    walking_distance_type: "straight_line_estimate";
    pedestrian_leg_distance_type: "per_leg";
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

export async function listNearbyRoutes(
  latitude: number,
  longitude: number,
  filters: { radius?: number; limit?: number } = {},
  signal?: AbortSignal,
): Promise<{ data: NearbyRoute[]; meta: { radius_meters: number; count: number; location_is_ephemeral: boolean; vehicle_presence: string } }> {
  const params = new URLSearchParams({ lat: String(latitude), lng: String(longitude) });
  if (filters.radius) params.set("radius", String(filters.radius));
  if (filters.limit) params.set("limit", String(filters.limit));
  return getJson(`/routes/nearby?${params.toString()}`, signal);
}

export async function listDisruptions(filters: { route_id?: number; stop_id?: number; place_id?: number } = {}, signal?: AbortSignal): Promise<DisruptionSummary[]> {
  const params = new URLSearchParams({ limit: "50" });
  if (filters.route_id) params.set("route_id", String(filters.route_id));
  if (filters.stop_id) params.set("stop_id", String(filters.stop_id));
  if (filters.place_id) params.set("place_id", String(filters.place_id));
  const response = await getJson<{ data: DisruptionSummary[] }>("/disruptions?" + params.toString(), signal);
  return response.data;
}

export async function getPulse(
  filters: { filter?: "ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR"; route_id?: number; stop_id?: number; place_id?: number } = {},
  signal?: AbortSignal,
): Promise<PulseResponse["data"]> {
  const params = new URLSearchParams();
  if (filters.filter) params.set("filter", filters.filter);
  if (filters.route_id) params.set("route_id", String(filters.route_id));
  if (filters.stop_id) params.set("stop_id", String(filters.stop_id));
  if (filters.place_id) params.set("place_id", String(filters.place_id));
  const response = await getJson<PulseResponse>("/pulse" + (params.toString() ? "?" + params.toString() : ""), signal);
  return response.data;
}

export type CommuterReportPayload = {
  category: CommuterReportCategory;
  description: string;
  observed_at: string;
  route_id?: number;
  route_pattern_id?: number;
  stop_id?: number;
  place_id?: number;
  transfer_relationship_id?: number;
  location?: LocationPoint;
  location_accuracy_m?: number;
  source_reference?: string;
};

export async function submitCommuterReport(payload: CommuterReportPayload): Promise<{ data: { stable_id: string; category: CommuterReportCategory; status: string; description: string; observed_at: string; location: LocationPoint | null } }> {
  const response = await fetch(apiUrl + "/commuter-reports", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(payload),
    cache: "no-store",
  });
  if (!response.ok) throw new Error("Report request failed with status " + response.status);
  return response.json() as Promise<{ data: { stable_id: string; category: CommuterReportCategory; status: string; description: string; observed_at: string; location: LocationPoint | null } }>;
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

export async function replanJourney(
  fromPlaceId: number,
  toPlaceId: number,
  signal?: AbortSignal,
): Promise<JourneyResponse> {
  const response = await fetch(apiUrl + "/journeys/replan", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ from_place_id: fromPlaceId, to_place_id: toPlaceId }),
    signal,
    cache: "no-store",
  });
  if (!response.ok) throw new Error("Replan request failed with status " + response.status);
  return response.json() as Promise<JourneyResponse>;
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
