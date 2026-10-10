import assert from "node:assert/strict";
import { LatestPlaceSearch } from "../src/lib/latestPlaceSearch.ts";

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

const search = new LatestPlaceSearch();
const shortRequest = deferred();
const oldQuery = search.search("Carbon", () => shortRequest.promise);
assert.equal((await search.search(" C ", async () => [])).status, "empty");
shortRequest.resolve([{ id: 1, name: "Stale Carbon result" }]);
assert.equal((await oldQuery).status, "stale", "clearing to a short query must invalidate an abort-insensitive request");

const olderRequest = deferred();
const newerRequest = deferred();
const older = search.search("Ayala", () => olderRequest.promise);
const newer = search.search("Ayala Center", () => newerRequest.promise);
newerRequest.resolve([{ id: 2, name: "Ayala Center Cebu" }]);
assert.deepEqual(await newer, { status: "results", results: [{ id: 2, name: "Ayala Center Cebu" }] });
olderRequest.resolve([{ id: 3, name: "Old Ayala result" }]);
assert.equal((await older).status, "stale", "a slower earlier query must not replace newer suggestions");

assert.deepEqual(await search.search("IT Park", async (query) => [{ id: 4, name: query }]), {
  status: "results",
  results: [{ id: 4, name: "IT Park" }],
});
assert.equal((await search.search("Carbon", async () => { throw new Error("offline"); })).status, "error");
assert.deepEqual(await search.search("Carbon", async () => [{ id: 5, name: "Carbon Market" }]), {
  status: "results",
  results: [{ id: 5, name: "Carbon Market" }],
});

console.log("PLACE_SEARCH_HARNESS=PASS");
console.log("SHORT_QUERY_INVALIDATION=PASS OUT_OF_ORDER_RESPONSE=PASS NETWORK_ERROR_RECOVERY=PASS");
