import { fetchWithTimeout } from "./fetchWithTimeout.ts";

function endpointPath(path: string): string {
  return path.split("?", 1)[0] || "/";
}

export class ApiHttpError extends Error {
  readonly path: string;
  readonly status: number;

  constructor(path: string, status: number) {
    const safePath = endpointPath(path);
    super(`API request to ${safePath} failed with status ${status}.`);
    this.name = "ApiHttpError";
    this.path = safePath;
    this.status = status;
  }
}

function requireSuccessfulResponse(response: Response, path: string): void {
  if (!response.ok) throw new ApiHttpError(path, response.status);
}

async function readJsonResponse<T>(response: Response, path: string): Promise<T> {
  try {
    return await response.json() as T;
  } catch (error) {
    if (error instanceof SyntaxError) {
      throw new Error(`Malformed JSON response from ${endpointPath(path)}.`);
    }
    throw error;
  }
}

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
  network_distance_meters?: number | null;
  approximate_connector_distance_meters?: number | null;
  distance_provenance?: "OSM_NETWORK_PLUS_APPROXIMATE_ENDPOINT_CONNECTORS" | "OSM_NETWORK_WITHIN_SNAP_TOLERANCE" | "UNAVAILABLE" | null;
  geometry_provenance?: "OPENSTREETMAP_GRAPH_EDGE_COORDINATES" | "UNAVAILABLE" | null;
  walking_source_snapshot?: string | null;
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
  offline_walking_preview?: {
    status: "ROUTE_FOUND" | "ROUTE_UNAVAILABLE";
    reason_code: string | null;
    source: "OPENSTREETMAP";
    graph_sha256: string;
    graph_fingerprint: string;
    component_id: number | null;
    origin_snap_distance_meters: number | null;
    destination_snap_distance_meters: number | null;
    duration_label: "estimate_only" | null;
    field_safety_verified: false;
  };
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
  offline_walking_preview?: {
    mode: "DEVELOPMENT_ONLY";
    status: "COMPLETE" | "PARTIAL" | "UNAVAILABLE" | "NO_WALKING_LEGS";
    walking_legs: number;
    routes_found: number;
    source: "OPENSTREETMAP";
    source_sha256: string;
    source_snapshot: string;
    graph_sha256: string;
    graph_fingerprint: string;
    graph_format: "biyahe-pedestrian-sqlite-v3";
    graph_schema_version: 3;
    maximum_snap_distance_meters: 25;
    restriction_inventory: {
      complete: true;
      source_relation_count: number;
      pedestrian_applicable: number;
      ambiguous: number;
      unsupported_applicable: number;
      conditional_relations: number;
      conditional_semantics_evaluated: boolean;
      state_aware_turn_search: boolean;
      policy: string;
    };
    resource_preflight: { samples: 3; minimum_memavailable_kib: number; minimum_swapfree_kib: number } | null;
    field_safety_verified: false;
    notice: string;
  };
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

const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1").replace(/\/$/, "");

export function canUseOfflineWalkingPreview(): boolean {
  if (process.env.NODE_ENV !== "development" || process.env.NEXT_PUBLIC_OFFLINE_WALKING_PREVIEW !== "true") return false;
  try {
    const hostname = new URL(apiUrl).hostname.toLowerCase();
    return hostname === "localhost" || hostname === "127.0.0.1" || hostname === "::1" || hostname === "[::1]";
  } catch {
    return false;
  }
}

async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const response = await fetchWithTimeout(apiUrl + path, {
    headers: { Accept: "application/json" },
    signal,
    cache: "no-store",
  }, async (result) => {
    requireSuccessfulResponse(result, path);
    return readJsonResponse<T>(result, path);
  });
  return response as T;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasDataArray(value: unknown): value is { data: Record<string, unknown>[] } {
  return isRecord(value) && Array.isArray(value.data) && value.data.every(isRecord);
}

function isLocationPoint(value: unknown): boolean {
  return value === null || (isRecord(value)
    && typeof value.lat === "number" && Number.isFinite(value.lat) && value.lat >= -90 && value.lat <= 90
    && typeof value.lng === "number" && Number.isFinite(value.lng) && value.lng >= -180 && value.lng <= 180);
}

