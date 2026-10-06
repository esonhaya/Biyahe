import type {
  Journey,
  JourneyLeg,
  JourneyPoint,
  JourneyTrustState,
  LocationPoint,
} from "./api";

export const COMMUTE_INSTRUCTION_MODEL_VERSION = 1;

export type CommuteInstructionType =
  | "ACCESS_WALK"
  | "BOARD"
  | "RIDE"
  | "ALIGHT"
  | "TRANSFER_WALK"
  | "TRANSFER_BOARD"
  | "FINAL_WALK"
  | "ARRIVE";

export type CommutePrecision =
  | "EXACT_BOARDING_POINT"
  | "BOARDING_AREA"
  | "APPROXIMATE_LOCATION"
  | "UNKNOWN"
  | null;

export type CommuteInstruction = {
  id: string;
  type: CommuteInstructionType;
  legIndex: number;
  primaryAction: string;
  secondaryDetail: string | null;
  locationName: string | null;
  distanceMeters: number | null;
  distanceType: JourneyLeg["distance_type"];
  estimatedDurationSeconds: number | null;
  routeCode: string | null;
  routeName: string | null;
  direction: string | null;
  precision: CommutePrecision;
  trust: JourneyTrustState | null;
  uncertainty: string | null;
  warnings: string[];
  driverInstruction: string | null;
  stopCount: number | null;
  geometry: JourneyLeg["geometry"];
  focusLocation: LocationPoint | null;
};

export type CommuteJourneySummary = {
  rides: number;
  transfers: number;
  walkingDistanceMeters: number;
  walkingDistanceHasFallback: boolean;
  routeCodes: string[];
  fare: string;
  trust: string;
};

export type CommuteJourneyModel = {
  version: 1;
  journeyId: string;
  title: string;
  originName: string;
  destinationName: string;
  summary: CommuteJourneySummary;
  instructions: CommuteInstruction[];
  warnings: string[];
};

const WALKING_NETWORK_TYPES = new Set([
  "PEDESTRIAN_NETWORK",
  "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS",
]);

function cleanText(value: string | null | undefined): string | null {
  if (typeof value !== "string") return null;
  const cleaned = value.replace(/[\u0000-\u001f\u007f]/g, " ").replace(/\s+/g, " ").trim();
  return cleaned.length > 0 ? cleaned : null;
}

function pointName(point: JourneyPoint | null | undefined, fallback: string): string {
  return cleanText(point?.name) ?? fallback;
}

function distanceLabel(meters: number | null): string | null {
  if (meters === null || !Number.isFinite(meters) || meters < 0) return null;
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
}

function durationLabel(seconds: number | null): string | null {
  if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) return null;
  return `about ${Math.max(1, Math.round(seconds / 60))} min estimated`;
}

function routeLabel(leg: JourneyLeg): string {
  return cleanText(leg.route?.code) ?? "the mapped route";
}

function directionLabel(leg: JourneyLeg): string | null {
  return cleanText(leg.pattern?.direction);
}

function precisionFor(point: JourneyPoint | null | undefined, fallback: CommutePrecision = "UNKNOWN"): CommutePrecision {
  const precision = point?.boarding_precision;
  if (precision === "EXACT_BOARDING_POINT" || precision === "BOARDING_AREA" || precision === "APPROXIMATE_LOCATION" || precision === "UNKNOWN") {
    return precision;
  }
  return fallback;
}

function alightingPrecisionFor(point: JourneyPoint | null | undefined): CommutePrecision {
  if (point?.alighting_precision === "UNKNOWN") return "UNKNOWN";
  return "UNKNOWN";
}

function fareLabel(journey: Journey): string {
  if (journey.fare.status !== "AVAILABLE") return "Fare unavailable";
  const label = cleanText(journey.fare.label);
  if (label) return label;
  if (journey.fare.amount !== null && Number.isFinite(journey.fare.amount)) {
    return `${journey.fare.currency ? `${journey.fare.currency} ` : ""}${journey.fare.amount}`;
  }
  return "Fare available";
}

export function precisionGuidance(precision: CommutePrecision, kind: "boarding" | "alighting"): string {
  if (kind === "alighting") {
    return precision === "EXACT_BOARDING_POINT"
      ? "Use the supported get-off point; local conditions may still vary."
      : "Your mapped get-off area is approximate; the exact drop-off point or side is not verified.";
  }

  switch (precision) {
    case "EXACT_BOARDING_POINT":
      return "A supported boarding point is mapped; follow the route cue and local conditions.";
    case "BOARDING_AREA":
      return "Board around this mapped area. The exact bay or curbside position is not confirmed.";
    case "APPROXIMATE_LOCATION":
      return "This mapped boarding location is approximate.";
    default:
      return "The exact boarding location has not been verified yet.";
  }
}

