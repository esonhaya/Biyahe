import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { canIRidePresentation } from "../src/lib/canIRidePresentation.ts";

assert.deepEqual(canIRidePresentation("YES", "R12"), { headline: "YES — ride R12", tone: "decisionYes" });
assert.deepEqual(canIRidePresentation("YES", null), { headline: "YES — ride this route", tone: "decisionYes" });
assert.deepEqual(canIRidePresentation("YES_BUT_AFFECTED", "R12"), { headline: "YES, BUT CHECK THE ADVISORY", tone: "decisionAffected" });
assert.deepEqual(canIRidePresentation("TEMPORARILY_UNAVAILABLE", "R12"), { headline: "TEMPORARILY UNAVAILABLE", tone: "decisionNo" });
assert.deepEqual(canIRidePresentation("UNCERTAIN_DUE_TO_REPORT", "R12"), { headline: "UNCERTAIN — REPORT UNDER REVIEW", tone: "decisionUncertain" });
assert.deepEqual(canIRidePresentation("DIRECTION_DEPENDENT", "R12"), { headline: "NOT THIS DIRECTION", tone: "decisionDirection" });
assert.deepEqual(canIRidePresentation("NO", "R12"), { headline: "NOT A KNOWN RIDE", tone: "decisionNo" });
assert.deepEqual(canIRidePresentation("UNKNOWN", "R12"), { headline: "NOT ENOUGH INFORMATION YET", tone: "decisionUnknown" });

const page = await readFile(new URL("../src/app/page.tsx", import.meta.url), "utf8");
assert.ok(page.includes("canIRide(rideRouteCode, origin.id, destination.id, request.signal)"));
assert.ok(page.includes("canRideRequestGateRef.current.isCurrent(request)"), "late responses are rejected after route or place changes");
assert.ok(page.includes("The Can I Ride check is unavailable right now"), "API unavailable stays distinct from NO");
assert.ok(page.includes("canIRideReasonLabel(result.reason_code)"), "the checker reason remains disclosed");
assert.ok(page.includes("result.warnings.map((warning)"), "evidence warnings remain visible");

console.log("CAN_I_RIDE_HARNESS=PASS");
console.log("YES=PASS NO=PASS UNKNOWN=PASS DIRECTION=PASS AFFECTED=PASS API_UNAVAILABLE=PASS EVIDENCE_DISCLOSURE=PASS STALE_RESPONSE=PASS");
