import assert from "node:assert/strict";
import { generateCommuteInstructions, instructionForRideMode } from "../src/lib/commuteInstructions.ts";
import {
  applyRideModeAction,
  canReviewPreviousRideModeStep,
  classifyLocation,
  createRideModeSession,
  evaluateRideModeLocation,
  inspectRideModeSession,
  offlineWalkingRequestIdentity,
  serializeRideModeSession,
  parseRideModeSession,
  replaceSessionJourney,
} from "../src/lib/rideMode.ts";

const verification = { state: "UNVERIFIED", is_demo: false, trusted: false };

function point(id, name, lat, lng, type = "PLACE") {
  return { type, id, name, location: { lat, lng }, verification };
}

function stop(id, name, lat, lng) {
  return {
    ...point(id, name, lat, lng, "STOP"),
    code: null,
    is_boarding_point: true,
    boarding_precision: "BOARDING_AREA",
    alighting_precision: "UNKNOWN",
  };
}

function walk(type, origin, destination) {
  return {
    type,
    origin,
    destination,
    distance_meters: 100,
    distance_is_estimate: false,
    distance_type: "PEDESTRIAN_NETWORK",
    routing_status: "ROUTE_FOUND",
    routing_source: "OPENSTREETMAP",
    access_classification: "NETWORK_ROUTE",
    estimated_duration_seconds: 90,
    origin_precision: null,
    destination_precision: null,
    warnings: [],
    instruction: "Follow the mapped walking path.",
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
    geometry: { type: "LineString", coordinates: [[origin.location.lng, origin.location.lat], [destination.location.lng, destination.location.lat]] },
  };
}

function ride(index, boarding, alighting, startLng, endLng) {
  return {
    type: "RIDE",
    origin: boarding,
    destination: alighting,
    distance_meters: 1000,
    distance_is_estimate: false,
    distance_type: null,
    routing_status: null,
    routing_source: null,
    access_classification: null,
    estimated_duration_seconds: null,
    origin_precision: "BOARDING_AREA",
    destination_precision: "UNKNOWN",
    warnings: [],
    instruction: "Board the displayed route in the displayed direction.",
    route: { id: index, code: `R${index}`, name: `Synthetic route ${index}`, vehicle_type: null, verification },
    pattern: { id: index, name: `Synthetic pattern ${index}`, direction: "OUTBOUND", verification },
    boarding_stop: boarding,
    alighting_stop: alighting,
    stop_count: 4,
    boarding_sequence: 1,
    alighting_sequence: 5,
    boarding_instruction: "Walk to the mapped boarding area.",
    boarding_association: verification,
    alighting_association: verification,
    driver_instruction: null,
    get_off_guidance: "Get off near the mapped area. The exact drop-off point is not verified.",
    trust: "MIXED",
    provenance: [],
    geometry: { type: "LineString", coordinates: [[startLng, boarding.location.lat], [(startLng + endLng) / 2, boarding.location.lat], [endLng, alighting.location.lat]] },
  };
}

function syntheticJourney(transfers) {
  const origin = point(1, "Origin", 10.3000, 123.8700);
  const destination = point(99, "Destination", 10.3000, 123.9200);
  const legs = [];
  let previous = origin;
  for (let index = 0; index <= transfers; index += 1) {
    const boarding = stop(10 + index * 3, `Boarding ${index + 1}`, 10.3000, 123.8750 + index * 0.012);
    const alighting = stop(11 + index * 3, `Alighting ${index + 1}`, 10.3000, 123.8830 + index * 0.012);
    legs.push(walk(index === 0 ? "WALK_ACCESS" : "TRANSFER_WALK", previous, boarding));
    legs.push(ride(index + 1, boarding, alighting, boarding.location.lng, alighting.location.lng));
    previous = alighting;
  }
  legs.push(walk("WALK_EGRESS", previous, destination));
  return {
    id: `synthetic-${transfers}`,
    origin,
    destination,
    legs,
    transfer_count: transfers,
    ranking: { walking_distance_meters: 100, ride_stop_count: (transfers + 1) * 4, policy: "synthetic", reasons: [] },
    trust: { state: "MIXED", warning: "Synthetic test journey only." },
    warnings: [],
    fare: { status: "UNAVAILABLE", label: "Fare unavailable", amount: null, currency: null },
    provenance: [],
  };
}

function transition(session, action, now) {
  const result = applyRideModeAction(session, action, now);
  assert.equal(result.error, null, `${action}: ${result.error}`);
  return result.session;
}

