import assert from "node:assert/strict";
import { explainJourney } from "../src/lib/journeyExplanation.ts";
import { journeyPointFeatures, journeyRouteFeatures } from "../src/lib/journeyMapPresentation.ts";
import { offlineWalkingRequestIdentity } from "../src/lib/rideMode.ts";
import { isJourneySnapshot, upsertSavedJourney } from "../src/lib/savedJourneys.ts";

const graphLine = { type: "LineString", coordinates: [[123.87, 10.3], [123.8701, 10.3]] };
const inventedLineThatMustNotRender = { type: "LineString", coordinates: [[123.87, 10.3], [123.871, 10.3]] };
const origin = { type: "STOP", id: 1, name: "Cebu stop", location: { lat: 10.3, lng: 123.87 }, verification: { state: "VERIFIED" } };
const destination = { ...origin, id: 2, name: "Destination", location: { lat: 10.31, lng: 123.88 } };
const baseJourney = {
  id: "preview-fixture",
  origin,
  destination,
  transfer_count: 1,
  ranking: { walking_distance_meters: 10, ride_stop_count: 2, policy: "fixture", reasons: [] },
  trust: { state: "UNVERIFIED", warning: "Some details are not field checked." },
  warnings: [],
  fare: { status: "UNAVAILABLE", label: "Fare unavailable", amount: null, currency: null },
  provenance: [],
  legs: [
    {
      type: "TRANSFER_WALK",
      origin,
      destination: { ...destination, id: 3, name: "Transfer area" },
      distance_meters: 10,
      distance_is_estimate: false,
      distance_type: "PEDESTRIAN_NETWORK",
      routing_status: "ROUTE_FOUND",
      routing_source: "OPENSTREETMAP_OFFLINE_PREVIEW",
      access_classification: "NETWORK_ROUTE",
      estimated_duration_seconds: 8,
      warnings: [],
      route: null,
      pattern: null,
      boarding_stop: null,
      alighting_stop: null,
      trust: null,
      provenance: [],
      geometry: graphLine,
      offline_walking_preview: { status: "ROUTE_FOUND", reason_code: null, field_safety_verified: false },
    },
    {
      type: "WALK_EGRESS",
      origin,
      destination,
      distance_meters: null,
      distance_is_estimate: true,
      distance_type: "UNAVAILABLE",
      routing_status: "ROUTE_UNAVAILABLE",
      warnings: ["No local graph walking path was established."],
      route: null,
      pattern: null,
      boarding_stop: null,
      alighting_stop: null,
      trust: null,
      provenance: [],
      geometry: inventedLineThatMustNotRender,
      offline_walking_preview: { status: "ROUTE_UNAVAILABLE", reason_code: "component_disconnected", field_safety_verified: false },
    },
    {
      type: "RIDE",
      origin,
      destination,
      distance_meters: null,
      distance_is_estimate: false,
      distance_type: null,
      routing_status: null,
      routing_source: null,
      access_classification: null,
      estimated_duration_seconds: null,
      warnings: [],
      route: { code: "fixture-route", name: "Fixture route", verification: { state: "VERIFIED" } },
      pattern: null,
      boarding_stop: null,
      alighting_stop: null,
      trust: null,
      provenance: [],
      geometry: graphLine,
    },
  ],
};

const routes = journeyRouteFeatures(baseJourney);
assert.equal(routes.length, 2, "only graph walking geometry and actual transit geometry should render");
assert.equal(routes.some((feature) => feature.id === "leg-1"), false, "an unavailable walk must never draw even if stale geometry is present");
assert.equal(routes.some((feature) => feature.id === "leg-0"), true);
assert.equal(journeyPointFeatures(baseJourney).some((feature) => feature.properties.label.includes("Transfer · unverified")), true);

const explanation = explainJourney(baseJourney);
assert.equal(explanation.limitations.some((item) => item.includes("disconnected components")), true);
assert.equal(explanation.limitations.some((item) => item.includes("Fare information is unavailable")), true);
assert.equal(explanation.limitations.some((item) => item.includes("does not verify a safe physical interchange")), true);
assert.equal(explanation.limitations.some((item) => item.includes("field checked")), true);
const identity = offlineWalkingRequestIdentity(baseJourney);
const enrichedPreview = {
  ...baseJourney,
  offline_walking_preview: { graph_sha256: "a".repeat(64) },
  legs: baseJourney.legs.map((leg) => leg.type === "RIDE" ? leg : ({
    ...leg,
    distance_meters: 999,
    geometry: graphLine,
    offline_walking_preview: { status: "ROUTE_FOUND" },
  })),
};
assert.equal(offlineWalkingRequestIdentity(enrichedPreview), identity, "preview evidence cannot invalidate its own request identity");
assert.notEqual(offlineWalkingRequestIdentity({
  ...baseJourney,
  legs: baseJourney.legs.map((leg) => leg.type === "RIDE" ? {
    ...leg,
    route: { ...leg.route, code: "changed-transit-route" },
  } : leg),
}), identity, "a changed transit leg must invalidate an in-flight preview identity");
assert.equal(offlineWalkingRequestIdentity({ ...baseJourney, legs: baseJourney.legs.map((leg, index) => index === 0
  ? { ...leg, destination: { ...leg.destination, location: { lat: 10.32, lng: 123.89 } } } : leg) }) === identity, false,
"changed route endpoints receive a different request identity");
assert.equal(isJourneySnapshot(enrichedPreview), false, "local preview results are never valid saved snapshots");
assert.throws(() => upsertSavedJourney([], enrichedPreview), /preview/i);

console.log("OFFLINE_WALKING_PREVIEW_HARNESS=PASS");
console.log("GRAPH_GEOMETRY_ONLY=PASS UNAVAILABLE_GEOMETRY_HIDDEN=PASS TRANSFER_UNVERIFIED=PASS FAILURE_REASON=PASS FARE_UNKNOWN=PASS");
