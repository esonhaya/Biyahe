import { createReadStream } from "node:fs";
import { access, mkdir, open, readFile, rename, unlink } from "node:fs/promises";
import { spawn } from "node:child_process";
import { createHash, randomUUID } from "node:crypto";
import { homedir } from "node:os";
import { dirname, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const FORMAT = "biyahe-validation-ledger-v1";
const RESULT_STATES = new Set(["PASS", "FAIL", "BLOCKED", "NOT_RUN", "RUNNING"]);
const RESOURCE_STATES = new Set(["PASS", "FAIL", "BLOCKED", "NOT_RUN", "NOT_REQUIRED"]);

export function defaultLedgerPath() {
  return process.env.BIYAHE_VALIDATION_LEDGER_PATH
    ? resolve(process.env.BIYAHE_VALIDATION_LEDGER_PATH)
    : resolve(homedir(), ".cache", "biyahe", "validation-ledger", "batch038.json");
}

function stableJson(value) {
  if (typeof value === "number" && !Number.isFinite(value)) throw new Error("Fingerprint input contains a non-finite number.");
  if (value === undefined || typeof value === "function" || typeof value === "symbol") {
    throw new Error("Fingerprint input contains a value that cannot be represented in JSON.");
  }
  if (value === null || typeof value !== "object") return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map(stableJson).join(",")}]`;
  const entries = Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableJson(value[key])}`);
  return `{${entries.join(",")}}`;
}

export function fingerprintConfig(value) {
  return createHash("sha256").update(stableJson(value)).digest("hex");
}

export async function fingerprintFile(filePath) {
  const hash = createHash("sha256");
  for await (const chunk of createReadStream(filePath)) hash.update(chunk);
  return hash.digest("hex");
}

function validateIdentity(identity) {
  for (const key of ["checkId", "sourceFingerprint", "configFingerprint", "toolVersion", "runtimeVersion", "commandFingerprint"]) {
    if (typeof identity?.[key] !== "string" || identity[key].trim() === "") {
      throw new Error(`Validation ledger identity requires ${key}.`);
    }
  }
}

function sameIdentity(entry, identity) {
  return entry.checkId === identity.checkId
    && entry.sourceFingerprint === identity.sourceFingerprint
    && entry.configFingerprint === identity.configFingerprint
    && entry.toolVersion === identity.toolVersion
    && entry.runtimeVersion === identity.runtimeVersion
    && entry.commandFingerprint === identity.commandFingerprint;
}

export async function findReusablePass(entries, identity) {
  validateIdentity(identity);
  const latest = [...entries].reverse().find((entry) => sameIdentity(entry, identity));
  if (!latest) return { reusable: false, reason: "no_matching_result", entry: null };
  if (latest.status !== "PASS" || latest.executionStatus !== "EXECUTED") {
    return { reusable: false, reason: latest.status === "RUNNING" ? "interrupted_or_incomplete_execution" : "latest_result_not_pass", entry: latest };
  }
  if (!new Set(["PASS", "NOT_REQUIRED"]).has(latest.resourceGuard)) {
    return { reusable: false, reason: "resource_guard_not_passed", entry: latest };
  }
  if (typeof latest.evidencePath !== "string" || !/^[a-f0-9]{64}$/.test(latest.evidenceSha256 ?? "")) {
    return { reusable: false, reason: "evidence_missing_or_unbound", entry: latest };
  }
  try {
    const actualEvidenceSha256 = await fingerprintFile(latest.evidencePath);
    if (actualEvidenceSha256 !== latest.evidenceSha256) {
      return { reusable: false, reason: "evidence_changed", entry: latest };
    }
  } catch {
    return { reusable: false, reason: "evidence_unavailable", entry: latest };
  }
  return { reusable: true, reason: "fingerprints_and_evidence_match", entry: latest };
}