function isVerificationResponse(value: unknown): boolean {
  return isRecord(value)
    && typeof value.state === "string"
    && ["DRAFT", "UNVERIFIED", "VERIFIED", "STALE", "RETIRED"].includes(value.state)
    && typeof value.is_demo === "boolean"
    && typeof value.trusted === "boolean";
}

function isGeometryResponse(value: unknown): boolean {
  if (value === null) return true;
  if (!isRecord(value) || !Array.isArray(value.coordinates)) return false;
  const coordinate = (point: unknown) => Array.isArray(point) && point.length >= 2 && point.length <= 3
    && point.every((part) => typeof part === "number" && Number.isFinite(part))
    && point[0] >= -180 && point[0] <= 180 && point[1] >= -90 && point[1] <= 90;
  if (value.type === "LineString") return value.coordinates.length >= 2 && value.coordinates.every(coordinate);
  if (value.type === "MultiLineString") return value.coordinates.every((line) =>
    Array.isArray(line) && line.length >= 2 && line.every(coordinate)) && value.coordinates.length > 0;
  return false;
}

function isPlaceSuggestion(value: unknown): value is Place {
  return isRecord(value)
    && Number.isSafeInteger(value.id) && (value.id as number) > 0
    && typeof value.name === "string" && value.name.length > 0
    && typeof value.slug === "string"
    && typeof value.category === "string"
    && isLocationPoint(value.location)
    && isVerificationResponse(value.verification);
}

function isStopSummary(value: unknown): value is Stop {
  return isRecord(value)
    && Number.isSafeInteger(value.id) && (value.id as number) > 0
    && typeof value.name === "string" && typeof value.category === "string"
    && isLocationPoint(value.location)
    && isVerificationResponse(value.verification);
}

function isRouteSummary(value: unknown): value is Route {
  return isRecord(value)
    && Number.isSafeInteger(value.id) && (value.id as number) > 0
    && typeof value.code === "string" && typeof value.name === "string"
    && isVerificationResponse(value.verification);
}

function isJourneyPointResponse(value: unknown): value is JourneyPoint {
  return isRecord(value)
    && (value.type === "PLACE" || value.type === "STOP")
    && Number.isSafeInteger(value.id) && (value.id as number) > 0
    && typeof value.name === "string" && value.name.length > 0
    && isLocationPoint(value.location)
    && isVerificationResponse(value.verification);
}

function isNullableString(value: unknown): boolean {
  return value === null || typeof value === "string";
}

function isNullableNonNegativeNumber(value: unknown): boolean {
  return value === null || (typeof value === "number" && Number.isFinite(value) && value >= 0);
}

function isProvenanceList(value: unknown): boolean {
  return Array.isArray(value) && value.every((item) => isRecord(item)
    && typeof item.type === "string" && typeof item.title === "string");
}

function isJourneyDisruptionImpactResponse(value: unknown): boolean {
  if (!isRecord(value) || typeof value.warning !== "string"
    || !["INFORMATIONAL", "POSSIBLY_AFFECTED", "AFFECTED", "SERVICE_UNAVAILABLE"].includes(String(value.impact_level))
    || !(value.affected_leg_index === null || (Number.isSafeInteger(value.affected_leg_index) && (value.affected_leg_index as number) >= 0))
    || !(value.affected_leg_type === null || ["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"].includes(String(value.affected_leg_type)))
    || !Array.isArray(value.matched_by) || !value.matched_by.every((item) => typeof item === "string")
    || !(value.affected_legs === undefined || (Array.isArray(value.affected_legs) && value.affected_legs.every((leg) => isRecord(leg)
      && Number.isSafeInteger(leg.index) && (leg.index as number) >= 0
      && ["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"].includes(String(leg.type))
      && Array.isArray(leg.matched_by) && leg.matched_by.every((item) => typeof item === "string"))))) return false;

  const advisory = value.advisory;
  return isRecord(advisory)
    && typeof advisory.stable_id === "string" && advisory.stable_id.length > 0
    && typeof advisory.headline === "string"
    && typeof advisory.description === "string"
    && ["SERVICE_SUSPENDED", "SERVICE_REDUCED", "REROUTED", "STOP_UNAVAILABLE", "BOARDING_AREA_UNAVAILABLE", "ROAD_CLOSURE", "FLOODING", "EVENT", "TRAFFIC_DISRUPTION", "TRANSFER_UNAVAILABLE", "OTHER"].includes(String(advisory.category))
    && ["INFO", "MINOR", "MAJOR", "CRITICAL"].includes(String(advisory.severity))
    && typeof advisory.source_class === "string"
    && (advisory.starts_at === null || typeof advisory.starts_at === "string")
    && (advisory.ends_at === null || typeof advisory.ends_at === "string")
    && (advisory.source === null || (isRecord(advisory.source)
      && (advisory.source.observed_at === null || typeof advisory.source.observed_at === "string")
      && (advisory.source.conflict_status === undefined || advisory.source.conflict_status === null || typeof advisory.source.conflict_status === "string")));
}

