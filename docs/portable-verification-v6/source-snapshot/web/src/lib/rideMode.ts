import type { Journey, JourneyLeg, JourneyPoint, LocationPoint, RoutePatternStop } from "./api";

/**
 * Ride Mode is deliberately a client-side, foreground-only state layer.
 * Keep this session shape free of position samples so it is safe to persist
 * in localStorage. Live location belongs in React memory only.
 */
export const RIDE_MODE_STORAGE_KEY = "biyahe.ride-mode.session.v1";

export const LOCATION_THRESHOLDS = Object.freeze({
  goodAccuracyMeters: 20,
  usableAccuracyMeters: 75,
  staleAfterMilliseconds: 30_000,
  futureToleranceMilliseconds: 5_000,
});

export const GET_OFF_THRESHOLDS = Object.freeze({
  approachingMeters: 500,
  getReadyMeters: 250,
  getOffAreaMeters: 100,
  hysteresisMeters: 35,
});

export type RideModeStatus = "PLANNED" | "ACTIVE" | "COMPLETED" | "CANCELLED";
export type RideModeLegState = "NOT_STARTED" | "WALKING" | "WAITING_TO_BOARD" | "RIDING" | "ARRIVED";
export type RideModeAction =
  | "ACTIVATE"
  | "START_WALK"
  | "START_NEXT_LEG"
  | "AT_BOARDING_AREA"
  | "BOARDED"
  | "GOT_OFF"
  | "ARRIVED"
  | "REVIEW_PREVIOUS_STEP"
  | "CANCEL";

export type RideModeSession = {
  version: 1;
  sessionId: string;
  journey: Journey;
  status: RideModeStatus;
  currentLegIndex: number;
  currentLegState: RideModeLegState;
  createdAt: number;
  updatedAt: number;
};

export type RideModeTransition = {
  session: RideModeSession;
  error: string | null;
};

export type LocationQuality = "GOOD" | "USABLE" | "POOR" | "STALE" | "UNAVAILABLE";
export type RideModeLocationSample = {
  lat: number;
  lng: number;
  accuracy: number;
  timestamp: number;
};

export type CorridorState =
  | "ON_EXPECTED_CORRIDOR"
  | "NEAR_EXPECTED_CORRIDOR"
  | "LOCATION_UNCERTAIN"
  | "POSSIBLY_OFF_ROUTE";

export type RideModeGuidancePhase = "RIDING" | "APPROACHING" | "GET_READY" | "GET_OFF_AREA";
export type WalkingProgress = "IN_PROGRESS" | "NEAR_DESTINATION";

export type RideStopProgress = {
  currentSequence: number | null;
  stopsRemaining: number | null;
  targetSequence: number | null;
};

export type RideModeLocationEvaluation = {
  quality: LocationQuality;
  accepted: boolean;
  sample: RideModeLocationSample | null;
  corridorState: CorridorState;
  guidancePhase: RideModeGuidancePhase | null;
  walkingProgress: WalkingProgress | null;
  distanceToTargetMeters: number | null;
  distanceBasis: "ALONG_ROUTE" | "STRAIGHT_LINE_APPROXIMATION" | "UNAVAILABLE";
  distanceToRouteMeters: number | null;
  remainingAlongRouteMeters: number | null;
  currentAlongRouteMeters: number | null;
  targetAlongRouteMeters: number | null;
  stopProgress: RideStopProgress | null;
  passedAlighting: boolean;
  phaseChanged: boolean;
};

export type RideModeMapOverlay = {
  journeyId: string;
  activeLegIndex: number;
  currentLocation: LocationPoint | null;
  accuracyMeters: number | null;
  activeGeometry: JourneyLeg["geometry"];
  boardingLocation: LocationPoint | null;
  alightingLocation: LocationPoint | null;
  recenterToken: number;
};

const LEG_TYPES = new Set(["WALK_ACCESS", "RIDE", "TRANSFER_WALK", "WALK_EGRESS"]);

function stableIdentityJson(value: unknown): string {
  if (value === undefined) return "undefined";
  if (value === null || typeof value !== "object") return JSON.stringify(value) ?? "undefined";
  if (Array.isArray(value)) return `[${value.map(stableIdentityJson).join(",")}]`;
  const record = value as Record<string, unknown>;
  return `{${Object.keys(record).sort().map((key) => `${JSON.stringify(key)}:${stableIdentityJson(record[key])}`).join(",")}}`;
}

