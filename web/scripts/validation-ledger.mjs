import { createReadStream } from "node:fs";
import { mkdir, open, readFile, rename, unlink } from "node:fs/promises";
import { createHash, randomUUID } from "node:crypto";
import { homedir } from "node:os";
import { dirname, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const FORMAT = "biyahe-validation-ledger-v1";
const RESULT_STATES = new Set(["PASS", "FAIL", "BLOCKED", "NOT_RUN"]);
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
  for (const key of ["checkId", "sourceFingerprint", "configFingerprint", "toolVersion"]) {
    if (typeof identity?.[key] !== "string" || identity[key].trim() === "") {
      throw new Error(`Validation ledger identity requires ${key}.`);
    }
  }
}

function sameIdentity(entry, identity) {
  return entry.checkId === identity.checkId
    && entry.sourceFingerprint === identity.sourceFingerprint
    && entry.configFingerprint === identity.configFingerprint
    && entry.toolVersion === identity.toolVersion;
}

export function findReusablePass(entries, identity) {
  validateIdentity(identity);
  const latest = [...entries].reverse().find((entry) => sameIdentity(entry, identity));
  return { reusable: latest?.status === "PASS", entry: latest ?? null };
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
    try {
      validateIdentity(entry);
    } catch {
      throw new Error(`Validation ledger entry ${index} has an invalid identity: ${ledgerPath}`);
    }
    if (!RESULT_STATES.has(entry.status) || !RESOURCE_STATES.has(entry.resourceGuard)
      || !["EXECUTED", "NOT_EXECUTED"].includes(entry.executionStatus)
      || typeof entry.result !== "string" || typeof entry.recordedAt !== "string") {
      throw new Error(`Validation ledger entry ${index} is incomplete or invalid: ${ledgerPath}`);
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
  } catch (error) {
    if (handle) await handle.close().catch(() => {});
    await unlink(temporaryPath).catch(() => {});
    throw error;
  }
}

export async function appendValidationResult(record, ledgerPath = defaultLedgerPath()) {
  validateIdentity(record);
  if (!RESULT_STATES.has(record.status)) throw new Error("Validation status must be PASS, FAIL, BLOCKED, or NOT_RUN.");
  if (!RESOURCE_STATES.has(record.resourceGuard)) throw new Error("A valid resourceGuard outcome is required.");
  if (typeof record.result !== "string" || record.result.trim() === "") throw new Error("Validation result must be a non-empty string.");
  const ledger = await readValidationLedger(ledgerPath);
  const entry = {
    checkId: record.checkId,
    sourceFingerprint: record.sourceFingerprint,
    configFingerprint: record.configFingerprint,
    toolVersion: record.toolVersion,
    executionStatus: record.status === "PASS" || record.status === "FAIL" ? "EXECUTED" : "NOT_EXECUTED",
    status: record.status,
    result: record.result,
    evidencePath: typeof record.evidencePath === "string" ? record.evidencePath : null,
    resourceGuard: record.resourceGuard,
    recordedAt: new Date().toISOString(),
  };
  await writeAtomic(ledgerPath, { ...ledger, entries: [...ledger.entries, entry] });
  return entry;
}

async function cli() {
  const [command, inputPath, requestedLedgerPath] = process.argv.slice(2);
  if (!new Set(["record", "check"]).has(command) || !inputPath) {
    throw new Error("Usage: node validation-ledger.mjs <record|check> <identity.json> [ledger-path]");
  }
  const input = JSON.parse(await readFile(inputPath, "utf8"));
  const ledgerPath = requestedLedgerPath ? resolve(requestedLedgerPath) : defaultLedgerPath();
  if (command === "record") {
    console.log(JSON.stringify(await appendValidationResult(input, ledgerPath), null, 2));
    return;
  }
  const ledger = await readValidationLedger(ledgerPath);
  console.log(JSON.stringify(findReusablePass(ledger.entries, input), null, 2));
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  cli().catch((error) => {
    console.error(`VALIDATION_LEDGER_ERROR=${error.message}`);
    process.exitCode = 1;
  });
}