function isJourneyLegResponse(value: unknown): boolean {
  if (!isRecord(value)
    || !["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"].includes(String(value.type))
    || !isJourneyPointResponse(value.origin) || !isJourneyPointResponse(value.destination)
    || !isNullableNonNegativeNumber(value.distance_meters)
    || typeof value.distance_is_estimate !== "boolean"
    || !(value.distance_type === null || ["PEDESTRIAN_NETWORK", "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS", "STRAIGHT_LINE_ESTIMATE", "NOT_REQUIRED", "UNAVAILABLE"].includes(String(value.distance_type)))
    || !isNullableString(value.routing_status) || !isNullableString(value.routing_source)
    || !(value.access_classification === null || ["NETWORK_ROUTE", "STRAIGHT_LINE_ESTIMATE", "NO_WALKABLE_ACCESS", "GRAPH_COVERAGE_GAP", "ROUTING_UNAVAILABLE"].includes(String(value.access_classification)))
    || !isNullableNonNegativeNumber(value.estimated_duration_seconds)
    || !isNullableString(value.origin_precision) || !isNullableString(value.destination_precision)
    || !Array.isArray(value.warnings) || !value.warnings.every((item) => typeof item === "string")
    || !(value.instruction === null || typeof value.instruction === "string")
    || !isProvenanceList(value.provenance)
    || !isGeometryResponse(value.geometry)) return false;

  if (value.route !== null && (!isRecord(value.route)
    || !Number.isSafeInteger(value.route.id) || (value.route.id as number) <= 0
    || typeof value.route.code !== "string" || typeof value.route.name !== "string"
    || !(value.route.vehicle_type === null || (isRecord(value.route.vehicle_type)
      && typeof value.route.vehicle_type.code === "string" && typeof value.route.vehicle_type.name === "string"))
    || !isVerificationResponse(value.route.verification))) return false;
  if (value.pattern !== null && (!isRecord(value.pattern)
    || !Number.isSafeInteger(value.pattern.id) || (value.pattern.id as number) <= 0
    || typeof value.pattern.name !== "string" || typeof value.pattern.direction !== "string"
    || !isVerificationResponse(value.pattern.verification))) return false;
  if (!(value.boarding_stop === null || isJourneyPointResponse(value.boarding_stop))
    || !(value.alighting_stop === null || isJourneyPointResponse(value.alighting_stop))
    || !(value.transfer === null || (isRecord(value.transfer)
      && ["TRANSFER", "SHARED_STOP"].includes(String(value.transfer.type))
      && (value.transfer.id === null || (Number.isSafeInteger(value.transfer.id) && (value.transfer.id as number) > 0))
      && isNullableString(value.transfer.stable_id)))
    || !(value.stop_count === null || (Number.isSafeInteger(value.stop_count) && (value.stop_count as number) >= 0))
    || !(value.boarding_sequence === null || (Number.isSafeInteger(value.boarding_sequence) && (value.boarding_sequence as number) >= 0))
    || !(value.alighting_sequence === null || (Number.isSafeInteger(value.alighting_sequence) && (value.alighting_sequence as number) >= 0))
    || !(value.boarding_instruction === null || typeof value.boarding_instruction === "string")
    || !(value.boarding_association === null || isVerificationResponse(value.boarding_association))
    || !(value.alighting_association === null || isVerificationResponse(value.alighting_association))
    || !(value.driver_instruction === null || typeof value.driver_instruction === "string")
    || !(value.get_off_guidance === null || typeof value.get_off_guidance === "string")
    || !(value.trust === null || ["VERIFIED", "MIXED", "UNVERIFIED"].includes(String(value.trust)))) return false;

  return true;
}

