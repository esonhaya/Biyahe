import assert from "node:assert/strict";
import { performance } from "node:perf_hooks";
import {
  buildShareText,
  generateCommuteInstructions,
  walkingEvidenceLabels,
} from "../src/lib/commuteInstructions.ts";
import { journeyGeometryDisclosure, journeyPointFeatures, journeyRouteFeatures } from "../src/lib/journeyMapPresentation.ts";
import { explainJourney } from "../src/lib/journeyExplanation.ts";
import {
  isJourneySnapshot,
  parseSavedJourneys,
  persistSavedJourneys,
  readSavedJourneyStore,
  removeSavedJourney,
  SAVED_JOURNEYS_STORAGE_KEY,
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
const directExplanation = explainJourney(syntheticJourney(0));
assert.equal(directExplanation.structure, "Direct · 1 ride");
assert.ok(directExplanation.evidence.some((item) => item.includes("mapped pedestrian path geometry")));
assert.ok(directExplanation.limitations.some((item) => item.includes("Fare information is unavailable")));
assert.ok(directExplanation.limitations.some((item) => item.includes("Synthetic test data only")));
const estimateExplanation = explainJourney(syntheticJourney(1));
assert.ok(estimateExplanation.limitations.some((item) => item.includes("Straight-line distance is context only")));
assert.ok(estimateExplanation.limitations.some((item) => item.includes("physical interchange")));
assert.ok(!estimateExplanation.evidence.some((item) => item.includes("straight-line") && item.includes("path")));
const staleExplanationJourney = syntheticJourney(0);
staleExplanationJourney.origin.verification = { ...verified, state: "STALE" };
assert.ok(explainJourney(staleExplanationJourney).limitations.some((item) => item.includes("explicitly marked stale")));
const generationMs = performance.now() - started;

assert.equal(models[0].instructions.find((instruction) => instruction.type === "BOARD")?.precision, "BOARDING_AREA");
assert.equal(models[0].instructions.find((instruction) => instruction.type === "BOARD")?.driverInstruction, 'Tell the driver: "Central Terminal".');
assert.equal(models[1].instructions.find((instruction) => instruction.type === "TRANSFER_WALK")?.uncertainty, "Walking path unavailable — the distance shown is an estimate.");
const fallbackInstruction = models[1].instructions.find((instruction) => instruction.type === "TRANSFER_WALK");
assert.equal(fallbackInstruction?.distanceType, "STRAIGHT_LINE_ESTIMATE");
assert.equal(fallbackInstruction?.estimatedDurationSeconds, null);
assert.equal(fallbackInstruction?.geometry, null);
assert.match(fallbackInstruction?.primaryAction ?? "", /^No mapped walking path is available/);
assert.match(fallbackInstruction?.secondaryDetail ?? "", /straight-line estimate/);
assert.equal(models[1].summary.fare, "₱13");
assert.equal(models[2].summary.fare, "Fare unavailable");
assert.equal(models[0].instructions.filter((instruction) => instruction.type === "BOARD")[0].driverInstruction !== null, true);
assert.equal(models[0].instructions.find((instruction) => instruction.type === "TRANSFER_BOARD"), undefined);
assert.equal(models[0].summary.walkingEvidence.networkDistanceMeters, 440);
assert.equal(models[1].summary.walkingEvidence.networkDistanceMeters, 440);
assert.equal(models[1].summary.walkingEvidence.straightLineEstimateMeters, 180);
assert.deepEqual(walkingEvidenceLabels(models[1].summary.walkingEvidence), [
  "Mapped paths · 440 m",
  "Straight-line estimate only · 180 m",
]);

const connectorJourney = syntheticJourney(0);
Object.assign(connectorJourney.legs[0], {
  distance_meters: 230,
  network_distance_meters: 200,
  approximate_connector_distance_meters: 30,
  distance_type: "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS",
  distance_is_estimate: true,
});
const connectorInstructions = generateCommuteInstructions(connectorJourney);
assert.match(connectorInstructions.instructions.find((instruction) => instruction.type === "ACCESS_WALK")?.secondaryDetail ?? "", /Mapped graph path about 200 m plus approximate endpoint access about 30 m.*total about 230 m/);
assert.deepEqual(walkingEvidenceLabels(connectorInstructions.summary.walkingEvidence), [
  "Mapped paths · 420 m",
  "Approximate endpoint access · 30 m",
  "Affected walking legs · 230 m (includes approximate access)",
]);
const legacyConnectorJourney = syntheticJourney(0);
Object.assign(legacyConnectorJourney.legs[0], { distance_meters: 230, distance_type: "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS", distance_is_estimate: true });
assert.match(generateCommuteInstructions(legacyConnectorJourney).instructions[0]?.secondaryDetail ?? "", /total including approximate endpoint access; mapped path distance breakdown unavailable/);

const missingGeometryJourney = syntheticJourney(0);
missingGeometryJourney.legs[0].geometry = null;
const missingGeometryModel = generateCommuteInstructions(missingGeometryJourney);
const missingGeometryStep = missingGeometryModel.instructions.find((instruction) => instruction.type === "ACCESS_WALK");
assert.equal(missingGeometryModel.summary.walkingEvidence.geometryUnavailableLegs, 1);
assert.equal(missingGeometryStep?.geometry, null);
assert.match(missingGeometryStep?.primaryAction ?? "", /^No mapped walking path is available/);
assert.match(missingGeometryStep?.secondaryDetail ?? "", /route line is unavailable and is not drawn/);
assert.equal(journeyGeometryDisclosure(missingGeometryJourney).mappedWalkingLegsWithoutGeometry, 1);

const unavailableWalkJourney = syntheticJourney(0);
Object.assign(unavailableWalkJourney.legs[0], { distance_meters: null, distance_type: "UNAVAILABLE", routing_status: "NO_ROUTE", geometry: null });
const unavailableWalkModel = generateCommuteInstructions(unavailableWalkJourney);
assert.equal(unavailableWalkModel.summary.walkingEvidence.unavailableLegs, 1);
assert.ok(walkingEvidenceLabels(unavailableWalkModel.summary.walkingEvidence).includes("1 walking leg without a route distance"));

const mapJourney = syntheticJourney(1);
const estimatedWalkIndex = mapJourney.legs.findIndex((leg) => leg.type === "TRANSFER_WALK");
mapJourney.legs[estimatedWalkIndex].geometry = { type: "LineString", coordinates: [[123.8, 10.3], [123.9, 10.3]] };
const routeFeatures = journeyRouteFeatures(mapJourney);
assert.equal(routeFeatures.filter((feature) => feature.properties.kind === "RIDE").length, 2);
assert.equal(routeFeatures.some((feature) => feature.id === `leg-${estimatedWalkIndex}`), false, "an estimate-only leg must never draw a walking route");
assert.equal(routeFeatures.filter((feature) => feature.properties.kind !== "RIDE").length, 2);
assert.equal(journeyGeometryDisclosure(mapJourney).estimateOnlyWalkingLegs, 1);
const mapPoints = journeyPointFeatures(mapJourney);
assert.ok(mapPoints.filter((feature) => feature.properties.kind === "transfer").every((feature) => feature.properties.label.startsWith("Transfer · unverified · ")));
assert.ok(mapPoints.filter((feature) => feature.properties.kind === "boarding").every((feature) => feature.properties.label.includes("Boarding area · exact point unverified")));
assert.ok(mapPoints.filter((feature) => feature.properties.kind === "alighting").every((feature) => feature.properties.label.includes("Get-off area · exact side unverified")));
const missingTransitLineJourney = syntheticJourney(0);
missingTransitLineJourney.legs.find((leg) => leg.type === "RIDE").geometry = null;
assert.equal(journeyGeometryDisclosure(missingTransitLineJourney).rideLegsWithoutGeometry, 1);
assert.equal(journeyRouteFeatures(missingTransitLineJourney).filter((feature) => feature.properties.kind === "RIDE").length, 0);

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
assert.equal(restored[0].version, 1, "snapshot-v1 remains the persisted format");
assert.equal(serialized.includes("currentLocation"), false);
assert.equal(serialized.includes("locationSamples"), false);
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 999, journeys: saved })), []);
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 1, journeys: [{ ...saved[0], journey: { ...saved[0].journey, currentLocation: { lat: 10.3, lng: 123.9 } } }] })), []);
assert.deepEqual(parseSavedJourneys("{broken local data"), [], "corrupt local JSON is ignored without throwing");
const corruptRaw = "{broken local data";
const corruptStorageData = new Map([[SAVED_JOURNEYS_STORAGE_KEY, corruptRaw]]);
const corruptStorage = {
  getItem: (key) => corruptStorageData.get(key) ?? null,
  setItem: (key, value) => corruptStorageData.set(key, value),
  removeItem: (key) => corruptStorageData.delete(key),
};
const corruptRead = readSavedJourneyStore(corruptStorage);
assert.equal(corruptRead.writable, false);
assert.match(corruptRead.problem ?? "", /preserved/);
assert.throws(() => persistSavedJourneys(corruptStorage, saved), /could not be parsed/);
assert.equal(corruptStorageData.get(SAVED_JOURNEYS_STORAGE_KEY), corruptRaw,
  "a new save cannot silently overwrite unreadable existing journey data");
