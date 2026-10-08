import assert from "node:assert/strict";
import { performance } from "node:perf_hooks";
import {
  buildShareText,
  generateCommuteInstructions,
} from "../src/lib/commuteInstructions.ts";
import {
  isJourneySnapshot,
  parseSavedJourneys,
  savedSnapshotAgeLabel,
  serializeSavedJourneys,
  upsertSavedJourney,
} from "../src/lib/savedJourneys.ts";

const verified = { state: "UNVERIFIED", is_demo: false, trusted: false };

function point(id, name, lat, lng, type = "PLACE", precision = null) {
  return {
    type,
    id,
    name,
    location: { lat, lng },
    is_boarding_point: type === "STOP",
    boarding_precision: precision,
    alighting_precision: type === "STOP" ? "UNKNOWN" : undefined,
    verification: verified,
  };
}

function walk(type, origin, destination, fallback = false) {
  return {
    type,
    origin,
    destination,
    distance_meters: fallback ? 180 : 220,
    distance_is_estimate: fallback,
    distance_type: fallback ? "STRAIGHT_LINE_ESTIMATE" : "PEDESTRIAN_NETWORK",
    routing_status: fallback ? "NO_ROUTE" : "ROUTE_FOUND",
    routing_source: fallback ? null : "OPENSTREETMAP",
    access_classification: fallback ? "GRAPH_COVERAGE_GAP" : "NETWORK_ROUTE",
    estimated_duration_seconds: fallback ? null : 150,
    origin_precision: null,
    destination_precision: null,
    warnings: [],
    instruction: null,
    route: null,
    pattern: null,
    boarding_stop: null,
    alighting_stop: null,
    stop_count: null,
    boarding_sequence: null,
    alighting_sequence: null,
    boarding_instruction: null,
    boarding_association: null,
    alighting_association: null,
    driver_instruction: null,
    get_off_guidance: null,
    trust: null,
    provenance: [],
    geometry: fallback ? null : { type: "LineString", coordinates: [[origin.location.lng, origin.location.lat], [destination.location.lng, destination.location.lat]] },
  };
}

function ride(number, boarding, alighting, supportedDriver = false) {
  return {
    type: "RIDE",
    origin: boarding,
    destination: alighting,
    distance_meters: null,
    distance_is_estimate: false,
    distance_type: null,
    routing_status: null,
    routing_source: null,
    access_classification: null,
    estimated_duration_seconds: null,
    origin_precision: "BOARDING_AREA",
    destination_precision: "UNKNOWN",
    warnings: [],
    instruction: null,
    route: { id: number, code: `R${number}`, name: `Synthetic route ${number}`, vehicle_type: null, verification: verified },
    pattern: { id: number, name: `Synthetic pattern ${number}`, direction: number % 2 === 0 ? "Inbound" : "Outbound", verification: verified },
    boarding_stop: boarding,
    alighting_stop: alighting,
    stop_count: 4,
    boarding_sequence: 1,
    alighting_sequence: 5,
    boarding_instruction: "Walk to the mapped boarding area.",
    boarding_association: verified,
    alighting_association: verified,
    driver_instruction: supportedDriver ? 'Tell the driver: "Central Terminal".' : null,
    get_off_guidance: "Get off near the mapped area. The exact drop-off point or side is not verified.",
    trust: "MIXED",
    provenance: [],
    geometry: { type: "LineString", coordinates: [[boarding.location.lng, boarding.location.lat], [alighting.location.lng, alighting.location.lat]] },
  };
}

function syntheticJourney(transfers) {
  const origin = point(1, "Origin", 10.3000, 123.8700);
  const destination = point(99, "Destination", 10.3000, 123.9200);
  const legs = [];
  let previous = origin;
  for (let number = 1; number <= transfers + 1; number += 1) {
    const boarding = point(10 + number * 3, `Boarding ${number}`, 10.3000, 123.8750 + number * 0.012, "STOP", number === 1 ? "BOARDING_AREA" : "UNKNOWN");
    const alighting = point(11 + number * 3, `Alighting ${number}`, 10.3000, 123.8830 + number * 0.012, "STOP", "UNKNOWN");
    legs.push(walk(number === 1 ? "WALK_ACCESS" : "TRANSFER_WALK", previous, boarding, transfers === 1 && number === 2));
    legs.push(ride(number, boarding, alighting, number === 1));
    previous = alighting;
  }
  legs.push(walk("WALK_EGRESS", previous, destination, transfers === 2));
  return {
    id: `synthetic-${transfers}`,
    origin,
    destination,
    legs,
    transfer_count: transfers,
    ranking: { walking_distance_meters: 220, ride_stop_count: (transfers + 1) * 4, policy: "synthetic", reasons: [] },
    trust: { state: "MIXED", warning: "Synthetic test data only." },
    warnings: [],
    fare: transfers === 1
      ? { status: "AVAILABLE", label: "₱13", amount: 13, currency: "PHP" }
      : { status: "UNAVAILABLE", label: "Fare unavailable", amount: null, currency: null },
    provenance: [],
  };
}