function isCanIRideOptionResponse(value: unknown): boolean {
  const accessLegIsValid = (leg: unknown) => isRecord(leg)
    && ["PEDESTRIAN_NETWORK", "STRAIGHT_LINE_ESTIMATE"].includes(String(leg.distance_type))
    && typeof leg.access_classification === "string"
    && (leg.distance_meters === null || (typeof leg.distance_meters === "number"
      && Number.isFinite(leg.distance_meters) && leg.distance_meters >= 0))
    && isGeometryResponse(leg.geometry);
  return isRecord(value)
    && isRecord(value.pattern) && Number.isSafeInteger(value.pattern.id)
    && typeof value.pattern.name === "string" && typeof value.pattern.direction === "string"
    && isVerificationResponse(value.pattern.verification)
    && isJourneyPointResponse(value.boarding_stop) && isJourneyPointResponse(value.alighting_stop)
    && typeof value.boarding_instruction === "string"
    && typeof value.driver_instruction === "string"
    && typeof value.get_off_guidance === "string"
    && Number.isSafeInteger(value.stop_count) && (value.stop_count as number) >= 0
    && ["VERIFIED", "MIXED", "UNVERIFIED"].includes(String(value.trust))
    && (value.pedestrian_access === undefined || (isRecord(value.pedestrian_access)
      && accessLegIsValid(value.pedestrian_access.to_boarding)
      && accessLegIsValid(value.pedestrian_access.from_alighting)));
}

function isJourneyResponseValue(value: unknown): value is JourneyResponse {
  if (!isRecord(value) || !hasDataArray(value) || !isRecord(value.meta)
    || !isJourneyPointResponse(value.meta.origin) || !isJourneyPointResponse(value.meta.destination)
    || !Number.isSafeInteger(value.meta.result_count) || (value.meta.result_count as number) < 0
    || !Number.isSafeInteger(value.meta.max_transfers) || (value.meta.max_transfers as number) < 0
    || !(value.meta.no_route === null || (isRecord(value.meta.no_route)
      && typeof value.meta.no_route.code === "string" && typeof value.meta.no_route.message === "string"))
    || !isRecord(value.meta.pedestrian_routing)) return false;
  if (value.meta.result_count !== value.data.length
    || (value.data.length === 0 && value.meta.no_route === null)
    || (value.data.length > 0 && value.meta.no_route !== null)) return false;

  return value.data.every((journey) => {
    if (typeof journey.id !== "string" || journey.id.length === 0 || !isJourneyPointResponse(journey.origin)
      || !isJourneyPointResponse(journey.destination) || !Array.isArray(journey.legs)
      || journey.legs.length === 0 || !Number.isSafeInteger(journey.transfer_count)
      || (journey.transfer_count as number) < 0
      || journey.transfer_count !== journey.legs.filter((leg) => isRecord(leg) && leg.type === "RIDE").length - 1
      || journey.origin.id !== value.meta.origin.id || journey.destination.id !== value.meta.destination.id
      || !isRecord(journey.ranking)
      || typeof journey.ranking.walking_distance_meters !== "number"
      || !Number.isFinite(journey.ranking.walking_distance_meters) || journey.ranking.walking_distance_meters < 0
      || !Number.isSafeInteger(journey.ranking.ride_stop_count) || (journey.ranking.ride_stop_count as number) < 0
      || typeof journey.ranking.policy !== "string"
      || !Array.isArray(journey.ranking.reasons) || !journey.ranking.reasons.every((item) => typeof item === "string")
      || !isRecord(journey.trust)
      || !["VERIFIED", "MIXED", "UNVERIFIED"].includes(String(journey.trust.state))
      || !(journey.trust.warning === null || typeof journey.trust.warning === "string")
      || !Array.isArray(journey.warnings) || !journey.warnings.every((item) => typeof item === "string")
      || !isRecord(journey.fare) || !["AVAILABLE", "UNAVAILABLE"].includes(String(journey.fare.status))
      || typeof journey.fare.label !== "string"
      || !(journey.fare.amount === null || (typeof journey.fare.amount === "number" && Number.isFinite(journey.fare.amount)))
      || !(journey.fare.currency === null || typeof journey.fare.currency === "string")
      || !isProvenanceList(journey.provenance)
      || !(journey.operational_impact === undefined || ["INFORMATIONAL", "POSSIBLY_AFFECTED", "AFFECTED", "SERVICE_UNAVAILABLE"].includes(String(journey.operational_impact)))
      || !(journey.disruptions === undefined || (Array.isArray(journey.disruptions)
        && journey.disruptions.every(isJourneyDisruptionImpactResponse)))) return false;

    return journey.legs.every(isJourneyLegResponse);
  });
}