export async function readValidationLedger(ledgerPath = defaultLedgerPath()) {
  let text;
  try {
    text = await readFile(ledgerPath, "utf8");
  } catch (error) {
    if (error?.code === "ENOENT") return { format: FORMAT, entries: [] };
    throw error;
  }
  let ledger;
  try {
    ledger = JSON.parse(text);
  } catch {
    throw new Error(`Validation ledger is not valid JSON: ${ledgerPath}`);
  }
  if (ledger?.format !== FORMAT || !Array.isArray(ledger.entries)) {
    throw new Error(`Validation ledger format is unsupported or incomplete: ${ledgerPath}`);
  }
  for (const [index, entry] of ledger.entries.entries()) {
    if (["checkId", "sourceFingerprint", "configFingerprint", "toolVersion"].some((key) => typeof entry?.[key] !== "string" || entry[key].trim() === "")
      || (entry.runtimeVersion !== undefined && typeof entry.runtimeVersion !== "string")
      || (entry.commandFingerprint !== undefined && typeof entry.commandFingerprint !== "string")) {
      throw new Error(`Validation ledger entry ${index} has an invalid identity: ${ledgerPath}`);
    }
    if (!RESULT_STATES.has(entry.status) || !RESOURCE_STATES.has(entry.resourceGuard)
      || !["EXECUTED", "NOT_EXECUTED", "STARTED"].includes(entry.executionStatus)
      || typeof entry.result !== "string" || typeof entry.recordedAt !== "string") {
      throw new Error(`Validation ledger entry ${index} is incomplete or invalid: ${ledgerPath}`);
    }
    if ((entry.evidencePath !== null && entry.evidencePath !== undefined && typeof entry.evidencePath !== "string")
      || (entry.evidenceSha256 !== null && entry.evidenceSha256 !== undefined && !/^[a-f0-9]{64}$/.test(entry.evidenceSha256))) {
      throw new Error(`Validation ledger entry ${index} has invalid evidence metadata: ${ledgerPath}`);
    }
    if ((entry.commandFingerprint !== undefined && !/^[a-f0-9]{64}$/.test(entry.commandFingerprint))
      || (entry.runtimeVersion !== undefined && entry.runtimeVersion.trim() === "")) {
      throw new Error(`Validation ledger entry ${index} has invalid execution identity: ${ledgerPath}`);
    }
  }
  return ledger;
}

async function writeAtomic(ledgerPath, ledger) {
  const directory = dirname(ledgerPath);
  await mkdir(directory, { recursive: true });
  const temporaryPath = `${ledgerPath}.${process.pid}.${randomUUID()}.tmp`;
  let handle;
  try {
    handle = await open(temporaryPath, "wx", 0o600);
    await handle.writeFile(`${JSON.stringify(ledger, null, 2)}\n`, "utf8");
    await handle.sync();
    await handle.close();
    handle = null;
    await rename(temporaryPath, ledgerPath);
    const directoryHandle = await open(directory, "r");
    try { await directoryHandle.sync(); } finally { await directoryHandle.close(); }
  } catch (error) {
    if (handle) await handle.close().catch(() => {});
    await unlink(temporaryPath).catch(() => {});
    throw error;
  }
}

