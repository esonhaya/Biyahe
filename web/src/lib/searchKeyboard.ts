export type SuggestionNavigationKey = "ArrowDown" | "ArrowUp";

export function selectedSuggestionIndex(activeIndex: number, optionCount: number): number {
  if (optionCount <= 0) return 0;
  const safeIndex = Number.isInteger(activeIndex) ? activeIndex : 0;
  return Math.max(0, Math.min(safeIndex, optionCount - 1));
}

export function moveSuggestionSelection(
  activeIndex: number,
  optionCount: number,
  key: string,
): number | null {
  if (optionCount <= 0 || (key !== "ArrowDown" && key !== "ArrowUp")) return null;
  const current = selectedSuggestionIndex(activeIndex, optionCount);
  return key === "ArrowDown"
    ? (current + 1) % optionCount
    : (current - 1 + optionCount) % optionCount;
}
