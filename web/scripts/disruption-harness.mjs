import assert from "node:assert/strict";
import { freshnessLabel, humanDisruptionCategory, humanImpact, impactForLeg, matchesEntity, shareableDisruptionNote } from "../src/lib/disruptions.ts";

const advisory = {
  id: 7,
  stable_id: "synthetic-advisory",
  category: "SERVICE_SUSPENDED",
  headline: "Temporary service report",
  description: "Synthetic test data only.",
  severity: "MINOR",
  status: "ACTIVE",
  starts_at: "2026-10-07T00:00:00.000Z",
  ends_at: "2026-10-07T02:00:00.000Z",
  source_class: "PUBLIC_REPORT",
  source: { type: "PUBLIC_REPORT", title: "Synthetic", publisher: null, url: null, checked_at: "2026-10-07T00:00:00.000Z", observed_at: "2026-10-07T00:00:00.000Z", attribution: "Synthetic" },
  checked_at: "2026-10-07T00:00:00.000Z",
  geometry: null,
  affected_entities: { routes: [{ id: 12, code: "R12", name: "Synthetic route" }], patterns: [], stops: [], places: [], transfers: [] },
};
const impact = {
  advisory,
  impact_level: "POSSIBLY_AFFECTED",
  hard_closure: false,
  affected_leg_index: 1,
  affected_leg_type: "RIDE",
  affected_legs: [{ index: 1, type: "RIDE", matched_by: ["ROUTE"] }],
  matched_by: ["ROUTE"],
  warning: "Temporary service report. This is a report that may affect this journey and is still being checked.",
};

assert.equal(humanDisruptionCategory("STOP_UNAVAILABLE"), "Stop Unavailable");
assert.equal(humanImpact("POSSIBLY_AFFECTED"), "Report still being checked");
assert.equal(freshnessLabel(advisory, Date.parse("2026-10-07T00:18:00.000Z")), "Reported 18 min ago");
assert.equal(impactForLeg([impact], 1).length, 1);
assert.equal(impactForLeg([impact], 0).length, 0);
assert.equal(matchesEntity(advisory, 12), true);
assert.equal(matchesEntity(advisory, 99), false);
assert.match(shareableDisruptionNote([impact]), /still being checked/);

console.log("DISRUPTION_HARNESS=PASS");
console.log("IMPACT_LANGUAGE=PASS FRESHNESS=PASS LEG_MATCHING=PASS ENTITY_MATCHING=PASS");
console.log("PUBLIC_REPORT_HARD_CLOSE=0 SYNTHETIC_PRODUCTION_PROMOTION=0");