export async function appendValidationResult(record, ledgerPath = defaultLedgerPath()) {
  validateIdentity(record);
  if (!RESULT_STATES.has(record.status)) throw new Error("Validation status must be RUNNING, PASS, FAIL, BLOCKED, or NOT_RUN.");
  if (!RESOURCE_STATES.has(record.resourceGuard)) throw new Error("A valid resourceGuard outcome is required.");
  if (typeof record.result !== "string" || record.result.trim() === "") throw new Error("Validation result must be a non-empty string.");
  if (record.status === "PASS" && !new Set(["PASS", "NOT_REQUIRED"]).has(record.resourceGuard)) {
    throw new Error("A validation PASS requires a passing or not-required resource guard.");
  }
  if (record.status === "RUNNING" && !new Set(["PASS", "NOT_REQUIRED"]).has(record.resourceGuard)) {
    throw new Error("A validation RUNNING record requires a passing or not-required resource guard.");
  }
  const evidencePath = typeof record.evidencePath === "string" ? resolve(record.evidencePath) : null;
  if (record.status === "PASS" && evidencePath === null) {
    throw new Error("A reusable validation PASS requires an evidence file.");
  }
  const evidenceSha256 = evidencePath === null ? null : await fingerprintFile(evidencePath);
  await mkdir(dirname(ledgerPath), { recursive: true });
  const lockPath = `${ledgerPath}.write.lock`;
  let lockHandle;
  try {
    lockHandle = await open(lockPath, "wx", 0o600);
  } catch (error) {
    if (error?.code === "EEXIST") throw new Error(`Validation ledger is being updated by another process: ${ledgerPath}`);
    throw error;
  }
  try {
    const ledger = await readValidationLedger(ledgerPath);
    const entry = {
      checkId: record.checkId,
      sourceFingerprint: record.sourceFingerprint,
      configFingerprint: record.configFingerprint,
      toolVersion: record.toolVersion,
      runtimeVersion: record.runtimeVersion,
      commandFingerprint: record.commandFingerprint,
      executionStatus: record.status === "RUNNING" ? "STARTED" : record.status === "PASS" || record.status === "FAIL" ? "EXECUTED" : "NOT_EXECUTED",
      status: record.status,
      result: record.result,
      evidencePath,
      evidenceSha256,
      resourceGuard: record.resourceGuard,
      recordedAt: new Date().toISOString(),
    };
    await writeAtomic(ledgerPath, { ...ledger, entries: [...ledger.entries, entry] });
    return entry;
  } finally {
    await lockHandle.close().catch(() => {});
    await unlink(lockPath).catch(() => {});
  }
}

export function validateScheduledCheck(input) {
  validateIdentity(input);
  const command = input.command;
  if (!command || !Array.isArray(command.argv) || command.argv.length === 0
    || command.argv.some((part) => typeof part !== "string" || part.includes("\u0000"))) {
    throw new Error("Scheduled checks require a non-empty argv array of strings.");
  }
  if (fingerprintConfig(command.argv) !== input.commandFingerprint) {
    throw new Error("commandFingerprint does not match the exact argv array.");
  }
  for (const key of ["operation", "diskPath", "cancelPidFile", "cancelMatch", "logPath"]) {
    if (typeof command[key] !== "string" || command[key].trim() === "") throw new Error(`Scheduled check command requires ${key}.`);
  }
  if (!/^[a-z0-9-]{1,40}$/.test(command.operation)) throw new Error("Scheduled operation must use lowercase letters, digits, and hyphens.");
  return command;
}

