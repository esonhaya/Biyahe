import assert from "node:assert/strict";
import { isRequestCurrentForIdentity, RequestGate } from "../src/lib/requestGate.ts";

const gate = new RequestGate();
const first = gate.begin();
assert.equal(gate.isCurrent(first), true);

const second = gate.begin();
assert.equal(first.signal.aborted, true);
assert.equal(gate.isCurrent(first), false);
assert.equal(gate.isCurrent(second), true);

gate.cancel();
assert.equal(second.signal.aborted, true);
assert.equal(gate.isCurrent(second), false);

const afterCancel = gate.begin();
assert.equal(gate.isCurrent(afterCancel), true);
gate.finish(afterCancel);
assert.equal(gate.isCurrent(afterCancel), true);

const selectedGate = new RequestGate();
const selectedRequest = selectedGate.begin();
assert.equal(isRequestCurrentForIdentity(selectedGate, selectedRequest, "journey-a-v1", "journey-a-v1"), true);
assert.equal(isRequestCurrentForIdentity(selectedGate, selectedRequest, "journey-a-v1", "journey-b-v1"), false,
  "a current network response is still stale after the selected journey changes");
assert.equal(isRequestCurrentForIdentity(selectedGate, selectedRequest, "journey-a-v1", null), false,
  "a response cannot populate an unselected journey view");
const selectedRetry = selectedGate.begin();
assert.equal(selectedRequest.signal.aborted, true);
assert.equal(isRequestCurrentForIdentity(selectedGate, selectedRetry, "journey-b-v1", "journey-b-v1"), true);

console.log("REQUEST_GATE_HARNESS=PASS superseded=aborted stale_result=rejected cancel=aborted");
console.log("SELECTED_JOURNEY_IDENTITY=PASS stale_detail_response=rejected");