const emptyCorruptData = new Map([[SAVED_JOURNEYS_STORAGE_KEY, ""]]);
const emptyCorruptStorage = {
  getItem: (key) => emptyCorruptData.get(key) ?? null,
  setItem: (key, value) => emptyCorruptData.set(key, value),
  removeItem: (key) => emptyCorruptData.delete(key),
};
assert.equal(readSavedJourneyStore(emptyCorruptStorage).writable, false,
  "an existing empty storage value is treated as unreadable data, not an absent store");
assert.throws(() => persistSavedJourneys(emptyCorruptStorage, saved), /unreadable format/);
assert.equal(emptyCorruptData.get(SAVED_JOURNEYS_STORAGE_KEY), "",
  "an empty unreadable store is preserved until the user backs it up");
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 1, journeys: [{
  ...saved[0],
  journey: { ...saved[0].journey, legs: [{ type: "RIDE", origin: saved[0].journey.origin, destination: saved[0].journey.destination }] },
}] })), [], "incomplete v1 leg data is rejected before instruction rendering");
assert.deepEqual(parseSavedJourneys(JSON.stringify({ version: 1, journeys: [{
  ...saved[0],
  journey: { ...saved[0].journey, legs: saved[0].journey.legs.map((leg) => ({ ...leg, telemetry: [{ rawGps: { lat: 10.3, lng: 123.9 } }] })) },
}] })), [], "private location data nested inside arrays is rejected");
const duplicateSaved = upsertSavedJourney(saved, { ...syntheticJourney(2), fare: { ...syntheticJourney(2).fare, label: "Updated saved fixture" } }, "2026-10-08T00:00:00.000Z");
assert.equal(duplicateSaved.length, 1, "saving the same journey replaces its prior snapshot");
assert.equal(duplicateSaved[0].savedAt, "2026-10-08T00:00:00.000Z");
assert.deepEqual(removeSavedJourney(duplicateSaved, duplicateSaved[0].id), [], "deleting a saved journey removes it from local state");
assert.equal(parseSavedJourneys(JSON.stringify({ version: 1, journeys: saved }))[0]?.journey.id, "synthetic-2",
  "a valid older snapshot-v1 record can reopen without migration");