async function runGuardedCheck(input, ledgerPath) {
  const command = validateScheduledCheck(input);
  await mkdir(dirname(ledgerPath), { recursive: true });
  const schedulerLockPath = resolve(homedir(), ".cache", "biyahe", "validation-ledger", "resource-scheduler.lock");
  await mkdir(dirname(schedulerLockPath), { recursive: true });
  let schedulerLock;
  try {
    schedulerLock = await open(schedulerLockPath, "wx", 0o600);
  } catch (error) {
    if (error?.code === "EEXIST") throw new Error("Another resource-guarded validation check is already running.");
    throw error;
  }
  try {
    const ledger = await readValidationLedger(ledgerPath);
    const reusable = await findReusablePass(ledger.entries, input);
    if (reusable.reusable) {
      console.log(JSON.stringify({ status: "REUSED_PASS", reason: reusable.reason, evidencePath: reusable.entry.evidencePath }, null, 2));
      return 0;
    }
    if (await Promise.all([command.logPath, command.cancelPidFile].map(async (path) => {
      try { await access(path); return true; } catch (error) { if (error?.code === "ENOENT") return false; throw error; }
    })).then((values) => values.some(Boolean))) {
      throw new Error("Scheduled check log or cancellation path already exists; use a new evidence path.");
    }
    await appendValidationResult({ ...input, status: "RUNNING", result: "Guarded check started; final outcome not yet recorded.", resourceGuard: "PASS" }, ledgerPath);
    const repositoryRoot = resolve(dirname(new URL(import.meta.url).pathname), "../..");
    const guardPath = resolve(repositoryRoot, "api/scripts/run-bounded-process.sh");
    const args = [guardPath, "--operation", command.operation, "--disk-path", command.diskPath,
      "--cancel-pid-file", command.cancelPidFile, "--cancel-match", command.cancelMatch,
      "--log", command.logPath, "--", ...command.argv];
    const environment = {
      ...process.env,
      BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB: "524288",
      BIYAHE_RESOURCE_MIN_DISKFREE_KB: "1048576",
      BIYAHE_RESOURCE_MIN_SWAPFREE_KB: "131072",
      BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB: "262144",
      BIYAHE_RESOURCE_PREFLIGHT_SAMPLES: "3",
      BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS: "5",
      BIYAHE_RESOURCE_BREACH_SAMPLES: "2",
      BIYAHE_RESOURCE_MEMINFO_PATH: "/proc/meminfo",
    };
    const result = await new Promise((resolvePromise, rejectPromise) => {
      const child = spawn("bash", args, { stdio: "inherit", env: environment, shell: false });
      child.once("error", rejectPromise);
      child.once("close", (code, signal) => resolvePromise({ code: code ?? 1, signal }));
      const forwards = new Map([["SIGINT", () => child.kill("SIGINT")], ["SIGTERM", () => child.kill("SIGTERM")], ["SIGHUP", () => child.kill("SIGHUP")]]);
      for (const [signal, handler] of forwards) process.once(signal, handler);
      child.once("close", () => { for (const [signal, handler] of forwards) process.removeListener(signal, handler); });
    });
    let log = "";
    try { log = await readFile(command.logPath, "utf8"); } catch { /* missing evidence will prevent PASS reuse */ }
    const blocked = result.signal !== null || /command_not_started=yes|stopped_for_resource_budget|resource_preflight_failed|resource_cancel=required/i.test(log);
    const status = blocked ? "BLOCKED" : result.code === 0 ? "PASS" : "FAIL";
    const resourceGuard = blocked ? "BLOCKED" : "PASS";
    const evidencePath = log ? command.logPath : null;
    await appendValidationResult({ ...input, status, result: `Guarded command exited ${result.code}${result.signal ? ` via ${result.signal}` : ""}; ${status}.`, resourceGuard, evidencePath }, ledgerPath);
    console.log(`CHECK_STATUS=${status}`);
    console.log(`RESOURCE_GUARD=${resourceGuard}`);
    console.log(`EVIDENCE=${evidencePath ?? "MISSING"}`);
    return status === "PASS" ? 0 : 1;
  } finally {
    await schedulerLock.close().catch(() => {});
    await unlink(schedulerLockPath).catch(() => {});
  }
}

async function cli() {
  const [command, inputPath, requestedLedgerPath] = process.argv.slice(2);
  if (!new Set(["record", "check", "run"]).has(command) || !inputPath) {
    throw new Error("Usage: node validation-ledger.mjs <record|check|run> <identity.json> [ledger-path]");
  }
  const input = JSON.parse(await readFile(inputPath, "utf8"));
  const ledgerPath = requestedLedgerPath ? resolve(requestedLedgerPath) : defaultLedgerPath();
  if (command === "run") {
    process.exitCode = await runGuardedCheck(input, ledgerPath);
    return;
  }
  if (command === "record") {
    console.log(JSON.stringify(await appendValidationResult(input, ledgerPath), null, 2));
    return;
  }
  const ledger = await readValidationLedger(ledgerPath);
  console.log(JSON.stringify(await findReusablePass(ledger.entries, input), null, 2));
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  cli().catch((error) => {
    console.error(`VALIDATION_LEDGER_ERROR=${error.message}`);
    process.exitCode = 1;
  });
}
