import assert from "node:assert/strict";
import { ApiHttpError, canIRide, planJourney, replanJourney, searchPlaces } from "../src/lib/api.ts";
import { ApiRequestTimeoutError, fetchWithTimeout } from "../src/lib/fetchWithTimeout.ts";

const originalFetch = globalThis.fetch;
const jsonResponse = (value) => new Response(JSON.stringify(value), {
  status: 200,
  headers: { "content-type": "application/json" },
});
const verification = { state: "UNVERIFIED", is_demo: false, trusted: false };
const point = (id, name, type = "PLACE") => ({ type, id, name, location: null, verification });

function journeyLeg(type, origin, destination, rideNumber = null) {
  const isRide = type === "RIDE";
  return {
    type,
    origin,
    destination,
    distance_meters: isRide ? null : 125,
    distance_is_estimate: false,
    distance_type: isRide ? null : "PEDESTRIAN_NETWORK",
    routing_status: isRide ? null : "ROUTE_FOUND",
    routing_source: isRide ? null : "OPENSTREETMAP",
    access_classification: isRide ? null : "NETWORK_ROUTE",
    estimated_duration_seconds: isRide ? null : 75,
    origin_precision: null,
    destination_precision: null,
    warnings: [],
    instruction: null,
    route: isRide ? {
      id: rideNumber,
      code: `SYN-${rideNumber}`,
      name: `Synthetic route ${rideNumber}`,
      vehicle_type: null,
      verification,
    } : null,
    pattern: isRide ? {
      id: rideNumber,
      name: `Synthetic pattern ${rideNumber}`,
      direction: "Synthetic direction",
      verification,
    } : null,
    boarding_stop: isRide ? origin : null,
    alighting_stop: isRide ? destination : null,
    transfer: null,
    stop_count: isRide ? 4 : null,
    boarding_sequence: isRide ? 1 : null,
    alighting_sequence: isRide ? 4 : null,
    boarding_instruction: isRide ? "Walk to the mapped boarding area." : null,
    boarding_association: isRide ? verification : null,
    alighting_association: isRide ? verification : null,
    driver_instruction: null,
    get_off_guidance: isRide ? "Get off near the mapped area; exact drop-off is unverified." : null,
    trust: isRide ? "UNVERIFIED" : null,
    provenance: [],
    geometry: null,
  };
}

function journeyResponse({ transfers = 0, originId = 1, destinationId = 2, fareAvailable = false } = {}) {
  const origin = point(originId, "Synthetic origin");
  const destination = point(destinationId, "Synthetic destination");
  const legs = [];
  let previous = origin;
  for (let rideNumber = 1; rideNumber <= transfers + 1; rideNumber += 1) {
    const boarding = point(10 + rideNumber * 2, `Synthetic boarding ${rideNumber}`, "STOP");
    const alighting = point(11 + rideNumber * 2, `Synthetic alighting ${rideNumber}`, "STOP");
    legs.push(journeyLeg(rideNumber === 1 ? "WALK_ACCESS" : "TRANSFER_WALK", previous, boarding));
    legs.push(journeyLeg("RIDE", boarding, alighting, rideNumber));
    previous = alighting;
  }
  legs.push(journeyLeg("WALK_EGRESS", previous, destination));
  const journey = {
    id: `synthetic-${transfers}-transfer`,
    origin,
    destination,
    legs,
    transfer_count: transfers,
    ranking: { walking_distance_meters: 250, ride_stop_count: 4 * (transfers + 1), policy: "synthetic fixture", reasons: [] },
    trust: { state: "UNVERIFIED", warning: "Synthetic contract fixture only." },
    warnings: [],
    fare: fareAvailable
      ? { status: "AVAILABLE", label: "Fixture fare", amount: 20, currency: "PHP" }
      : { status: "UNAVAILABLE", label: "Fare unavailable", amount: null, currency: null },
    provenance: [],
  };
  return {
    data: [journey],
    meta: {
      origin,
      destination,
      result_count: 1,
      max_transfers: 2,
      pedestrian_routing: {},
      no_route: null,
    },
  };
}

