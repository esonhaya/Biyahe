import { readFile } from "node:fs/promises";

const files = {
  page: await readFile(new URL("../src/app/page.tsx", import.meta.url), "utf8"),
  card: await readFile(new URL("../src/components/CommuteCard.tsx", import.meta.url), "utf8"),
  map: await readFile(new URL("../src/components/MapView.tsx", import.meta.url), "utf8"),
  pageStyles: await readFile(new URL("../src/app/page.module.css", import.meta.url), "utf8"),
  globals: await readFile(new URL("../src/app/globals.css", import.meta.url), "utf8"),
  api: await readFile(new URL("../src/lib/api.ts", import.meta.url), "utf8"),
  sw: await readFile(new URL("../public/sw.js", import.meta.url), "utf8"),
  manifest: await readFile(new URL("../public/manifest.webmanifest", import.meta.url), "utf8"),
};

const checks = [
  ["destination-first planning anchor", files.page.includes('id="planning"')],
  ["accessible search combobox", files.page.includes('role="combobox"') && files.page.includes('aria-autocomplete="list"')],
  ["keyboard suggestion navigation", files.page.includes('event.key === "ArrowDown"') && files.page.includes('event.key === "Escape"')],
  ["selected place context closes suggestions", files.page.includes("selectedPlace === null") && files.page.includes("aria-describedby={selectedPlace ? selectionContextId : undefined}")],
  ["place choices show disambiguating details", files.page.includes("place.description?.trim()") && files.page.includes("place.category.toLowerCase()")],
  ["place search failure has distinct feedback", files.page.includes("Place matches could not be loaded") && files.page.includes("setDestinationSearchError(true)")],
  ["suggestions stay bounded on small screens", files.pageStyles.includes("max-height: min(55vh, 360px)") && files.pageStyles.includes("overscroll-behavior: contain")],
  ["map overlays clear the device safe area", files.pageStyles.includes("bottom: max(12px, env(safe-area-inset-bottom))") && files.globals.includes("bottom: max(12px, env(safe-area-inset-bottom))")],
  ["debounced stale-safe search", files.page.includes("setTimeout") && files.page.includes("originRequestRef") && files.page.includes("destinationRequestRef")],
  ["plain-language trust labels", files.page.includes("Mapped, some details need checking") && files.card.includes("Confirmed details")],
  ["factual journey explanation", files.card.includes("Why this option") && files.card.includes("walkingDistanceHasFallback")],
  ["text map alternative", files.page.includes("Map reading guide") && files.map.includes("role=\"img\"" )],
  ["foreground location boundary", files.page.includes("foreground") || files.map.includes("Current device location")],
  ["reduced-motion support", files.globals.includes("prefers-reduced-motion")],
  ["safe area support", files.globals.includes("safe-area-inset-bottom")],
  ["versioned PWA shell", files.sw.includes("biyahe-shell-v2") && files.manifest.includes('"start_url"')],
  ["API excluded from service worker cache", files.sw.includes("pathname.startsWith(\"/api/\")")],
  ["API fetches bypass the browser response cache", files.api.includes('cache: "no-store"')],
  ["offline-first personal data wording", files.page.includes("local commute shortcut") && files.page.toLowerCase().includes("offline")],
];

const failures = checks.filter(([, passed]) => !passed).map(([name]) => name);
if (failures.length > 0) {
  console.error(`UX_CONTRACT_HARNESS=FAIL\n${failures.map((failure) => `- ${failure}`).join("\n")}`);
  process.exit(1);
}

console.log(`UX_CONTRACT_HARNESS=PASS checks=${checks.length}`);
