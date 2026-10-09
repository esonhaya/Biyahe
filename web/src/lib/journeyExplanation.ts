import type { Journey, JourneyLeg } from "./api";

/**
 * Presentation-only explanation of the evidence already present on a journey.
 * This deliberately does not affect planner ranking or create new evidence.
 */
export type JourneyExplanation = {
  structure: string;
  plannerReasons: string[];
  evidence: string[];
  limitations: string[];
};

function hasDrawableGeometry(leg: JourneyLeg): boolean {
  const geometry = leg.geometry;
  if (!geometry || !Array.isArray(geometry.coordinates)) return false;
  if (geometry.type === "LineString") return geometry.coordinates.length >= 2;
  return geometry.coordinates.some((line) => Array.isArray(line) && line.length >= 2);
}

function unique(values: string[]): string[] {
  return [...new Set(values.map((value) => value.trim()).filter(Boolean))];
}

function verificationStates(journey: Journey): string[] {
  const states = [journey.origin.verification.state, journey.destination.verification.state];
  for (const leg of journey.legs) {
    if (leg.boarding_stop) states.push(leg.boarding_stop.verification.state);
    if (leg.alighting_stop) states.push(leg.alighting_stop.verification.state);
    if (leg.boarding_association) states.push(leg.boarding_association.state);
    if (leg.alighting_association) states.push(leg.alighting_association.state);
    if (leg.route) states.push(leg.route.verification.state);
    if (leg.pattern) states.push(leg.pattern.verification.state);
  }
  return states;
}

export function explainJourney(journey: Journey): JourneyExplanation {
  const rides = journey.legs.filter((leg) => leg.type === "RIDE");
  const walking = journey.legs.filter((leg) => leg.type !== "RIDE");
  const structure = rides.length === 0
    ? "Transit ride details unavailable"
    : journey.transfer_count === 0
      ? `Direct · ${rides.length} ride${rides.length === 1 ? "" : "s"}`
      : `${journey.transfer_count} transfer${journey.transfer_count === 1 ? "" : "s"} · ${rides.length} rides`;
  const evidence: string[] = [];
  const limitations: string[] = [];

  const routeCodes = unique(rides.map((leg) => leg.route?.code ?? ""));
  if (routeCodes.length > 0) evidence.push(`Route identifier${routeCodes.length === 1 ? "" : "s"} recorded: ${routeCodes.join(", ")}.`);
  if (rides.length > 0 && rides.every((leg) => Boolean(leg.pattern?.direction.trim()))) {
    evidence.push("A direction is recorded for each ride.");
  } else if (rides.length > 0) {
    limitations.push("Direction details are missing for one or more rides.");
  }

  if (walking.length === 0) {
    evidence.push("No separate walking leg is included in this journey.");
  } else {
    const networkWithGeometry = walking.filter((leg) => leg.distance_type === "PEDESTRIAN_NETWORK" && hasDrawableGeometry(leg)).length;
    const networkWithoutGeometry = walking.filter((leg) => leg.distance_type === "PEDESTRIAN_NETWORK" && !hasDrawableGeometry(leg)).length;
    const approximateConnectors = walking.filter((leg) => leg.distance_type === "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS").length;
    const straightLine = walking.filter((leg) => leg.distance_type === "STRAIGHT_LINE_ESTIMATE").length;
    const unavailable = walking.filter((leg) => leg.distance_type === "UNAVAILABLE" || leg.access_classification === "NO_WALKABLE_ACCESS" || leg.access_classification === "ROUTING_UNAVAILABLE").length;
    const coverageGap = walking.filter((leg) => leg.access_classification === "GRAPH_COVERAGE_GAP").length;

    if (networkWithGeometry > 0) evidence.push(`${networkWithGeometry} walking leg${networkWithGeometry === 1 ? "" : "s"} include mapped pedestrian path geometry.`);
    if (networkWithoutGeometry > 0) limitations.push(`${networkWithoutGeometry} mapped walking leg${networkWithoutGeometry === 1 ? " has" : "s have"} distance data but no drawable path line.`);
    if (approximateConnectors > 0) limitations.push("Some walking access uses approximate endpoint connectors; this is not fully graph-routed access.");
    if (straightLine > 0) limitations.push("Straight-line distance is context only and is not a walking path.");
    if (unavailable > 0) limitations.push("A walkable route could not be established for one or more legs.");
    if (coverageGap > 0) limitations.push("The pedestrian graph does not cover one or more walking endpoints.");
    if (walking.some((leg) => leg.distance_type === "PEDESTRIAN_NETWORK" && !hasDrawableGeometry(leg))) {
      limitations.push("Missing walking geometry is not replaced with a straight-line connector.");
    }
    const offlineFailures = walking.flatMap((leg) => {
      const reason = leg.offline_walking_preview?.reason_code;
      if (!reason) return [];
      const explanation = reason === "component_disconnected"
        ? "The local graph places these walking endpoints in disconnected components."
        : reason === "snap_unavailable"
          ? "An endpoint could not be safely snapped to a nearby walkable graph node."
          : reason === "endpoint_out_of_scope"
            ? "An endpoint is outside the verified local graph area."
            : reason === "resource_budget_exceeded"
              ? "The local graph route exceeded its bounded search budget."
              : reason === "invalid_endpoint"
                ? "A walking endpoint is missing valid coordinates."
                : "The local pedestrian graph could not establish this walking path.";
      return [`${explanation} (reason: ${reason})`];
    });
    limitations.push(...offlineFailures);
  }

  const missingRideGeometry = rides.filter((leg) => !hasDrawableGeometry(leg)).length;
  if (missingRideGeometry > 0) limitations.push(`Transit line geometry is unavailable for ${missingRideGeometry} ride${missingRideGeometry === 1 ? "" : "s"}; no line is inferred.`);

  if (journey.fare.status !== "AVAILABLE") limitations.push("Fare information is unavailable for this journey.");
  if (journey.transfer_count > 0 && journey.legs.some((leg) => leg.type === "TRANSFER_WALK")) {
    limitations.push("A mapped transfer connection does not verify a safe physical interchange.");
  }
  if (verificationStates(journey).includes("STALE")) limitations.push("One or more route or stop records are explicitly marked stale.");
  if (journey.trust.state !== "VERIFIED") {
    limitations.push(journey.trust.warning?.trim() || "Some route or stop details are not fully verified.");
  }

  const warnings = [
    ...journey.warnings,
    ...journey.legs.flatMap((leg) => leg.warnings),
  ];
  limitations.push(...warnings);

  return {
    structure,
    plannerReasons: unique(journey.ranking.reasons),
    evidence: unique(evidence),
    limitations: unique(limitations),
  };
}