function requireApiDataArray<T>(value: unknown, path: string, validateItem?: (item: unknown) => boolean): T[] {
  if (!hasDataArray(value) || (validateItem && !value.data.every(validateItem))) {
    throw new Error(`Invalid API response from ${path}.`);
  }
  return value.data as T[];
}

function requireJourneyResponse(
  value: unknown,
  path: string,
  expectedIdentity?: { originId: number; destinationId: number },
): JourneyResponse {
  if (!isJourneyResponseValue(value)) throw new Error(`Invalid API response from ${path}.`);
  if (expectedIdentity && (value.meta.origin.id !== expectedIdentity.originId
    || value.meta.destination.id !== expectedIdentity.destinationId)) {
    throw new Error(`Invalid API response from ${path}: requested place identity mismatch.`);
  }
  return value;
}

export async function listPlaces(signal?: AbortSignal): Promise<Place[]> {
  const path = "/places?per_page=50";
  return requireApiDataArray<Place>(await getJson<unknown>(path, signal), path, isPlaceSuggestion);
}

export async function searchPlaces(query: string, signal?: AbortSignal): Promise<Place[]> {
  const path = "/places/search?q=" + encodeURIComponent(query);
  return requireApiDataArray<Place>(await getJson<unknown>(path, signal), "/places/search", isPlaceSuggestion);
}

export async function listStops(signal?: AbortSignal): Promise<Stop[]> {
  const path = "/stops?per_page=100";
  return requireApiDataArray<Stop>(await getJson<unknown>(path, signal), path, isStopSummary);
}

export async function listRoutes(signal?: AbortSignal): Promise<Route[]> {
  const path = "/routes?per_page=50";
  return requireApiDataArray<Route>(await getJson<unknown>(path, signal), path, isRouteSummary);
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
  const response = await fetchWithTimeout(apiUrl + "/commuter-reports", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(payload),
    cache: "no-store",
  }, async (result) => {
    requireSuccessfulResponse(result, "/commuter-reports");
    return readJsonResponse<unknown>(result, "/commuter-reports");
  });
  if (!isRecord(response) || !isRecord(response.data)
    || typeof response.data.stable_id !== "string" || response.data.stable_id.length === 0
    || typeof response.data.category !== "string"
    || !["ROUTE_NOT_SEEN", "SERVICE_STOPPED", "REROUTE", "STOP_BLOCKED", "FLOODING", "ROAD_CLOSURE", "TRANSFER_PROBLEM", "FARE_OBSERVATION", "OTHER"].includes(response.data.category)
    || typeof response.data.status !== "string" || typeof response.data.description !== "string"
    || typeof response.data.observed_at !== "string" || !isLocationPoint(response.data.location)) {
    throw new Error("Invalid API response from /commuter-reports.");
  }
  return response as { data: { stable_id: string; category: CommuterReportCategory; status: string; description: string; observed_at: string; location: LocationPoint | null } };
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

  const path = "/journeys?" + params.toString();
  return requireJourneyResponse(await getJson<unknown>(path, signal), "/journeys", {
    originId: fromPlaceId,
    destinationId: toPlaceId,
  });
}

