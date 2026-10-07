import assert from "node:assert/strict";
import {
  FIELD_MODE_SCHEMA_VERSION,
  loadFieldOutbox,
  queueFieldObservation,
  removeFieldOutbox,
  saveFieldCache,
  loadFieldCache,
} from "../src/lib/fieldMode.ts";

const values = new Map();
const storage = {
  getItem: (key) => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, value),
  removeItem: (key) => values.delete(key),
};
const mission = { id: 1, stable_id: "b020-mission-carbon", title: "Carbon ground-truth mission", area: "Carbon", status: "PLANNED", priority: 10, route_scope: ["13B"], instructions: "Observe one narrow claim.", safety_notes: "Stay safe.", claim_checklist: ["route"], claims: [{ id: 1, stable_id: "b020-claim-1", claim_type: "BOARDING", target_type: "route_pattern_stop", target_stable_id: "pattern:stop", prompt: "Where can people board?", status: "OPEN", priority_score: 10, journeys_affected: 2, affected_od_pairs: [], route_code: "13B", direction: "OUTBOUND", stop_name: "Carbon", pattern_stable_id: "pattern", stop_stable_id: "stop", research_status: "FIELD_REQUIRED" }], session: null, offline_note: "Snapshot", schema_version: FIELD_MODE_SCHEMA_VERSION };
saveFieldCache(storage, [mission]);
assert.equal(loadFieldCache(storage).missions.length, 1);
const draft = { stable_id: "field-client-1", session_id: 4, claim_id: "b020-claim-1", type: "BOARDING_LOCATION", observed_at: new Date().toISOString(), fact: { observed_precision: "BOARDING_AREA_OBSERVED", description: "Unit 3" }, notes: null, schema_version: FIELD_MODE_SCHEMA_VERSION };
queueFieldObservation(storage, draft);
queueFieldObservation(storage, draft);
assert.equal(loadFieldOutbox(storage).length, 1);
removeFieldOutbox(storage, [draft.stable_id]);
assert.equal(loadFieldOutbox(storage).length, 0);
console.log("FIELD_MODE_HARNESS=PASS");
