import assert from "node:assert/strict";
import { nearbyOutcomeMessage, requestNearbyCandidates } from "../src/lib/nearbyLookup.ts";

function providerWith(run) {
  return { getCurrentPosition: run };
}

let loads = 0;
const unsupported = await requestNearbyCandidates(null, async () => { loads += 1; return { data: [] }; });
assert.equal(unsupported.status, "unsupported");
assert.equal(loads, 0);
assert.match(nearbyOutcomeMessage(unsupported), /Use route search/);

const denied = await requestNearbyCandidates(providerWith((_success, failure) => failure({ code: 1 })), async () => {
  loads += 1;
  return { data: [] };
});
assert.equal(denied.status, "denied");
assert.match(nearbyOutcomeMessage(denied), /no location was saved/);
assert.equal(loads, 0);

const unavailable = await requestNearbyCandidates(providerWith((_success, failure) => failure({ code: 2 })), async () => ({ data: [] }));
assert.equal(unavailable.status, "unavailable");

let receivedCoordinates;
const locationProvider = providerWith((success, _failure, options) => {
  assert.deepEqual(options, { enableHighAccuracy: false, maximumAge: 60_000, timeout: 10_000 });
  success({ coords: { latitude: 10.292, longitude: 123.899 } });
});
const routes = [{ id: 12, code: "12L" }];
const found = await requestNearbyCandidates(locationProvider, async (latitude, longitude, signal) => {
  loads += 1;
  receivedCoordinates = [latitude, longitude];
  assert.equal(signal, undefined);
  return { data: routes };
});
assert.equal(found.status, "results");
assert.deepEqual(found.routes, routes);
assert.deepEqual(receivedCoordinates, [10.292, 123.899]);
assert.match(nearbyOutcomeMessage(found), /1 route near/);

const empty = await requestNearbyCandidates(locationProvider, async () => ({ data: [] }));
assert.equal(empty.status, "empty");
assert.match(nearbyOutcomeMessage(empty), /does not mean no service exists/);
const failure = await requestNearbyCandidates(locationProvider, async () => { throw new Error("offline"); });
assert.equal(failure.status, "error");
assert.match(nearbyOutcomeMessage(failure), /No location was saved/);

const controller = new AbortController();
let finishLocation;
let abortedLoadCalls = 0;
const pending = requestNearbyCandidates(providerWith((success) => { finishLocation = success; }), async () => {
  abortedLoadCalls += 1;
  return { data: routes };
}, controller.signal);
controller.abort();
finishLocation({ coords: { latitude: 10.3, longitude: 123.9 } });
assert.deepEqual(await pending, { status: "cancelled", routes: [] });
assert.equal(abortedLoadCalls, 0);

const requestController = new AbortController();
let resolveNearby;
const pendingRequest = requestNearbyCandidates(locationProvider, async () => new Promise((resolve) => {
  resolveNearby = resolve;
}), requestController.signal);
await new Promise((resolve) => setImmediate(resolve));
assert.equal(typeof resolveNearby, "function");
requestController.abort();
resolveNearby({ data: routes });
assert.deepEqual(await pendingRequest, { status: "cancelled", routes: [] });

console.log("NEARBY_HARNESS=PASS");
console.log("LOCATION_DENIAL_FALLBACK=PASS EPHEMERAL_REQUEST=PASS EMPTY_UNCERTAINTY=PASS NETWORK_FAILURE=PASS CANCEL=PASS LATE_RESPONSE_REJECTED=PASS");