const LOCAL_PREVIEW_WARNINGS = new Set([
  "Geometry follows source OSM pedestrian-accessible ways; it does not verify sidewalks, crossings, barriers missing from OSM, or current field conditions.",
  "Endpoint connectors are approximate straight-line snaps and are not included in the displayed graph geometry.",
  "Walking duration is an estimate using a fixed speed assumption, not a precise ETA.",
  "No local graph walking path was established; no substitute line or connector is shown.",
]);

/** Stable across local preview output changes, but bound to the selected route and its source evidence. */
export function offlineWalkingRequestIdentity(journey: Journey): string {
  const identityLegs = journey.legs.map((leg) => {
    if (leg.type === "RIDE") return leg;
    return {
      type: leg.type,
      origin: leg.origin,
      destination: leg.destination,
      route: leg.route,
      pattern: leg.pattern,
      boarding_stop: leg.boarding_stop,
      alighting_stop: leg.alighting_stop,
      stop_count: leg.stop_count,
      boarding_sequence: leg.boarding_sequence,
      alighting_sequence: leg.alighting_sequence,
      boarding_instruction: leg.boarding_instruction,
      boarding_association: leg.boarding_association,
      alighting_association: leg.alighting_association,
      driver_instruction: leg.driver_instruction,
      get_off_guidance: leg.get_off_guidance,
      origin_precision: leg.origin_precision,
      destination_precision: leg.destination_precision,
      trust: leg.trust,
      provenance: leg.provenance,
      instruction: leg.instruction,
      warnings: (leg.warnings ?? []).filter((warning) => !LOCAL_PREVIEW_WARNINGS.has(warning)),
    };
  });

  return stableIdentityJson({
    id: journey.id,
    origin: journey.origin,
    destination: journey.destination,
    legs: identityLegs,
    transfer_count: journey.transfer_count,
    ranking: journey.ranking,
    trust: journey.trust,
    warnings: journey.warnings,
    operational_impact: journey.operational_impact,
    disruptions: journey.disruptions,
    fare: journey.fare,
    provenance: journey.provenance,
  });
}
const PERSISTED_SESSION_KEYS = new Set([
  "version",
  "sessionId",
  "journey",
  "status",
  "currentLegIndex",
  "currentLegState",
  "createdAt",
  "updatedAt",
]);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function pointLocation(value: unknown): value is LocationPoint {
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

function looksLikeJourney(value: unknown): value is Journey {
  if (!isRecord(value) || typeof value.id !== "string" || !Array.isArray(value.legs) || value.legs.length === 0) {
    return false;
  }

  return value.legs.every((leg) => {
    if (!isRecord(leg) || typeof leg.type !== "string" || !LEG_TYPES.has(leg.type)) {
      return false;
    }
    if (!isRecord(leg.origin) || !isRecord(leg.destination)) {
      return false;
    }
    return typeof leg.origin.id === "number"
      && typeof leg.destination.id === "number"
      && (leg.origin.location === null || leg.origin.location === undefined || pointLocation(leg.origin.location))
      && (leg.destination.location === null || leg.destination.location === undefined || pointLocation(leg.destination.location));
  });
}

function legInitialState(leg: JourneyLeg): RideModeLegState {
  return leg.type === "RIDE" ? "WAITING_TO_BOARD" : "NOT_STARTED";
}

function copyWithUpdate(session: RideModeSession, update: Partial<RideModeSession>, now: number): RideModeSession {
  return { ...session, ...update, updatedAt: now };
}

function transitionError(session: RideModeSession, error: string): RideModeTransition {
  return { session, error };
}

function nextLeg(session: RideModeSession, now: number): RideModeTransition {
  const nextIndex = session.currentLegIndex + 1;
  const next = session.journey.legs[nextIndex];
  if (!next) {
    return transitionError(session, "There is no next journey leg to start.");
  }

  return {
    session: copyWithUpdate(session, {
      currentLegIndex: nextIndex,
      currentLegState: legInitialState(next),
    }, now),
    error: null,
  };
}

export function createRideModeSession(journey: Journey, now = Date.now()): RideModeSession {
  if (journey.legs.length === 0) {
    throw new Error("A Ride Mode session needs at least one journey leg.");
  }

  return {
    version: 1,
    sessionId: `ride-${journey.id}-${now}`,
    journey,
    status: "PLANNED",
    currentLegIndex: 0,
    currentLegState: legInitialState(journey.legs[0]),
    createdAt: now,
    updatedAt: now,
  };
}

export function currentRideModeLeg(session: RideModeSession): JourneyLeg | null {
  return session.journey.legs[session.currentLegIndex] ?? null;
}

function previousManualProgress(session: RideModeSession): { currentLegIndex: number; currentLegState: RideModeLegState } | null {
  const leg = currentRideModeLeg(session);
  if (!leg) return null;

  if (session.currentLegState === "WALKING") {
    return { currentLegIndex: session.currentLegIndex, currentLegState: "NOT_STARTED" };
  }
  if (leg.type === "RIDE" && session.currentLegState === "RIDING") {
    return { currentLegIndex: session.currentLegIndex, currentLegState: "WAITING_TO_BOARD" };
  }
  if (leg.type === "RIDE" && session.currentLegState === "WAITING_TO_BOARD") {
    const previousLeg = session.journey.legs[session.currentLegIndex - 1];
    if (previousLeg && ["WALK_ACCESS", "TRANSFER_WALK"].includes(previousLeg.type)) {
      return { currentLegIndex: session.currentLegIndex - 1, currentLegState: "WALKING" };
    }
  }
  if (["WALK_ACCESS", "TRANSFER_WALK", "WALK_EGRESS"].includes(leg.type) && session.currentLegState === "NOT_STARTED") {
    const previousIndex = session.currentLegIndex - 1;
    const previousLeg = session.journey.legs[previousIndex];
    if (previousLeg?.type === "RIDE") {
      return { currentLegIndex: previousIndex, currentLegState: "RIDING" };
    }
  }
  return null;
}

export function canReviewPreviousRideModeStep(session: RideModeSession): boolean {
  return session.status === "ACTIVE" && previousManualProgress(session) !== null;
}

export function applyRideModeAction(
  session: RideModeSession,
  action: RideModeAction,
  now = Date.now(),
): RideModeTransition {
  if (action === "CANCEL") {
    if (session.status === "COMPLETED") {
      return transitionError(session, "This Ride Mode session is already complete.");
    }
    return { session: copyWithUpdate(session, { status: "CANCELLED" }, now), error: null };
  }

  if (action === "ACTIVATE") {
    if (session.status !== "PLANNED") {
      return transitionError(session, "Only a planned Ride Mode session can be started.");
    }
    return { session: copyWithUpdate(session, { status: "ACTIVE" }, now), error: null };
  }

  if (session.status !== "ACTIVE") {
    return transitionError(session, "Start Ride Mode before changing journey progress.");
  }

  if (action === "REVIEW_PREVIOUS_STEP") {
    const previous = previousManualProgress(session);
    if (!previous) return transitionError(session, "There is no previous manual step to review.");
    return { session: copyWithUpdate(session, previous, now), error: null };
  }

  const leg = currentRideModeLeg(session);
  if (!leg) {
    return transitionError(session, "The current journey leg is unavailable.");
  }

  if (action === "START_WALK" || action === "START_NEXT_LEG") {
    if (!["WALK_ACCESS", "TRANSFER_WALK", "WALK_EGRESS"].includes(leg.type)) {
      return transitionError(session, "The current leg is not a walking leg.");
    }
    if (session.currentLegState !== "NOT_STARTED") {
      return transitionError(session, "This walking step has already started or advanced.");
    }
    return {
      session: copyWithUpdate(session, { currentLegState: "WALKING" }, now),
      error: null,
    };
  }

  if (action === "AT_BOARDING_AREA") {
    if (!["WALK_ACCESS", "TRANSFER_WALK"].includes(leg.type)) {
      return transitionError(session, "A boarding area can only follow a walking access leg.");
    }
    if (session.currentLegState !== "WALKING") {
      return transitionError(session, "Start the walking step before confirming the boarding area.");
    }
    const followingLeg = session.journey.legs[session.currentLegIndex + 1];
    if (!followingLeg || followingLeg.type !== "RIDE") {
      return transitionError(session, "The next journey leg is not a transit leg.");
    }
    return nextLeg(session, now);
  }

  if (action === "BOARDED") {
    if (leg.type !== "RIDE" || session.currentLegState !== "WAITING_TO_BOARD") {
      return transitionError(session, "Confirm the mapped boarding area before confirming boarding.");
    }
    return {
      session: copyWithUpdate(session, { currentLegState: "RIDING" }, now),
      error: null,
    };
  }

  if (action === "GOT_OFF") {
    if (leg.type !== "RIDE" || session.currentLegState !== "RIDING") {
      return transitionError(session, "Confirm boarding before confirming that you got off.");
    }
    return nextLeg(session, now);
  }

  if (action === "ARRIVED") {
    if (leg.type !== "WALK_EGRESS") {
      return transitionError(session, "Arrival can be confirmed only on the final walking leg.");
    }
    if (session.currentLegState !== "WALKING") {
      return transitionError(session, "Start the final walking step before confirming arrival.");
    }
    return {
      session: copyWithUpdate(session, {
        status: "COMPLETED",
        currentLegState: "ARRIVED",
      }, now),
      error: null,
    };
  }

  return transitionError(session, "That Ride Mode action is not available yet.");
}

/** Replace only the journey payload after a one-time pattern detail fetch. */
export function replaceSessionJourney(session: RideModeSession, journey: Journey, now = Date.now()): RideModeSession {
  if (session.journey.id !== journey.id || session.journey.legs.length !== journey.legs.length
    || offlineWalkingRequestIdentity(session.journey) !== offlineWalkingRequestIdentity(journey)) {
    return session;
  }

  return copyWithUpdate(session, { journey }, now);
}

export function serializeRideModeSession(session: RideModeSession): string {
  // Explicitly select the persisted shape: no position, quality, trace, or
  // device state can accidentally enter localStorage through this function.
  return JSON.stringify({
    version: session.version,
    sessionId: session.sessionId,
    journey: session.journey,
    status: session.status,
    currentLegIndex: session.currentLegIndex,
    currentLegState: session.currentLegState,
    createdAt: session.createdAt,
    updatedAt: session.updatedAt,
  });
}

export function parseRideModeSession(raw: string | null): RideModeSession | null {
  if (!raw) {
    return null;
  }

  try {
    const value: unknown = JSON.parse(raw);
    if (!isRecord(value) || Object.keys(value).some((key) => !PERSISTED_SESSION_KEYS.has(key))) {
      return null;
    }
    if (value.version !== 1 || typeof value.sessionId !== "string" || !looksLikeJourney(value.journey)) {
      return null;
    }
    if (!["PLANNED", "ACTIVE", "COMPLETED", "CANCELLED"].includes(String(value.status))) {
      return null;
    }
    const currentLegIndex = value.currentLegIndex;
    if (typeof currentLegIndex !== "number" || !Number.isInteger(currentLegIndex) || currentLegIndex < 0 || currentLegIndex >= value.journey.legs.length) {
      return null;
    }
    if (!["NOT_STARTED", "WALKING", "WAITING_TO_BOARD", "RIDING", "ARRIVED"].includes(String(value.currentLegState))) {
      return null;
    }
    if (typeof value.createdAt !== "number" || !Number.isFinite(value.createdAt)
      || typeof value.updatedAt !== "number" || !Number.isFinite(value.updatedAt)
      || value.updatedAt < value.createdAt || value.sessionId.trim() === "") {
      return null;
    }

    const status = value.status as RideModeStatus;
    const currentLeg = value.journey.legs[currentLegIndex];
    const currentState = value.currentLegState as RideModeLegState;
    if (status === "PLANNED" && (currentLegIndex !== 0 || currentState !== legInitialState(value.journey.legs[0]))) return null;
    if (status === "COMPLETED" && (currentLegIndex !== value.journey.legs.length - 1
      || currentLeg.type !== "WALK_EGRESS" || currentState !== "ARRIVED")) return null;
    if (status !== "COMPLETED" && currentState === "ARRIVED") return null;
    if (currentLeg.type === "RIDE") {
      if (!["WAITING_TO_BOARD", "RIDING"].includes(currentState)) return null;
    } else if (status !== "COMPLETED" && !["NOT_STARTED", "WALKING"].includes(currentState)) {
      return null;
    }

    return value as unknown as RideModeSession;
  } catch {
    return null;
  }
}

export function inspectRideModeSession(raw: string | null): {
  session: RideModeSession | null;
  writable: boolean;
  problem: string | null;
} {
  if (raw === null) return { session: null, writable: true, problem: null };
  const session = parseRideModeSession(raw);
  if (session) return { session, writable: true, problem: null };
  return {
    session: null,
    writable: false,
    problem: "Saved Ride Mode progress could not be read safely. Existing local data was preserved; a new session cannot replace it.",
  };
}

export function hasActiveRideModeSession(storage: Pick<Storage, "getItem">): boolean {
  try {
    const session = parseRideModeSession(storage.getItem(RIDE_MODE_STORAGE_KEY));
    return session?.status === "ACTIVE" || session?.status === "PLANNED";
  } catch {
    return false;
  }
}

export function classifyLocation(sample: RideModeLocationSample | null, now = Date.now()): LocationQuality {
  if (!sample
    || !Number.isFinite(sample.lat)
    || !Number.isFinite(sample.lng)
    || sample.lat < -90
    || sample.lat > 90
    || sample.lng < -180
    || sample.lng > 180
    || !Number.isFinite(sample.accuracy)
    || sample.accuracy < 0
    || !Number.isFinite(sample.timestamp)) {
    return "UNAVAILABLE";
  }

  const age = now - sample.timestamp;
  if (age > LOCATION_THRESHOLDS.staleAfterMilliseconds || age < -LOCATION_THRESHOLDS.futureToleranceMilliseconds) {
    return "STALE";
  }
  if (sample.accuracy <= LOCATION_THRESHOLDS.goodAccuracyMeters) {
    return "GOOD";
  }
  if (sample.accuracy <= LOCATION_THRESHOLDS.usableAccuracyMeters) {
    return "USABLE";
  }
  return "POOR";
}

export function isUsableLocationQuality(quality: LocationQuality): boolean {
  return quality === "GOOD" || quality === "USABLE";
}

export function distanceMeters(from: LocationPoint, to: LocationPoint): number {
  const latDelta = (to.lat - from.lat) * Math.PI / 180;
  const lngDelta = (to.lng - from.lng) * Math.PI / 180;
  const a = Math.sin(latDelta / 2) ** 2
    + Math.cos(from.lat * Math.PI / 180) * Math.cos(to.lat * Math.PI / 180) * Math.sin(lngDelta / 2) ** 2;
  return 6_371_008.8 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

type Coordinate = [number, number];
type Projection = {
  distanceToRouteMeters: number;
  alongRouteMeters: number;
  totalRouteMeters: number;
};

function geometryLines(geometry: JourneyLeg["geometry"]): Coordinate[][] {
  if (!geometry) {
    return [];
  }
  if (geometry.type === "LineString") {
    return [geometry.coordinates
      .filter((coordinate): coordinate is number[] => Array.isArray(coordinate) && coordinate.length >= 2)
      .map((coordinate) => [Number(coordinate[0]), Number(coordinate[1])] as Coordinate)
      .filter(([lng, lat]) => Number.isFinite(lng) && Number.isFinite(lat))];
  }
  return geometry.coordinates.map((line) => line
    .filter((coordinate): coordinate is number[] => Array.isArray(coordinate) && coordinate.length >= 2)
    .map((coordinate) => [Number(coordinate[0]), Number(coordinate[1])] as Coordinate)
    .filter(([lng, lat]) => Number.isFinite(lng) && Number.isFinite(lat)));
}

function localMeters(coordinate: Coordinate, reference: LocationPoint): [number, number] {
  const latitudeScale = 111_320;
  const longitudeScale = 111_320 * Math.cos(reference.lat * Math.PI / 180);
  return [
    (coordinate[0] - reference.lng) * longitudeScale,
    (coordinate[1] - reference.lat) * latitudeScale,
  ];
}

function projectToRoute(point: LocationPoint, geometry: JourneyLeg["geometry"]): Projection | null {
  const lines = geometryLines(geometry);
  let totalLength = 0;
  let best: Projection | null = null;

  for (const line of lines) {
    if (line.length < 2) {
      continue;
    }
    let lineLength = 0;
    let lineBestDistance = Number.POSITIVE_INFINITY;
    let lineBestAlong = 0;
    for (let index = 1; index < line.length; index += 1) {
      const start = localMeters(line[index - 1], point);
      const end = localMeters(line[index], point);
      const px = -start[0];
      const py = -start[1];
      const dx = end[0] - start[0];
      const dy = end[1] - start[1];
      const segmentLength = Math.hypot(dx, dy);
      if (segmentLength <= 0) {
        continue;
      }
      const ratio = Math.max(0, Math.min(1, (px * dx + py * dy) / (segmentLength * segmentLength)));
      const nearestX = start[0] + dx * ratio;
      const nearestY = start[1] + dy * ratio;
      const nearestDistance = Math.hypot(nearestX, nearestY);
      if (nearestDistance < lineBestDistance) {
        lineBestDistance = nearestDistance;
        lineBestAlong = lineLength + segmentLength * ratio;
      }
      lineLength += segmentLength;
    }

    if (Number.isFinite(lineBestDistance) && (best === null || lineBestDistance < best.distanceToRouteMeters)) {
      best = {
        distanceToRouteMeters: lineBestDistance,
        alongRouteMeters: totalLength + lineBestAlong,
        totalRouteMeters: totalLength + lineLength,
      };
    }
    totalLength += lineLength;
  }

  if (!best) {
    return null;
  }
  return { ...best, totalRouteMeters: totalLength };
}

function stableGuidancePhase(
  remainingMeters: number,
  previousPhase: RideModeGuidancePhase | null,
): RideModeGuidancePhase {
  const { approachingMeters, getReadyMeters, getOffAreaMeters, hysteresisMeters } = GET_OFF_THRESHOLDS;
  if (previousPhase === "GET_OFF_AREA" && remainingMeters <= getOffAreaMeters + hysteresisMeters) {
    return "GET_OFF_AREA";
  }
  if (previousPhase === "GET_READY" && remainingMeters <= getReadyMeters + hysteresisMeters) {
    return "GET_READY";
  }
  if (previousPhase === "APPROACHING" && remainingMeters <= approachingMeters + hysteresisMeters) {
    return "APPROACHING";
  }
  if (remainingMeters <= getOffAreaMeters) return "GET_OFF_AREA";
  if (remainingMeters <= getReadyMeters) return "GET_READY";
  if (remainingMeters <= approachingMeters) return "APPROACHING";
  return "RIDING";
}

function stopProgress(
  leg: JourneyLeg,
  currentAlong: number,
  accuracy: number,
): RideStopProgress | null {
  const targetSequence = leg.alighting_sequence;
  const boardingSequence = leg.boarding_sequence;
  const stops = leg.pattern?.stops ?? [];
  if (targetSequence === null || boardingSequence === null || stops.length === 0) {
    return null;
  }

  let currentSequence = boardingSequence;
  for (const patternStop of stops) {
    if (patternStop.sequence < boardingSequence || patternStop.sequence > targetSequence || !patternStop.stop.location) {
      continue;
    }
    const projection = projectToRoute(patternStop.stop.location, leg.geometry);
    if (projection && projection.alongRouteMeters <= currentAlong + Math.max(accuracy, 20)) {
      currentSequence = Math.max(currentSequence, patternStop.sequence);
    }
  }

  return {
    currentSequence,
    stopsRemaining: Math.max(0, targetSequence - currentSequence),
    targetSequence,
  };
}

function unavailableEvaluation(sample: RideModeLocationSample | null, quality: LocationQuality): RideModeLocationEvaluation {
  return {
    quality,
    accepted: false,
    sample,
    corridorState: "LOCATION_UNCERTAIN",
    guidancePhase: null,
    walkingProgress: null,
    distanceToTargetMeters: null,
    distanceBasis: "UNAVAILABLE",
    distanceToRouteMeters: null,
    remainingAlongRouteMeters: null,
    currentAlongRouteMeters: null,
    targetAlongRouteMeters: null,
    stopProgress: null,
    passedAlighting: false,
    phaseChanged: false,
  };
}

export function evaluateRideModeLocation(
  session: RideModeSession,
  sample: RideModeLocationSample | null,
  now = Date.now(),
  previousPhase: RideModeGuidancePhase | null = null,
): RideModeLocationEvaluation {
  const quality = classifyLocation(sample, now);
  if (!sample || !isUsableLocationQuality(quality)) {
    return unavailableEvaluation(sample, quality);
  }

  const leg = currentRideModeLeg(session);
  if (!leg) {
    return unavailableEvaluation(sample, "UNAVAILABLE");
  }

  if (leg.type !== "RIDE") {
    const target = leg.destination.location;
    if (!target) {
      return {
        ...unavailableEvaluation(sample, quality),
        accepted: true,
      };
    }
    const distance = distanceMeters(sample, target);
    return {
      quality,
      accepted: true,
      sample,
      corridorState: "LOCATION_UNCERTAIN",
      guidancePhase: null,
      walkingProgress: distance <= Math.max(40, sample.accuracy * 1.5) ? "NEAR_DESTINATION" : "IN_PROGRESS",
      distanceToTargetMeters: distance,
      distanceBasis: "STRAIGHT_LINE_APPROXIMATION",
      distanceToRouteMeters: null,
      remainingAlongRouteMeters: null,
      currentAlongRouteMeters: null,
      targetAlongRouteMeters: null,
      stopProgress: null,
      passedAlighting: false,
      phaseChanged: false,
    };
  }

  const target = leg.alighting_stop?.location ?? leg.destination.location;
  if (!target) {
    return { ...unavailableEvaluation(sample, quality), accepted: true };
  }

  const currentProjection = projectToRoute(sample, leg.geometry);
  const targetProjection = projectToRoute(target, leg.geometry);
  if (!currentProjection || !targetProjection) {
    const distance = distanceMeters(sample, target);
    const phase = stableGuidancePhase(distance, previousPhase);
    return {
      quality,
      accepted: true,
      sample,
      corridorState: "LOCATION_UNCERTAIN",
      guidancePhase: phase,
      walkingProgress: null,
      distanceToTargetMeters: distance,
      distanceBasis: "STRAIGHT_LINE_APPROXIMATION",
      distanceToRouteMeters: null,
      remainingAlongRouteMeters: null,
      currentAlongRouteMeters: null,
      targetAlongRouteMeters: null,
      stopProgress: null,
      passedAlighting: false,
      phaseChanged: phase !== previousPhase,
    };
  }

  const remaining = Math.max(0, targetProjection.alongRouteMeters - currentProjection.alongRouteMeters);
  const passedAlighting = currentProjection.alongRouteMeters > targetProjection.alongRouteMeters + Math.max(sample.accuracy, 25);
  const phase = stableGuidancePhase(remaining, previousPhase);
  const corridorLimit = Math.max(100, sample.accuracy * 2 + 50);
  const corridorState: CorridorState = currentProjection.distanceToRouteMeters <= corridorLimit
    ? "ON_EXPECTED_CORRIDOR"
    : currentProjection.distanceToRouteMeters <= corridorLimit + 150
      ? "NEAR_EXPECTED_CORRIDOR"
      : "POSSIBLY_OFF_ROUTE";

  return {
    quality,
    accepted: true,
    sample,
    corridorState,
    guidancePhase: phase,
    walkingProgress: null,
    distanceToTargetMeters: remaining,
    distanceBasis: "ALONG_ROUTE",
    distanceToRouteMeters: currentProjection.distanceToRouteMeters,
    remainingAlongRouteMeters: remaining,
    currentAlongRouteMeters: currentProjection.alongRouteMeters,
    targetAlongRouteMeters: targetProjection.alongRouteMeters,
    stopProgress: stopProgress(leg, currentProjection.alongRouteMeters, sample.accuracy),
    passedAlighting,
    phaseChanged: phase !== previousPhase,
  };
}

/** Attach optional ordered pattern stops without changing journey identity. */
export function withPatternStops(
  journey: Journey,
  patternStopsById: Record<string, RoutePatternStop[]>,
): Journey {
  return {
    ...journey,
    legs: journey.legs.map((leg) => {
      const patternId = leg.pattern?.id;
      const stops = patternId === undefined ? undefined : patternStopsById[String(patternId)];
      if (!leg.pattern || !stops || stops.length === 0) {
        return leg;
      }
      return { ...leg, pattern: { ...leg.pattern, stops } };
    }),
  };
}

export function journeyPatternIds(journey: Journey): number[] {
  return [...new Set(journey.legs
    .map((leg) => leg.pattern?.id)
    .filter((id): id is number => typeof id === "number"))];
}