export function trustGuidance(state: JourneyTrustState | null): string | null {
  switch (state) {
    case "MIXED":
      return "Some route or stop details still need checking.";
    case "UNVERIFIED":
      return "Transport details are not fully verified yet.";
    default:
      return null;
  }
}

function walkingGeometry(leg: JourneyLeg): JourneyLeg["geometry"] {
  return WALKING_NETWORK_TYPES.has(leg.distance_type ?? "") ? leg.geometry : null;
}

function walkingDetail(leg: JourneyLeg): { detail: string; uncertainty: string | null } {
  if (leg.distance_type === "NOT_REQUIRED") {
    return { detail: "No separate walking leg is mapped.", uncertainty: null };
  }

  const distance = distanceLabel(leg.distance_meters);
  const duration = durationLabel(leg.estimated_duration_seconds);
  const distancePhrase = distance ? `Walk about ${distance}` : "Follow the mapped walking leg";

  if (leg.distance_type === "PEDESTRIAN_NETWORK") {
    return {
      detail: `${distancePhrase} on the mapped pedestrian path${duration ? ` · ${duration}` : ""}.`,
      uncertainty: null,
    };
  }

  if (leg.distance_type === "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS") {
    return {
      detail: `${distancePhrase} on the mapped pedestrian path${duration ? ` · ${duration}` : ""}.`,
      uncertainty: "The connection at one or both endpoints is approximate.",
    };
  }

  if (leg.access_classification === "GRAPH_COVERAGE_GAP") {
    return {
      detail: `${distancePhrase} is a straight-line estimate; walking access could not be confirmed from available map data.`,
      uncertainty: "Walking path unavailable — distance shown is an estimate.",
    };
  }

  if (leg.access_classification === "NO_WALKABLE_ACCESS") {
    return {
      detail: `${distancePhrase} is a straight-line estimate; mapped access is unresolved.`,
      uncertainty: "Walking path unavailable — distance shown is an estimate.",
    };
  }

  if (leg.access_classification === "ROUTING_UNAVAILABLE" || leg.routing_status === "NO_ROUTE") {
    return {
      detail: `${distancePhrase} is a straight-line estimate; a pedestrian path is not available right now.`,
      uncertainty: "Walking path unavailable — distance shown is an estimate.",
    };
  }

  return {
    detail: `${distancePhrase} is an estimate; no pedestrian route is shown.`,
    uncertainty: "Walking path unavailable — distance shown is an estimate.",
  };
}

function commonInstructionFields(leg: JourneyLeg, index: number, type: CommuteInstructionType): Pick<CommuteInstruction, "legIndex" | "distanceMeters" | "distanceType" | "estimatedDurationSeconds" | "routeCode" | "routeName" | "direction" | "trust" | "warnings" | "geometry"> {
  return {
    legIndex: index,
    distanceMeters: leg.distance_meters,
    distanceType: leg.distance_type,
    estimatedDurationSeconds: leg.estimated_duration_seconds,
    routeCode: leg.route?.code ?? null,
    routeName: leg.route?.name ?? null,
    direction: directionLabel(leg),
    trust: leg.trust,
    warnings: leg.warnings.map((warning) => cleanText(warning)).filter((warning): warning is string => warning !== null),
    geometry: type === "RIDE" ? leg.geometry : walkingGeometry(leg),
  };
}

function makeWalkInstruction(journey: Journey, leg: JourneyLeg, index: number, type: "ACCESS_WALK" | "TRANSFER_WALK" | "FINAL_WALK"): CommuteInstruction {
  const destinationName = pointName(leg.destination, type === "FINAL_WALK" ? journey.destination.name : "the mapped boarding area");
  const walking = walkingDetail(leg);
  const action = type === "ACCESS_WALK"
    ? `Walk to ${destinationName}.`
    : type === "TRANSFER_WALK"
      ? `Walk to the next boarding area near ${destinationName}.`
      : `Walk to ${destinationName}.`;
  const fields = commonInstructionFields(leg, index, type);

  return {
    id: `${journey.id}-leg-${index}-${type.toLowerCase()}`,
    type,
    primaryAction: action,
    secondaryDetail: walking.detail,
    locationName: destinationName,
    precision: null,
    uncertainty: walking.uncertainty,
    driverInstruction: null,
    stopCount: null,
    focusLocation: leg.destination.location,
    ...fields,
  };
}