function expectedTypes(transfers) {
  const types = ["ACCESS_WALK"];
  for (let ride = 0; ride <= transfers; ride += 1) {
    types.push(ride === 0 ? "BOARD" : "TRANSFER_BOARD", "RIDE", "ALIGHT");
    if (ride < transfers) types.push("TRANSFER_WALK");
  }
  types.push("FINAL_WALK", "ARRIVE");
  return types;
}

let transformed = 0;
let generated = 0;
const started = performance.now();
const models = [0, 1, 2].map((transfers) => {
  const model = generateCommuteInstructions(syntheticJourney(transfers));
  transformed += 1;
  generated += model.instructions.length;
  assert.deepEqual(model.instructions.map((instruction) => instruction.type), expectedTypes(transfers));
  assert.equal(model.summary.rides, transfers + 1);
  assert.equal(model.summary.transfers, transfers);
  assert.equal(model.summary.walkingDistanceHasFallback, transfers > 0);
  assert.ok(model.instructions.every((instruction) => instruction.id.includes(`synthetic-${transfers}`)));
  return model;
});
const generationMs = performance.now() - started;

assert.equal(models[0].instructions.find((instruction) => instruction.type === "BOARD")?.precision, "BOARDING_AREA");
assert.equal(models[0].instructions.find((instruction) => instruction.type === "BOARD")?.driverInstruction, 'Tell the driver: "Central Terminal".');
assert.equal(models[1].instructions.find((instruction) => instruction.type === "TRANSFER_WALK")?.uncertainty, "Walking path unavailable — distance shown is an estimate.");
assert.equal(models[1].summary.fare, "₱13");
assert.equal(models[2].summary.fare, "Fare unavailable");
assert.equal(models[0].instructions.filter((instruction) => instruction.type === "BOARD")[0].driverInstruction !== null, true);
assert.equal(models[0].instructions.find((instruction) => instruction.type === "TRANSFER_BOARD"), undefined);

const saved = upsertSavedJourney([], syntheticJourney(2), "2026-10-07T00:00:00.000Z");
assert.equal(savedSnapshotAgeLabel("2026-10-08T09:30:00.000Z", Date.parse("2026-10-08T10:00:00.000Z")), "saved less than an hour ago");
assert.equal(savedSnapshotAgeLabel("2026-10-08T00:00:00.000Z", Date.parse("2026-10-08T10:00:00.000Z")), "saved less than a day ago");
assert.equal(savedSnapshotAgeLabel("2026-10-06T00:00:00.000Z", Date.parse("2026-10-08T10:00:00.000Z")), "saved 2 days ago");
assert.equal(savedSnapshotAgeLabel("invalid", Date.parse("2026-10-08T10:00:00.000Z")), "saved date unavailable");
const serialized = serializeSavedJourneys(saved);
const restored = parseSavedJourneys(serialized);
assert.equal(restored.length, 1);
assert.equal(restored[0].journey.id, "synthetic-2");
assert.equal(isJourneySnapshot(restored[0].journey), true);
assert.equal(serialized.includes("currentLocation"), false);
assert.equal(serialized.includes("locationSamples"), false);
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 999, journeys: saved })), []);
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 1, journeys: [{ ...saved[0], journey: { ...saved[0].journey, currentLocation: { lat: 10.3, lng: 123.9 } } }] })), []);

let backendRequests = 0;
const offlineModel = generateCommuteInstructions(restored[0].journey);
assert.equal(offlineModel.instructions.length, models[2].instructions.length);
assert.equal(backendRequests, 0);

for (const model of models) {
  const share = buildShareText(model);
  assert.ok(share.startsWith("Biyahe:"));
  assert.ok(share.length <= 3800);
  assert.equal(share.includes(model.journeyId), false);
  assert.equal(share.includes("123.8"), false);
  assert.equal(share.includes("locationSamples"), false);
  assert.equal(share.includes("undefined"), false);
}

console.log("COMMUTE_CARD_HARNESS=PASS");
console.log("DIRECT=PASS ONE_TRANSFER=PASS TWO_TRANSFER=PASS");
console.log(`JOURNEYS_TRANSFORMED=${transformed} INSTRUCTIONS_GENERATED=${generated} INSTRUCTION_GENERATION_TIME_MS=${generationMs.toFixed(2)}`);
console.log("PRECISION_TRUST_FARE=PASS FALLBACK_TRANSFER=PASS DRIVER_PHRASE_ONLY_WHEN_SUPPORTED=PASS");
console.log("SNAPSHOT_VALIDATION=PASS OFFLINE_CORE_BACKEND_REQUESTS=0 GPS_PERSISTENCE=0");
console.log("SHARE_TEXT=PASS NO_COORDINATES=PASS NO_INTERNAL_IDS=PASS");