function installFakeTimers() {
  const originalSetTimeout = globalThis.setTimeout;
  const originalClearTimeout = globalThis.clearTimeout;
  const scheduled = [];
  globalThis.setTimeout = (callback, delay) => {
    const timer = { callback, delay, cleared: false };
    scheduled.push(timer);
    return timer;
  };
  globalThis.clearTimeout = (timer) => {
    if (timer) timer.cleared = true;
  };
  return {
    scheduled,
    restore() {
      globalThis.setTimeout = originalSetTimeout;
      globalThis.clearTimeout = originalClearTimeout;
    },
  };
}

function rejectWhenAborted(signal) {
  return new Promise((_resolve, reject) => {
    const rejectAsAborted = () => reject(new DOMException("Request aborted", "AbortError"));
    if (signal.aborted) rejectAsAborted();
    else signal.addEventListener("abort", rejectAsAborted, { once: true });
  });
}

function trackedCallerSignal() {
  const listeners = new Set();
  const signal = {
    aborted: false,
    reason: undefined,
    added: 0,
    removed: 0,
    addEventListener(type, listener) {
      if (type === "abort") {
        this.added += 1;
        listeners.add(listener);
      }
    },
    removeEventListener(type, listener) {
      if (type === "abort") {
        this.removed += 1;
        listeners.delete(listener);
      }
    },
  };
  return {
    signal,
    abort() {
      signal.aborted = true;
      for (const listener of listeners) listener();
    },
  };
}