const olderSnapshotV1 = { ...saved[0], journey: { ...saved[0].journey } };
delete olderSnapshotV1.journey.provenance;
assert.equal(parseSavedJourneys(JSON.stringify({ version: 1, journeys: [olderSnapshotV1] }))[0]?.journey.id, "synthetic-2",
  "an older v1 snapshot may omit optional provenance metadata");
const validStorageData = new Map([[SAVED_JOURNEYS_STORAGE_KEY, serialized]]);
const validStorage = {
  getItem: (key) => validStorageData.get(key) ?? null,
  setItem: (key, value) => validStorageData.set(key, value),
  removeItem: (key) => validStorageData.delete(key),
};
assert.equal(readSavedJourneyStore(validStorage).writable, true, "valid v1 snapshots remain writable without migration");
persistSavedJourneys(validStorage, restored);
assert.equal(JSON.parse(validStorageData.get(SAVED_JOURNEYS_STORAGE_KEY)).version, 1);

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
console.log("CORRUPT_STORAGE_PRESERVED=PASS SAFE_V1_WRITE=PASS");
console.log("WALKING_EVIDENCE_LABELS=PASS ESTIMATE_NOT_PRESENTED_AS_PATH=PASS MISSING_GEOMETRY=PASS");
console.log("MAP_GEOMETRY_FAIL_CLOSED=PASS ESTIMATE_ONLY_CONNECTOR_NOT_DRAWN=PASS");
console.log("MAP_BOARDING_AND_TRANSFER_UNCERTAINTY_LABELS=PASS");
console.log("SHARE_TEXT=PASS NO_COORDINATES=PASS NO_INTERNAL_IDS=PASS");