function runJourney(transfers) {
  const journey = syntheticJourney(transfers);
  const detail = generateCommuteInstructions(journey);
  assert.equal(detail.journeyId, journey.id);
  assert.equal(detail.summary.transfers, transfers);
  journey.legs.forEach((leg, index) => {
    if (leg.type !== "RIDE") return;
    const board = instructionForRideMode(detail, index, leg.type, "WAITING_TO_BOARD");
    const ride = instructionForRideMode(detail, index, leg.type, "RIDING");
    const alight = instructionForRideMode(detail, index, leg.type, "ARRIVED");
    assert.equal(board?.type, journey.legs[index - 1]?.type === "TRANSFER_WALK" ? "TRANSFER_BOARD" : "BOARD");
    assert.equal(board?.precision, "BOARDING_AREA");
    assert.equal(ride?.type, "RIDE");
    assert.deepEqual(ride?.geometry, leg.geometry, "map focus must use the selected journey's ride geometry");
    assert.deepEqual(ride?.focusLocation, leg.boarding_stop.location);
    assert.equal(alight?.type, "ALIGHT");
    assert.deepEqual(alight?.focusLocation, leg.alighting_stop.location);
    assert.match(alight?.uncertainty ?? "", /exact drop-off point or side is not verified/);
  });

  let session = createRideModeSession(journey, 1_000);
  session = transition(session, "ACTIVATE", 1_001);
  for (let index = 0; index < session.journey.legs.length; index += 1) {
    const leg = session.journey.legs[session.currentLegIndex];
    if (leg.type === "WALK_ACCESS" || leg.type === "TRANSFER_WALK") {
      session = transition(session, index === 0 ? "START_WALK" : "START_NEXT_LEG", 2_000 + index);
      session = transition(session, "AT_BOARDING_AREA", 2_100 + index);
    } else if (leg.type === "RIDE") {
      session = transition(session, "BOARDED", 3_000 + index);
      const target = leg.alighting_stop.location;
      const middle = { lat: target.lat, lng: leg.boarding_stop.location.lng + (target.lng - leg.boarding_stop.location.lng) * 0.5 };
      const evaluation = evaluateRideModeLocation(session, { ...middle, accuracy: 10, timestamp: 3_010 + index }, 3_011 + index);
      assert.equal(evaluation.accepted, true);
      assert.equal(evaluation.distanceBasis, "ALONG_ROUTE");
      session = transition(session, "GOT_OFF", 4_000 + index);
    } else {
      session = transition(session, "START_NEXT_LEG", 5_000 + index);
      session = transition(session, "ARRIVED", 5_100 + index);
    }
  }
  assert.equal(session.status, "COMPLETED");
  return session;
}

const completedSession = runJourney(0);
for (const transfers of [1, 2]) runJourney(transfers);

const direct = createRideModeSession(syntheticJourney(0), 10_000);
const identityJourney = syntheticJourney(0);
const identityBeforePreview = offlineWalkingRequestIdentity(identityJourney);
const previewIdentityJourney = {
  ...identityJourney,
  offline_walking_preview: { mode: "DEVELOPMENT_ONLY", graph_sha256: "pinned-fixture", graph_fingerprint: "fingerprint-fixture" },
  legs: identityJourney.legs.map((leg) => leg.type === "RIDE" ? leg : {
    ...leg,
    distance_meters: null,
    distance_type: "UNAVAILABLE",
    routing_source: "OPENSTREETMAP_OFFLINE_PREVIEW",
    geometry: null,
    offline_walking_preview: { reason_code: "component_disconnected" },
    warnings: [...leg.warnings, "No local graph walking path was established; no substitute line or connector is shown."],
  }),
};
assert.equal(offlineWalkingRequestIdentity(previewIdentityJourney), identityBeforePreview, "local preview output must not change the selected journey identity");
assert.notEqual(offlineWalkingRequestIdentity({ ...identityJourney, fare: { ...identityJourney.fare, label: "Changed fare evidence" } }), identityBeforePreview, "changed fare evidence must invalidate an in-flight preview");
const active = transition(direct, "ACTIVATE", 10_001);
assert.equal(canReviewPreviousRideModeStep(active), false, "there is no prior step before a journey starts");
let reviewSession = transition(active, "START_WALK", 10_002);
reviewSession = transition(reviewSession, "REVIEW_PREVIOUS_STEP", 10_003);
assert.equal(reviewSession.currentLegIndex, 0);
assert.equal(reviewSession.currentLegState, "NOT_STARTED");
reviewSession = transition(reviewSession, "START_WALK", 10_004);
reviewSession = transition(reviewSession, "AT_BOARDING_AREA", 10_005);
assert.equal(reviewSession.currentLegState, "WAITING_TO_BOARD");
reviewSession = transition(reviewSession, "REVIEW_PREVIOUS_STEP", 10_006);
assert.equal(reviewSession.currentLegIndex, 0);
assert.equal(reviewSession.currentLegState, "WALKING");
reviewSession = transition(reviewSession, "AT_BOARDING_AREA", 10_007);
reviewSession = transition(reviewSession, "BOARDED", 10_008);
assert.equal(reviewSession.currentLegState, "RIDING");
reviewSession = transition(reviewSession, "REVIEW_PREVIOUS_STEP", 10_009);
assert.equal(reviewSession.currentLegState, "WAITING_TO_BOARD");
const prematureBoarding = applyRideModeAction(active, "AT_BOARDING_AREA", 10_010);
assert.match(prematureBoarding.error ?? "", /Start the walking step/);
const cancelled = applyRideModeAction(active, "CANCEL", 10_002);
assert.equal(cancelled.error, null);
assert.equal(cancelled.session.status, "CANCELLED");
const rideIndex = active.journey.legs.findIndex((leg) => leg.type === "RIDE");
const atRide = { ...active, currentLegIndex: rideIndex, currentLegState: "RIDING" };
const ridingLeg = atRide.journey.legs[rideIndex];
const target = ridingLeg.alighting_stop.location;
const approach = { lat: target.lat, lng: target.lng - 0.0018 };
const approaching = evaluateRideModeLocation(atRide, { ...approach, accuracy: 10, timestamp: 10_100 }, 10_101);
assert.equal(approaching.accepted, true);
assert.ok(["APPROACHING", "GET_READY", "GET_OFF_AREA"].includes(approaching.guidancePhase));
const hysteresis = evaluateRideModeLocation(atRide, { lat: target.lat, lng: target.lng - 0.0020, accuracy: 10, timestamp: 10_102 }, 10_103, approaching.guidancePhase);
assert.ok(hysteresis.guidancePhase === approaching.guidancePhase || hysteresis.guidancePhase === "GET_READY");

