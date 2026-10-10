import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, unlink, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
  appendValidationResult,
  findReusablePass,
  fingerprintConfig,
  fingerprintFile,
  fingerprintFileSet,
  fingerprintScheduledCommand,
  fingerprintValidationInputs,
  readValidationLedger,
  recoverInterruptedRun,
  resourceGuardEnvironment,
  validateScheduledCheck,
} from "./validation-ledger.mjs";

const directory = await mkdtemp(join(tmpdir(), "biyahe-validation-ledger-"));
const ledgerPath = join(directory, "ledger.json");
const inputPath = join(directory, "source.txt");
const testPath = join(directory, "test.mjs");
const configPath = join(directory, "config.json");

try {
  await writeFile(inputPath, "stable fixture input\n", "utf8");
  await writeFile(testPath, "assert(true);\n", "utf8");
  await writeFile(configPath, '{"snapRadiusMeters":25}\n', "utf8");
  assert.equal((await fingerprintFile(inputPath)).length, 64);
  assert.equal((await fingerprintFileSet([inputPath, testPath])).length, 64);
  const sourceFingerprint = await fingerprintValidationInputs({ sourceFiles: [inputPath], testFiles: [testPath], configurationFiles: [configPath] });
  const configFingerprint = await fingerprintFileSet([configPath]);
  const identity = {
    checkId: "offline-route-fixture",
    sourceFingerprint,
    configFingerprint,
    toolVersion: "fixture-runner-1",
    runtimeVersion: "node-fixture-1",
    commandFingerprint: fingerprintConfig(["fixture-command"]),
  };
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
      cancelMatch: "fixture-check", logPath: join(directory, "run.log"), argv: ["node", "--version"],
      cwd: directory, environment: { FIXTURE_MODE: "1" } },
  };
  scheduled.commandFingerprint = fingerprintScheduledCommand(scheduled.command);
  assert.equal(validateScheduledCheck(scheduled).operation, "fixture-check");
  assert.notEqual(fingerprintScheduledCommand({ ...scheduled.command, cwd: tmpdir() }), scheduled.commandFingerprint,
    "the working directory participates in the exact command fingerprint");
  assert.notEqual(fingerprintScheduledCommand({ ...scheduled.command, environment: { FIXTURE_MODE: "2" } }), scheduled.commandFingerprint,
    "declared non-secret environment participates in the command fingerprint");
  assert.throws(() => validateScheduledCheck({ ...scheduled, commandFingerprint: "0".repeat(64) }), /argv, working directory, and declared environment/);
  const guardedEnvironment = resourceGuardEnvironment({ BIYAHE_RESOURCE_MIN_SWAPFREE_KB: "1" }, { BIYAHE_RESOURCE_BREACH_SAMPLES: "10" });
  assert.equal(guardedEnvironment.BIYAHE_RESOURCE_MIN_SWAPFREE_KB, "131072");
  assert.equal(guardedEnvironment.BIYAHE_RESOURCE_PREFLIGHT_SAMPLES, "5");
  assert.equal(guardedEnvironment.BIYAHE_RESOURCE_BREACH_SAMPLES, "1");

  await writeFile(inputPath, "evidence changed after the PASS\n", "utf8");
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "evidence_changed");
  await unlink(inputPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "evidence_unavailable");
  await writeFile(inputPath, "stable fixture input\n", "utf8");
  const changedInputsFingerprint = await fingerprintValidationInputs({
    sourceFiles: [inputPath], testFiles: [testPath], configurationFiles: [configPath],
  });
  assert.equal(changedInputsFingerprint, sourceFingerprint, "restored source bytes restore the same grouped input fingerprint");
  await writeFile(testPath, "assert(false);\n", "utf8");
  assert.notEqual(await fingerprintValidationInputs({
    sourceFiles: [inputPath], testFiles: [testPath], configurationFiles: [configPath],
  }), sourceFingerprint, "test source changes invalidate the grouped input fingerprint");
  await writeFile(testPath, "assert(true);\n", "utf8");

  await appendValidationResult({ ...identity, status: "RUNNING", result: "Execution started.", resourceGuard: "PASS" }, ledgerPath);
  ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "interrupted_or_incomplete_execution");
  const recovered = await recoverInterruptedRun(identity, ledgerPath);
  assert.equal(recovered.recovered, true);
  assert.equal(recovered.entry.status, "NOT_RUN");
  ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reason, "latest_result_not_pass");
  assert.equal((await recoverInterruptedRun(identity, ledgerPath)).recovered, false,
    "recovery does not rewrite an already closed or terminal result");

  const blocked = await appendValidationResult({ ...identity, status: "BLOCKED", result: "Resource guard stopped execution.", resourceGuard: "BLOCKED" }, ledgerPath);
  assert.equal(blocked.executionStatus, "NOT_EXECUTED");
  ledger = await readValidationLedger(ledgerPath);
  assert.equal((await findReusablePass(ledger.entries, identity)).reusable, false, "a newer BLOCKED record must not reuse an older PASS");
  await assert.rejects(appendValidationResult({ ...identity, status: "PASS", result: "No evidence.", resourceGuard: "PASS" }, ledgerPath), /requires an evidence file/);
  await assert.rejects(appendValidationResult({ ...identity, status: "PASS", result: "Guard failed.", resourceGuard: "BLOCKED", evidencePath: inputPath }, ledgerPath), /resource guard/);
  await assert.rejects(appendValidationResult({ ...identity, status: "RUNNING", result: "Guard failed.", resourceGuard: "BLOCKED" }, ledgerPath), /RUNNING record requires/);

  const serialized = await readFile(ledgerPath, "utf8");
  assert.equal(serialized.endsWith("\n"), true);
  assert.equal(JSON.parse(serialized).entries.length, 4);
  await writeFile(ledgerPath, JSON.stringify({ format: "biyahe-validation-ledger-v1", entries: [{}] }), "utf8");
  await assert.rejects(readValidationLedger(ledgerPath), /invalid identity/);
  console.log("VALIDATION_LEDGER_HARNESS=PASS");
  console.log("INPUT_GROUP_HASH=PASS COMMAND_CWD_ENV_HASH=PASS EVIDENCE_HASH=PASS STALE_EVIDENCE_REJECTED=PASS MISSING_EVIDENCE_REJECTED=PASS");
  console.log("INTERRUPTED_RUN_RECOVERY=PASS BLOCKED_NOT_REUSED=PASS FIRST_BREACH_POLICY=PASS ATOMIC_JSON=PASS");
} finally {
  await rm(directory, { recursive: true, force: true });
}