export async function previewJourneyWalkingOffline(
  journey: Journey,
  signal?: AbortSignal,
): Promise<{ journey: Journey; preview: NonNullable<Journey["offline_walking_preview"]> }> {
  if (!canUseOfflineWalkingPreview()) {
    throw new Error("unsafe_preview_target: Offline graph preview is enabled only against a local development API.");
  }
  const response = await fetchWithTimeout(apiUrl + "/dev/offline-walking-preview", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ journey }),
    signal,
    cache: "no-store",
  }, async (result) => {
    const body = await result.json().catch(() => null) as { data?: { journey?: Journey; preview?: Journey["offline_walking_preview"] }; error?: { code?: string; message?: string } } | null;
    if (!result.ok || !body?.data?.journey || !body.data.preview) {
      const code = body?.error?.code ?? `http_${result.status}`;
      const message = body?.error?.message ?? "The local graph preview is unavailable.";
      throw new Error(`${code}: ${message}`);
    }
    return { journey: body.data.journey, preview: body.data.preview as NonNullable<Journey["offline_walking_preview"]> };
  });
  return response as { journey: Journey; preview: NonNullable<Journey["offline_walking_preview"]> };
}

export async function replanJourney(
  fromPlaceId: number,
  toPlaceId: number,
  signal?: AbortSignal,
): Promise<JourneyResponse> {
  const response = await fetchWithTimeout(apiUrl + "/journeys/replan", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ from_place_id: fromPlaceId, to_place_id: toPlaceId }),
    signal,
    cache: "no-store",
  }, async (result) => {
    requireSuccessfulResponse(result, "/journeys/replan");
    return readJsonResponse<unknown>(result, "/journeys/replan");
  });
  return requireJourneyResponse(response, "/journeys/replan", {
    originId: fromPlaceId,
    destinationId: toPlaceId,
  });
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

  const path = "/can-i-ride?" + params.toString();
  const response = await getJson<unknown>(path, signal);
  if (!isRecord(response) || !isRecord(response.data) || !isRecord(response.meta)
    || !isJourneyPointResponse(response.data.origin) || !isJourneyPointResponse(response.data.destination)
    || !["YES", "YES_BUT_AFFECTED", "TEMPORARILY_UNAVAILABLE", "UNCERTAIN_DUE_TO_REPORT", "NO", "DIRECTION_DEPENDENT", "UNKNOWN"].includes(String(response.data.status))
    || typeof response.data.message !== "string" || typeof response.data.reason_code !== "string"
    || !Array.isArray(response.data.warnings) || !response.data.warnings.every((item) => typeof item === "string")
    || !Array.isArray(response.data.provenance)
    || !(response.data.route === null || (isRecord(response.data.route) && typeof response.data.route.code === "string"))
    || !(response.data.option === null || isCanIRideOptionResponse(response.data.option))
    || !(response.data.opposite_direction === null || isCanIRideOptionResponse(response.data.opposite_direction))
    || !(response.data.trust === null || ["VERIFIED", "MIXED", "UNVERIFIED"].includes(String(response.data.trust)))
    || (response.data.disruptions !== undefined && (!Array.isArray(response.data.disruptions)
      || !response.data.disruptions.every((impact) => isRecord(impact) && typeof impact.warning === "string"
        && isRecord(impact.advisory) && typeof impact.advisory.stable_id === "string")))
    || response.meta.distance_unit !== "meters"
    || typeof response.meta.walking_distance_type !== "string"
    || response.meta.pedestrian_leg_distance_type !== "per_leg"
    || typeof response.meta.access_radius_meters !== "number" || !Number.isFinite(response.meta.access_radius_meters)) {
    throw new Error("Invalid API response from /can-i-ride.");
  }
  if (response.data.origin.id !== fromPlaceId || response.data.destination.id !== toPlaceId) {
    throw new Error("Invalid API response from /can-i-ride: requested place identity mismatch.");
  }
  if ((response.data.status === "YES" || response.data.status === "YES_BUT_AFFECTED")
    && (response.data.route === null || response.data.option === null)) {
    throw new Error("Invalid API response from /can-i-ride: a YES result needs a route and direction option.");
  }
  if (response.data.status === "DIRECTION_DEPENDENT" && response.data.opposite_direction === null) {
    throw new Error("Invalid API response from /can-i-ride: direction-dependent result has no opposite-direction evidence.");
  }
  return response as unknown as CanIRideResponse;
}