function makeBoardInstruction(journey: Journey, leg: JourneyLeg, index: number, type: "BOARD" | "TRANSFER_BOARD"): CommuteInstruction {
  const boarding = leg.boarding_stop ?? leg.origin;
  const boardingName = pointName(boarding, "the mapped boarding area");
  const route = routeLabel(leg);
  const direction = directionLabel(leg);
  const directionPhrase = direction ? ` toward ${direction}` : "";
  const precision = precisionFor(boarding, "UNKNOWN");
  const fields = commonInstructionFields(leg, index, type);

  return {
    id: `${journey.id}-leg-${index}-${type.toLowerCase()}`,
    type,
    primaryAction: `Board ${route}${directionPhrase}.`,
    secondaryDetail: `Board around ${boardingName}.`,
    locationName: boardingName,
    precision,
    uncertainty: precisionGuidance(precision, "boarding"),
    driverInstruction: cleanText(leg.driver_instruction),
    stopCount: null,
    focusLocation: boarding.location,
    ...fields,
  };
}

function makeRideInstruction(journey: Journey, leg: JourneyLeg, index: number): CommuteInstruction {
  const route = routeLabel(leg);
  const direction = directionLabel(leg);
  const directionPhrase = direction ? ` toward ${direction}` : "";
  const stopDetail = leg.stop_count !== null && leg.stop_count > 0 && leg.boarding_sequence !== null && leg.alighting_sequence !== null
    ? `Your mapped get-off is ${leg.stop_count} stop${leg.stop_count === 1 ? "" : "s"} after boarding.`
    : null;
  const fields = commonInstructionFields(leg, index, "RIDE");

  return {
    id: `${journey.id}-leg-${index}-ride`,
    type: "RIDE",
    primaryAction: `Ride ${route}${directionPhrase}.`,
    secondaryDetail: stopDetail,
    locationName: null,
    precision: null,
    uncertainty: trustGuidance(leg.trust),
    driverInstruction: null,
    stopCount: leg.stop_count,
    focusLocation: leg.boarding_stop?.location ?? leg.origin.location,
    ...fields,
  };
}

function makeAlightInstruction(journey: Journey, leg: JourneyLeg, index: number): CommuteInstruction {
  const alighting = leg.alighting_stop ?? leg.destination;
  const alightingName = pointName(alighting, "the mapped get-off area");
  const precision = alightingPrecisionFor(alighting);
  const fields = commonInstructionFields(leg, index, "ALIGHT");
  const getOffGuidance = cleanText(leg.get_off_guidance);

  return {
    id: `${journey.id}-leg-${index}-alight`,
    type: "ALIGHT",
    primaryAction: `Get off around ${alightingName}.`,
    secondaryDetail: getOffGuidance ?? precisionGuidance(precision, "alighting"),
    locationName: alightingName,
    precision,
    uncertainty: precisionGuidance(precision, "alighting"),
    driverInstruction: null,
    stopCount: null,
    focusLocation: alighting.location,
    ...fields,
  };
}

