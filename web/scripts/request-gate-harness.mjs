import assert from "node:assert/strict";
import { RequestGate } from "../src/lib/requestGate.ts";

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

console.log("REQUEST_GATE_HARNESS=PASS superseded=aborted stale_result=rejected cancel=aborted");
