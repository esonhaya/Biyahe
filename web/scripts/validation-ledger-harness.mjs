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
} from "./validation-ledger.mjs";

const directory = await mkdtemp(join(tmpdir(), "biyahe-validation-ledger-"));
const ledgerPath = join(directory, "ledger.json");
const inputPath = join(directory, "source.txt");
const identity = {
  checkId: "offline-route-fixture",
  sourceFingerprint: "source-sha256-fixture",
  configFingerprint: fingerprintConfig({ schema: 1, snapRadiusMeters: 25 }),
  toolVersion: "fixture-runner-1",
};

try {
  await writeFile(inputPath, "stable fixture input\n", "utf8");
  assert.equal((await fingerprintFile(inputPath)).length, 64);
  const first = await appendValidationResult({ ...identity, status: "PASS", result: "Fixture check passed.", resourceGuard: "PASS", evidencePath: "fixture:evidence" }, ledgerPath);
  assert.equal(first.executionStatus, "EXECUTED");
  let ledger = await readValidationLedger(ledgerPath);
  assert.equal(findReusablePass(ledger.entries, identity).reusable, true);
  assert.equal(findReusablePass(ledger.entries, { ...identity, sourceFingerprint: "changed-source" }).reusable, false);
  assert.equal(findReusablePass(ledger.entries, { ...identity, configFingerprint: "changed-config" }).reusable, false);
  assert.equal(findReusablePass(ledger.entries, { ...identity, toolVersion: "fixture-runner-2" }).reusable, false);

  const blocked = await appendValidationResult({ ...identity, status: "BLOCKED", result: "Resource guard stopped execution.", resourceGuard: "BLOCKED" }, ledgerPath);
  assert.equal(blocked.executionStatus, "NOT_EXECUTED");
  ledger = await readValidationLedger(ledgerPath);
  assert.equal(findReusablePass(ledger.entries, identity).reusable, false, "a newer BLOCKED record must not reuse an older PASS");

  const serialized = await readFile(ledgerPath, "utf8");
  assert.equal(serialized.endsWith("\n"), true);
  assert.equal(JSON.parse(serialized).entries.length, 2);
  await writeFile(ledgerPath, JSON.stringify({ format: "biyahe-validation-ledger-v1", entries: [{}] }), "utf8");
  await assert.rejects(readValidationLedger(ledgerPath), /invalid identity/);
  console.log("VALIDATION_LEDGER_HARNESS=PASS");
  console.log("IDENTITY_BINDING=PASS BLOCKED_NOT_REUSED=PASS ATOMIC_JSON=PASS");
} finally {
  await rm(directory, { recursive: true, force: true });
}