let fakeTimers = null;
try {
  globalThis.fetch = async () => jsonResponse({
    data: [{
      id: 1,
      name: "Carbon Market",
      slug: "carbon-market",
      category: "LANDMARK",
      location: null,
      verification: { state: "UNVERIFIED", is_demo: false, trusted: false },
    }],
  });
  assert.equal((await searchPlaces("Carbon"))[0].name, "Carbon Market");

  globalThis.fetch = async () => new Response("{broken JSON", { status: 200 });
  await assert.rejects(searchPlaces("Carbon"), /Malformed JSON response from \/places\/search/);

  globalThis.fetch = async () => new Response(JSON.stringify({ message: "synthetic server failure" }), { status: 503 });
  await assert.rejects(searchPlaces("private query text"), (error) => {
    assert.ok(error instanceof ApiHttpError);
    assert.equal(error.status, 503);
    assert.equal(error.path, "/places/search");
    assert.equal(error.message.includes("private query text"), false, "diagnostics do not retain search text");
    return true;
  });

  globalThis.fetch = async () => jsonResponse({ data: [{ id: "not-an-id", name: "Malformed result" }] });
  await assert.rejects(searchPlaces("Carbon"), /Invalid API response from \/places\/search/);

  globalThis.fetch = async () => jsonResponse({
    data: [],
    meta: {
      origin: point(1, "Synthetic origin"),
      destination: point(2, "Synthetic destination"),
      result_count: 0,
      max_transfers: 2,
      pedestrian_routing: {},
      no_route: { code: "NO_KNOWN_JOURNEY", message: "No supported journey was returned." },
    },
  });
  assert.deepEqual((await planJourney(1, 2)).data, [], "a valid empty route result stays a valid no-route response");

  globalThis.fetch = async () => jsonResponse(journeyResponse());
  const directJourney = await planJourney(1, 2);
  assert.equal(directJourney.data[0].transfer_count, 0, "a synthetic direct journey with unknown fare and missing walking geometry remains valid");
  assert.equal(directJourney.data[0].legs[0].geometry, null, "missing geometry stays explicitly unavailable");
  assert.equal(directJourney.data[0].legs[0].estimated_duration_seconds, 75);

  globalThis.fetch = async () => jsonResponse(journeyResponse({ transfers: 1, fareAvailable: true }));
  const oneTransferJourney = await planJourney(1, 2);
  assert.equal(oneTransferJourney.data[0].transfer_count, 1, "a synthetic transfer journey must pass the same complete contract");
  assert.equal(oneTransferJourney.data[0].fare.amount, 20);

  const withDisruption = journeyResponse();
  withDisruption.data[0].disruptions = [{
    advisory: {
      stable_id: "synthetic-advisory",
      headline: "Synthetic route notice",
      description: "Synthetic fixture only.",
      category: "ROAD_CLOSURE",
      severity: "MAJOR",
      source_class: "OFFICIAL",
      starts_at: null,
      ends_at: null,
      source: null,
    },
    impact_level: "AFFECTED",
    warning: "Synthetic fixture only.",
    affected_leg_index: 1,
    affected_leg_type: "RIDE",
    matched_by: ["ROUTE"],
    affected_legs: [{ index: 1, type: "RIDE", matched_by: ["ROUTE"] }],
  }];
  globalThis.fetch = async () => jsonResponse(withDisruption);
  assert.equal((await planJourney(1, 2)).data[0].disruptions[0].advisory.stable_id, "synthetic-advisory");

  const malformedDisruption = journeyResponse();
  malformedDisruption.data[0].disruptions = [{}];
  globalThis.fetch = async () => jsonResponse(malformedDisruption);
  await assert.rejects(planJourney(1, 2), /Invalid API response from \/journeys/,
    "malformed nested disruption evidence cannot reach the commuter card");

  globalThis.fetch = async () => jsonResponse(journeyResponse({ originId: 77 }));
  await assert.rejects(planJourney(1, 2), /requested place identity mismatch/);

  globalThis.fetch = async () => jsonResponse(journeyResponse({ destinationId: 77 }));
  await assert.rejects(replanJourney(1, 2), /requested place identity mismatch/);

  globalThis.fetch = async () => jsonResponse({
    data: [{}],
    meta: {
      origin: point(1, "Synthetic origin"),
      destination: point(2, "Synthetic destination"),
      result_count: 1,
      max_transfers: 2,
      pedestrian_routing: {},
      no_route: null,
    },
  });
  await assert.rejects(planJourney(1, 2), /Invalid API response from \/journeys/);

  const partialJourney = journeyResponse();
  delete partialJourney.data[0].legs[1].routing_source;
  globalThis.fetch = async () => jsonResponse(partialJourney);
  await assert.rejects(planJourney(1, 2), /Invalid API response from \/journeys/,
    "a partial leg cannot be treated as a successful journey");

  globalThis.fetch = async () => jsonResponse({
    data: {
      origin: point(1, "Synthetic origin"),
      destination: point(2, "Synthetic destination"),
      status: "UNKNOWN",
      message: "There is not enough information.",
      reason_code: "UNKNOWN_DATA",
      route: null,
      option: null,
      opposite_direction: null,
      trust: null,
      warnings: [],
      disruptions: [],
      provenance: [],
    },
    meta: {
      distance_unit: "meters",
      walking_distance_type: "straight_line_estimate",
      pedestrian_leg_distance_type: "per_leg",
      access_radius_meters: 1000,
    },
  });
  assert.equal((await canIRide("R12", 1, 2)).data.status, "UNKNOWN");

  globalThis.fetch = async () => jsonResponse({
    data: {
      origin: point(77, "Wrong synthetic origin"),
      destination: point(2, "Synthetic destination"),
      status: "UNKNOWN",
      message: "There is not enough information.",
      reason_code: "UNKNOWN_DATA",
      route: null,
      option: null,
      opposite_direction: null,
      trust: null,
      warnings: [],
      disruptions: [],
      provenance: [],
    },
    meta: {
      distance_unit: "meters",
      walking_distance_type: "straight_line_estimate",
      pedestrian_leg_distance_type: "per_leg",
      access_radius_meters: 1000,
    },
  });
  await assert.rejects(canIRide("R12", 1, 2), /requested place identity mismatch/);

  globalThis.fetch = async () => jsonResponse({ data: { status: "YES" }, meta: {} });
  await assert.rejects(canIRide("R12", 1, 2), /Invalid API response from \/can-i-ride/);

  fakeTimers = installFakeTimers();
  globalThis.fetch = (_input, init) => rejectWhenAborted(init.signal);
  const headerTimeout = fetchWithTimeout("http://127.0.0.1/test", {}, async (response) => response, 37);
  const headerTimer = fakeTimers.scheduled.at(-1);
  assert.equal(headerTimer.delay, 37, "the configured deadline is scheduled exactly");
  headerTimer.callback();
  await assert.rejects(headerTimeout, (error) => {
    assert.ok(error instanceof ApiRequestTimeoutError);
    return true;
  });
  assert.equal(headerTimer.cleared, true, "the timeout handle is released after timeout");

  let bodyStartedResolve;
  const bodyStarted = new Promise((resolve) => { bodyStartedResolve = resolve; });
  globalThis.fetch = async (_input, init) => ({
    json: () => {
      bodyStartedResolve();
      return rejectWhenAborted(init.signal);
    },
  });
  const bodyTimeout = fetchWithTimeout("http://127.0.0.1/test", {}, (response) => response.json(), 83);
  await bodyStarted;
  const bodyTimer = fakeTimers.scheduled.at(-1);
  assert.equal(bodyTimer.delay, 83);
  bodyTimer.callback();
  await assert.rejects(bodyTimeout, (error) => {
    assert.ok(error instanceof ApiRequestTimeoutError, "the timeout remains active while reading the response body");
    return true;
  });
  assert.equal(bodyTimer.cleared, true);

  const caller = trackedCallerSignal();
  globalThis.fetch = (_input, init) => rejectWhenAborted(init.signal);
  const callerCancelled = fetchWithTimeout("http://127.0.0.1/test", { signal: caller.signal }, async (response) => response, 1000);
  const callerTimer = fakeTimers.scheduled.at(-1);
  caller.abort();
  await assert.rejects(callerCancelled, (error) => error?.name === "AbortError");
  assert.equal(callerTimer.cleared, true);
  assert.equal(caller.signal.added, 1);
  assert.equal(caller.signal.removed, 1, "caller abort listeners are removed after cancellation");

  const successCaller = trackedCallerSignal();
  globalThis.fetch = async () => jsonResponse({ ok: true });
  const successfulRequest = fetchWithTimeout("http://127.0.0.1/test", { signal: successCaller.signal }, (response) => response.json());
  const successfulTimer = fakeTimers.scheduled.at(-1);
  assert.equal(successfulTimer.delay, 20_000, "the shared API timeout defaults to twenty seconds");
  assert.deepEqual(await successfulRequest, { ok: true });
  assert.equal(successfulTimer.cleared, true, "successful requests release their timeout handle");
  assert.equal(successCaller.signal.added, 1);
  assert.equal(successCaller.signal.removed, 1, "successful requests release caller abort listeners");
} finally {
  globalThis.fetch = originalFetch;
  fakeTimers?.restore();
}

console.log("API_FAILURE_HARNESS=PASS");
console.log("PLACE_CONTRACT=PASS DIRECT_AND_TRANSFER_FIXTURES=PASS EMPTY_RESULT=PASS PARTIAL_RESPONSE_REJECTED=PASS");
console.log("SOURCE_IDENTITY=PASS HTTP_DIAGNOSTICS=PASS MALFORMED_JSON=PASS");
console.log("HEADER_TIMEOUT=PASS BODY_TIMEOUT=PASS CALLER_CANCELLATION=PASS TIMER_AND_LISTENER_CLEANUP=PASS");
