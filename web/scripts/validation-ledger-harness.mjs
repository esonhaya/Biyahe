import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
  appendValidationResult,
  findReusablePass,
  fingerprintConfig,
  fingerprintFile,
  readValidationLedger,
  validateScheduledCheck,
} from "./validation-ledger.mjs";

const directory = await mkdtemp(join(tmpdir(), "biyahe-validation-ledger-"));
const ledgerPath = join(directory, "ledger.json");
const inputPath = join(directory, "source.txt");
const identity = {
  checkId: "offline-route-fixture",
  sourceFingerprint: "source-sha256-fixture",
  configFingerprint: fingerprintConfig({ schema: 1, snapRadiusMeters: 25 }),
  toolVersion: "fixture-runner-1",
  runtimeVersion: "node-fixture-1",
  commandFingerprint: fingerprintConfig(["fixture-command"]),
};

try {
  await writeFile(inputPath, "stable fixture input\n", "utf8");
  assert.equal((await fingerprintFile(inputPath)).length, 64);
  const first = await appendValidationResult({ ...identity, status: "PASS", result: "Fixture check passed.", resourceGuard: "PASS", evidencePath: inputPath }, ledgerPath);
  assert.equal(first.executionStatus, "EXECUTED");
  let ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reusable, true);
  assert.equal((await findReusablePass(ledger.entries, { ...identity, sourceFingerprint: "changed-source" })).reusable, false);
  assert.equal((await findReusablePass(ledger.entries, { ...identity, configFingerprint: "changed-config" })).reusable, false);
  assert.equal((await findReusablePass(ledger.entries, { ...identity, toolVersion: "fixture-runner-2" })).reusable, false);
  assert.equal((await findReusablePass(ledger.entries, { ...identity, runtimeVersion: "node-fixture-2" })).reusable, false);
  assert.equal((await findReusablePass(ledger.entries, { ...identity, commandFingerprint: "f".repeat(64) })).reusable, false);

  const scheduled = {
    ...identity,
    command: { operation: "fixture-check", diskPath: directory, cancelPidFile: join(directory, "owned.pid"),
      cancelMatch: "fixture-check", logPath: join(directory, "run.log"), argv: ["node", "--version"] },
  };
  scheduled.commandFingerprint = fingerprintConfig(scheduled.command.argv);
  assert.equal(validateScheduledCheck(scheduled).operation, "fixture-check");
  assert.throws(() => validateScheduledCheck({ ...scheduled, commandFingerprint: "0".repeat(64) }), /does not match/);

  await writeFile(inputPath, "evidence changed after the PASS\n", "utf8");
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "evidence_changed");
  await writeFile(inputPath, "stable fixture input\n", "utf8");

  await appendValidationResult({ ...identity, status: "RUNNING", result: "Execution started.", resourceGuard: "PASS" }, ledgerPath);
  ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "interrupted_or_incomplete_execution");

  const blocked = await appendValidationResult({ ...identity, status: "BLOCKED", result: "Resource guard stopped execution.", resourceGuard: "BLOCKED" }, ledgerPath);
  assert.equal(blocked.executionStatus, "NOT_EXECUTED");
  ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reusable, false, "a newer BLOCKED record must not reuse an older PASS");
  await assert.rejects(appendValidationResult({ ...identity, status: "PASS", result: "No evidence.", resourceGuard: "PASS" }, ledgerPath), /requires an evidence file/);
  await assert.rejects(appendValidationResult({ ...identity, status: "PASS", result: "Guard failed.", resourceGuard: "BLOCKED", evidencePath: inputPath }, ledgerPath), /resource guard/);
  await assert.rejects(appendValidationResult({ ...identity, status: "RUNNING", result: "Guard failed.", resourceGuard: "BLOCKED" }, ledgerPath), /RUNNING record requires/);

  const serialized = await readFile(ledgerPath, "utf8");
  assert.equal(serialized.endsWith("\n"), true);
  assert.equal(JSON.parse(serialized).entries.length, 3);
  await writeFile(ledgerPath, JSON.stringify({ format: "biyahe-validation-ledger-v1", entries: [{}] }), "utf8");
  await assert.rejects(readValidationLedger(ledgerPath), /invalid identity/);
  console.log("VALIDATION_LEDGER_HARNESS=PASS");
  console.log("IDENTITY_BINDING=PASS EVIDENCE_HASH=PASS STALE_EVIDENCE_REJECTED=PASS INTERRUPTED_RUN_REJECTED=PASS BLOCKED_NOT_REUSED=PASS ATOMIC_JSON=PASS");
} finally {
  await rm(directory, { recursive: true, force: true });
}