export function generateCommuteInstructions(journey: Journey): CommuteJourneyModel {
  const instructions: CommuteInstruction[] = [];
  const routeCodes: string[] = [];
  let walkingDistanceMeters = 0;
  let walkingDistanceHasFallback = false;
  let rides = 0;

  journey.legs.forEach((leg, index) => {
    if (leg.type === "WALK_ACCESS") {
      const instruction = makeWalkInstruction(journey, leg, index, "ACCESS_WALK");
      instructions.push(instruction);
      if (leg.distance_meters !== null && leg.distance_type !== "NOT_REQUIRED") walkingDistanceMeters += Math.max(0, leg.distance_meters);
      if (leg.distance_type !== "NOT_REQUIRED" && !WALKING_NETWORK_TYPES.has(leg.distance_type ?? "")) walkingDistanceHasFallback = true;
      return;
    }

    if (leg.type === "TRANSFER_WALK") {
      const instruction = makeWalkInstruction(journey, leg, index, "TRANSFER_WALK");
      instructions.push(instruction);
      if (leg.distance_meters !== null && leg.distance_type !== "NOT_REQUIRED") walkingDistanceMeters += Math.max(0, leg.distance_meters);
      if (leg.distance_type !== "NOT_REQUIRED" && !WALKING_NETWORK_TYPES.has(leg.distance_type ?? "")) walkingDistanceHasFallback = true;
      return;
    }

    if (leg.type === "WALK_EGRESS") {
      const instruction = makeWalkInstruction(journey, leg, index, "FINAL_WALK");
      instructions.push(instruction);
      if (leg.distance_meters !== null && leg.distance_type !== "NOT_REQUIRED") walkingDistanceMeters += Math.max(0, leg.distance_meters);
      if (leg.distance_type !== "NOT_REQUIRED" && !WALKING_NETWORK_TYPES.has(leg.distance_type ?? "")) walkingDistanceHasFallback = true;
      return;
    }

    rides += 1;
    const previous = journey.legs[index - 1];
    const boardType = previous?.type === "TRANSFER_WALK" ? "TRANSFER_BOARD" : "BOARD";
    const boardInstruction = makeBoardInstruction(journey, leg, index, boardType);
    const rideInstruction = makeRideInstruction(journey, leg, index);
    const alightInstruction = makeAlightInstruction(journey, leg, index);
    instructions.push(boardInstruction, rideInstruction, alightInstruction);
    if (leg.route?.code && !routeCodes.includes(leg.route.code)) routeCodes.push(leg.route.code);
  });

  const lastLegIndex = Math.max(0, journey.legs.length - 1);
  instructions.push({
    id: `${journey.id}-arrive`,
    type: "ARRIVE",
    legIndex: lastLegIndex,
    primaryAction: "Arrived.",
    secondaryDetail: `You have reached ${pointName(journey.destination, "your destination")}.`,
    locationName: journey.destination.name,
    distanceMeters: null,
    distanceType: null,
    estimatedDurationSeconds: null,
    routeCode: null,
    routeName: null,
    direction: null,
    precision: null,
    trust: null,
    uncertainty: null,
    warnings: [],
    driverInstruction: null,
    stopCount: null,
    geometry: null,
    focusLocation: journey.destination.location,
  });

  return {
    version: 1,
    journeyId: journey.id,
    title: `${pointName(journey.origin, "Origin")} → ${pointName(journey.destination, "Destination")}`,
    originName: pointName(journey.origin, "Origin"),
    destinationName: pointName(journey.destination, "Destination"),
    summary: {
      rides,
      transfers: journey.transfer_count,
      walkingDistanceMeters,
      walkingDistanceHasFallback,
      routeCodes,
      fare: fareLabel(journey),
      trust: trustGuidance(journey.trust.state) ?? "Route details are supported by the current journey data.",
    },
    instructions,
    warnings: journey.warnings.map((warning) => cleanText(warning)).filter((warning): warning is string => warning !== null),
  };
}

export function instructionForRideMode(
  model: CommuteJourneyModel,
  legIndex: number,
  legType: JourneyLeg["type"],
  legState: string,
): CommuteInstruction | null {
  const candidates = model.instructions.filter((instruction) => instruction.legIndex === legIndex);
  if (legType === "RIDE") {
    if (legState === "WAITING_TO_BOARD") return candidates.find((instruction) => instruction.type === "BOARD" || instruction.type === "TRANSFER_BOARD") ?? null;
    if (legState === "RIDING") return candidates.find((instruction) => instruction.type === "RIDE") ?? null;
    return candidates.find((instruction) => instruction.type === "ALIGHT") ?? null;
  }
  const type = legType === "WALK_ACCESS" ? "ACCESS_WALK" : legType === "TRANSFER_WALK" ? "TRANSFER_WALK" : "FINAL_WALK";
  return candidates.find((instruction) => instruction.type === type) ?? null;
}

export function buildShareText(model: CommuteJourneyModel): string {
  const lines = model.instructions
    .filter((instruction) => instruction.type !== "ARRIVE")
    .map((instruction, index) => {
      const detail = instruction.secondaryDetail && instruction.type !== "RIDE" ? ` — ${instruction.secondaryDetail}` : "";
      return `${index + 1}. ${instruction.primaryAction}${detail}`;
    });
  const notes = model.instructions
    .flatMap((instruction) => [instruction.uncertainty, instruction.driverInstruction])
    .filter((note): note is string => note !== null)
    .filter((note, index, all) => all.indexOf(note) === index)
    .slice(0, 3);
  const noteLines = notes.length > 0 ? [`Note: ${notes.join(" ")}`] : [];
  const summary = `${model.summary.rides} ride${model.summary.rides === 1 ? "" : "s"}${model.summary.transfers > 0 ? ` · ${model.summary.transfers} transfer${model.summary.transfers === 1 ? "" : "s"}` : ""}`;
  const cleaned = [`Biyahe: ${model.title}`, summary, ...lines, ...noteLines]
    .map((line) => cleanText(line))
    .filter((line): line is string => line !== null)
    .join("\n") || "Biyahe journey instructions";
  return cleaned.length > 3800 ? `${cleaned.slice(0, 3797)}…` : cleaned;
}
