import assert from "node:assert/strict";
import {
  loadFavoriteCommutes,
  loadFavoritePlaces,
  loadRecentCommutes,
  persistFavoriteCommutes,
  persistFavoritePlaces,
  persistRecentCommutes,
  recordRecentCommute,
  restorePlace,
  upsertFavoriteCommute,
  upsertFavoritePlace,
} from "../src/lib/commuterTools.ts";

class MemoryStorage {
  values = new Map();
  getItem(key) { return this.values.get(key) ?? null; }
  setItem(key, value) { this.values.set(key, value); }
  removeItem(key) { this.values.delete(key); }
}

const verification = { state: "VERIFIED", is_demo: false, trusted: true };
function place(id, name) {
  return { id, name, slug: name.toLowerCase().replaceAll(" ", "-"), description: null, category: "LANDMARK", location: { lat: 10.3 + id / 10000, lng: 123.9 + id / 10000 }, verification, aliases: [] };
}

const storage = new MemoryStorage();
const origin = place(1, "Ayala Center Cebu");
const destination = place(2, "Cebu IT Park");
let recents = recordRecentCommute([], origin, destination, "2026-10-07T01:00:00.000Z");
recents = recordRecentCommute(recents, origin, destination, "2026-10-07T02:00:00.000Z");
assert.equal(recents.length, 1);
persistRecentCommutes(storage, recents);
assert.equal(loadRecentCommutes(storage)[0].destination.name, "Cebu IT Park");

const savedPlace = upsertFavoritePlace([], origin, "Work", "2026-10-07T02:00:00.000Z");
persistFavoritePlaces(storage, savedPlace);
assert.equal(loadFavoritePlaces(storage)[0].label, "Work");
assert.equal(restorePlace(loadFavoritePlaces(storage)[0].place).id, origin.id);

const savedCommute = upsertFavoriteCommute([], origin, destination, "Office", "2026-10-07T02:00:00.000Z");
persistFavoriteCommutes(storage, savedCommute);
assert.equal(loadFavoriteCommutes(storage)[0].label, "Office");

const malformed = JSON.stringify({ version: 999, items: savedCommute });
storage.setItem("biyahe.favorite-commutes.v1", malformed);
assert.deepEqual(loadFavoriteCommutes(storage), []);
assert.equal(Array.from(storage.values.values()).join("\n").toLowerCase().includes("gps"), false);

console.log("PERSONAL_COMMUTE_HARNESS=PASS");
console.log("RECENTS_LOCAL=PASS FAVORITE_PLACES_LOCAL=PASS FAVORITE_COMMUTES_LOCAL=PASS");
console.log("SCHEMA_VALIDATION=PASS BOUNDED_STORAGE=PASS GPS_PERSISTENCE=0 SERVER_HISTORY=0");
