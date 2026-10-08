import assert from "node:assert/strict";
import { moveSuggestionSelection, selectedSuggestionIndex } from "../src/lib/searchKeyboard.ts";

assert.equal(selectedSuggestionIndex(9, 3), 2, "shrinking suggestions clamps the active option");
assert.equal(selectedSuggestionIndex(-2, 3), 0);
assert.equal(moveSuggestionSelection(0, 3, "ArrowUp"), 2, "ArrowUp wraps from first to last");
assert.equal(moveSuggestionSelection(2, 3, "ArrowDown"), 0, "ArrowDown wraps from last to first");
assert.equal(moveSuggestionSelection(0, 3, "ArrowDown"), 1);
assert.equal(moveSuggestionSelection(2, 3, "Enter"), null, "Enter remains the component's selection action");
assert.equal(moveSuggestionSelection(0, 0, "ArrowDown"), null, "empty suggestions do not capture arrow keys");

console.log("SEARCH_KEYBOARD_HARNESS=PASS");
console.log("ARROW_WRAP=PASS SHRINK_CLAMP=PASS EMPTY_LIST_PASSTHROUGH=PASS");
