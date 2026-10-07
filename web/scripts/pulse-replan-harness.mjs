import assert from "node:assert/strict";
import { journeyHasOperationalImpact, pulseNetworkStatusIsUncertain, replanStatusLabel, PULSE_FILTERS } from "../src/lib/pulse.ts";

const journey = { disruptions: [] };
assert.equal(journeyHasOperationalImpact(journey), false);
assert.equal(journeyHasOperationalImpact({ disruptions: [{ impact_level: "AFFECTED" }] }), true);
assert.equal(pulseNetworkStatusIsUncertain("NO_KNOWN_ACTIVE_ADVISORIES"), true);
assert.equal(pulseNetworkStatusIsUncertain("DISRUPTED"), false);
assert.equal(replanStatusLabel("ALTERNATIVES_FOUND"), "Alternatives found");
assert.equal(replanStatusLabel("NO_ALTERNATIVE_FOUND"), "No unaffected alternative found");
assert.deepEqual(PULSE_FILTERS, ["ALL", "ROUTES", "STOPS", "AREAS", "MAJOR"]);

console.log("PULSE_REPLAN_HARNESS=PASS");
console.log("PULSE_NON_NORMAL_STATUS=PASS EXPLICIT_REPLAN=PASS FILTERS=PASS");
