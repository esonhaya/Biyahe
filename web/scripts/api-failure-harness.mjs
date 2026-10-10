import assert from "node:assert/strict";
import { canIRide, planJourney, searchPlaces } from "../src/lib/api.ts";
import { ApiRequestTimeoutError, fetchWithTimeout } from "../src/lib/fetchWithTimeout.ts";

const originalFetch = globalThis.fetch;
const jsonResponse = (value) => new Response(JSON.stringify(value), {
  status: 200,
  headers: { "content-type": "application/json" },
});
const point = { id: 1, name: "Cebu place", location: null, verification: { state: "UNVERIFIED", is_demo: false, trusted: false } };

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

  globalThis.fetch = async () => jsonResponse({ data: [{ id: "not-an-id", name: "Malformed result" }] });
  await assert.rejects(searchPlaces("Carbon"), /Invalid API response from \/places\/search/);

  globalThis.fetch = async () => jsonResponse({
    data: [],
    meta: {
      origin: point,
      destination: { ...point, id: 2 },
      result_count: 0,
      max_transfers: 2,
      pedestrian_routing: {},
      no_route: { code: "NO_KNOWN_JOURNEY", message: "No supported journey was returned." },
    },
  });
  assert.deepEqual((await planJourney(1, 2)).data, [], "a valid empty route result stays a valid no-route response");

  globalThis.fetch = async () => jsonResponse({
    data: [{}],
    meta: {
      origin: point,
      destination: { ...point, id: 2 },
      result_count: 1,
      max_transfers: 2,
      pedestrian_routing: {},
      no_route: null,
    },
  });
  await assert.rejects(planJourney(1, 2), /Invalid API response from \/journeys/);

  globalThis.fetch = async () => jsonResponse({
    data: {
      origin: point,
      destination: { ...point, id: 2 },
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

  globalThis.fetch = async () => jsonResponse({ data: { status: "YES" }, meta: {} });
  await assert.rejects(canIRide("R12", 1, 2), /Invalid API response from \/can-i-ride/);

  globalThis.fetch = (_input, init) => new Promise((_resolve, reject) => {
    const signal = init?.signal;
    const rejectAsAborted = () => reject(new DOMException("Request aborted", "AbortError"));
    if (signal?.aborted) rejectAsAborted();
    else signal?.addEventListener("abort", rejectAsAborted, { once: true });
  });
  await assert.rejects(fetchWithTimeout("http://127.0.0.1/test", {}, async (response) => response, 5), (error) => {
    assert.ok(error instanceof ApiRequestTimeoutError);
    return true;
  });

  globalThis.fetch = (_input, init) => Promise.resolve({
    json: () => new Promise((_resolve, reject) => {
      init.signal.addEventListener("abort", () => reject(new DOMException("Body aborted", "AbortError")), { once: true });
    }),
  });
  await assert.rejects(fetchWithTimeout("http://127.0.0.1/test", {}, (response) => response.json(), 5), (error) => {
    assert.ok(error instanceof ApiRequestTimeoutError, "the timeout remains active while reading the response body");
    return true;
  });

  globalThis.fetch = (_input, init) => new Promise((_resolve, reject) => {
    const signal = init?.signal;
    const rejectAsAborted = () => reject(new DOMException("Request aborted", "AbortError"));
    if (signal?.aborted) rejectAsAborted();
    else signal?.addEventListener("abort", rejectAsAborted, { once: true });
  });
  const callerController = new AbortController();
  const callerCancelled = fetchWithTimeout("http://127.0.0.1/test", { signal: callerController.signal }, async (response) => response, 1000);
  callerController.abort();
  await assert.rejects(callerCancelled, (error) => error?.name === "AbortError");
} finally {
  globalThis.fetch = originalFetch;
}

console.log("API_FAILURE_HARNESS=PASS");
console.log("PLACE_CONTRACT=PASS JOURNEY_CONTRACT=PASS CAN_I_RIDE_CONTRACT=PASS HEADER_TIMEOUT=PASS BODY_TIMEOUT=PASS CALLER_CANCELLATION=PASS");
