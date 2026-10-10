import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import {
  createOfflineWalkingPreviewState,
  offlineWalkingPreviewRequestIsCurrent,
  transitionOfflineWalkingPreview,
} from "../src/lib/offlineWalkingPreviewState.ts";

const idA = "journey-a";
const idB = "journey-b";
const complete = (identity, status = "COMPLETE", sourceMatches = true) => ({
  type: "RESPONSE_RECEIVED",
  identity,
  responseIdentity: sourceMatches ? identity : "different-source-identity",
  sourceMatches,
  status,
});

let state = createOfflineWalkingPreviewState(idA, false);
assert.equal(state.status, "DISABLED");
assert.equal(transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA }), state,
  "production and saved journeys cannot start a preview request");
assert.equal(offlineWalkingPreviewRequestIsCurrent(idA, idA, false), false);
assert.equal(offlineWalkingPreviewRequestIsCurrent(idA, idB, true), false);
assert.equal(offlineWalkingPreviewRequestIsCurrent(idA, idA, true, AbortSignal.abort()), false);

state = createOfflineWalkingPreviewState(idA, true);
assert.equal(state.status, "IDLE");
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
assert.equal(state.status, "LOADING");
state = transitionOfflineWalkingPreview(state, complete(idA));
assert.equal(state.status, "AVAILABLE");
assert.equal(state.responseStatus, "COMPLETE");

state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, complete(idA, "PARTIAL"));
assert.equal(state.status, "PARTIAL");
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, complete(idA, "UNAVAILABLE"));
assert.equal(state.status, "UNAVAILABLE");
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, complete(idA, "NO_WALKING_LEGS"));
assert.equal(state.status, "AVAILABLE");
assert.match(state.message ?? "", /no separate walking legs/);

state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, complete(idA, "COMPLETE", false));
assert.equal(state.status, "ERROR", "a response bound to a different journey/source is rejected");
assert.match(state.message ?? "", /did not match/);
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_FAILED", identity: idA, message: "graph unavailable: fixture" });
assert.equal(state.status, "ERROR");
assert.match(state.message ?? "", /graph unavailable/);
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_CANCELLED", identity: idA });
assert.equal(state.status, "STALE");
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idA });
assert.equal(state.status, "LOADING", "error and cancelled requests can retry");

state = transitionOfflineWalkingPreview(state, { type: "SELECTED_JOURNEY", identity: idB, enabled: true });
assert.equal(state.status, "STALE");
assert.equal(state.identity, idB);
const afterOldResponse = transitionOfflineWalkingPreview(state, complete(idA));
assert.equal(afterOldResponse, state, "a prior journey response cannot overwrite the current selected journey");
state = transitionOfflineWalkingPreview(state, { type: "REQUEST_STARTED", identity: idB });
state = transitionOfflineWalkingPreview(state, complete(idB));
assert.equal(state.status, "AVAILABLE");
state = transitionOfflineWalkingPreview(state, { type: "PREVIEW_REMOVED", identity: idB });
assert.equal(state.status, "IDLE");
state = transitionOfflineWalkingPreview(state, { type: "SELECTED_JOURNEY", identity: idB, enabled: false });
assert.equal(state.status, "DISABLED");

const component = await readFile(new URL("../src/components/CommuteCard.tsx", import.meta.url), "utf8");
assert.match(component, /return \(\) => \{\s*offlinePreviewRequest\.current\?\.abort\(\);\s*offlinePreviewRequest\.current = null;/,
  "unmount and request-context changes abort the owned preview request");
assert.match(component, /\[requestIdentity, canPreviewOfflineWalking\]/,
  "changing into saved or disabled preview context also cancels an in-flight request");
assert.ok(component.indexOf("if (!sourceMatches) return;") < component.indexOf("onOfflinePreview?.(result.journey, requestIdentity)"),
  "a source-mismatched response is discarded before the selected journey is updated");

console.log("OFFLINE_WALKING_PREVIEW_STATE_HARNESS=PASS");
console.log("STATES=DISABLED/IDLE/LOADING/AVAILABLE/PARTIAL/UNAVAILABLE/STALE/ERROR");
console.log("CANCELLATION=PASS IDENTITY_BINDING=PASS SOURCE_MISMATCH=PASS SAVED_CONTEXT=PASS UNMOUNT_CLEANUP=PASS RETRY=PASS REMOVE=PASS");