const poor = evaluateRideModeLocation(atRide, { ...approach, accuracy: 150, timestamp: 10_200 }, 10_201);
assert.equal(poor.quality, "POOR");
assert.equal(poor.accepted, false);
const stale = evaluateRideModeLocation(atRide, { ...approach, accuracy: 10, timestamp: 1 }, 40_000);
assert.equal(stale.quality, "STALE");
assert.equal(stale.accepted, false);
const offRoute = evaluateRideModeLocation(atRide, { lat: target.lat + 0.01, lng: approach.lng, accuracy: 10, timestamp: 10_300 }, 10_301);
assert.equal(offRoute.corridorState, "POSSIBLY_OFF_ROUTE");
assert.equal(classifyLocation(null, 10_000), "UNAVAILABLE");

const restored = parseRideModeSession(serializeRideModeSession(active));
assert.equal(restored?.sessionId, active.sessionId);
assert.equal(inspectRideModeSession(serializeRideModeSession(active)).writable, true);
const corruptRideModeRaw = "{corrupt ride data";
const inspectedCorruptRide = inspectRideModeSession(corruptRideModeRaw);
assert.equal(inspectedCorruptRide.session, null);
assert.equal(inspectedCorruptRide.writable, false);
assert.match(inspectedCorruptRide.problem ?? "", /preserved/);
assert.equal(Object.prototype.hasOwnProperty.call(JSON.parse(serializeRideModeSession(active)), "location"), false);
assert.equal(parseRideModeSession(JSON.stringify({ ...active, currentLocation: { lat: 10.3, lng: 123.9 } })), null);
assert.equal(parseRideModeSession(JSON.stringify({ ...active, currentLegState: "RIDING" })), null, "persisted progress must match the current walking leg");
assert.equal(parseRideModeSession(JSON.stringify({ ...active, updatedAt: active.createdAt - 1 })), null, "timestamps cannot run backwards");
const changedRoute = { ...active.journey, legs: active.journey.legs.map((leg, index) => index === 0
  ? { ...leg, destination: { ...leg.destination, location: { lat: 10.35, lng: 123.91 } } } : leg) };
assert.equal(replaceSessionJourney(active, changedRoute, 10_010), active, "a detail refresh cannot silently switch the active route endpoints");
const unstartedFinalWalk = { ...active, currentLegIndex: active.journey.legs.length - 1, currentLegState: "NOT_STARTED" };
assert.equal(applyRideModeAction(unstartedFinalWalk, "ARRIVED", 10_011).error?.includes("Start the final walking step"), true, "arrival requires a started final walk");
assert.notEqual(parseRideModeSession(JSON.stringify(completedSession)), null, "valid completed sessions remain resumable for display");
assert.equal(parseRideModeSession(JSON.stringify({ ...completedSession, status: "ACTIVE" })), null, "ARRIVED is valid only for a completed final walking leg");
assert.equal(parseRideModeSession(JSON.stringify({ ...completedSession, currentLegState: "WALKING" })), null, "completed sessions must retain their final arrived state");

console.log("RIDE_MODE_HARNESS=PASS");
console.log("DIRECT=PASS ONE_TRANSFER=PASS TWO_TRANSFER=PASS");
console.log("QUALITY=GOOD/USABLE_ACCEPTED POOR/STALE_REJECTED OFF_ROUTE_CONSERVATIVE");
console.log("HYSTERESIS=PASS PERSISTENCE_NO_RAW_GPS=PASS SYNTHETIC_EVIDENCE_PROMOTION=0");
console.log("CORRUPT_SESSION_WRITE_GUARD=PASS EXISTING_DATA_PRESERVED=PASS");
console.log("RESULT_DETAIL_MAP_RIDE_MODE_HANDOFF=PASS boarding_uncertainty=preserved alighting_uncertainty=preserved");
