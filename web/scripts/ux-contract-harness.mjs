import { readFile } from "node:fs/promises";

const files = {
  page: await readFile(new URL("../src/app/page.tsx", import.meta.url), "utf8"),
  card: await readFile(new URL("../src/components/CommuteCard.tsx", import.meta.url), "utf8"),
  savedJourneyLibrary: await readFile(new URL("../src/lib/savedJourneys.ts", import.meta.url), "utf8"),
  map: await readFile(new URL("../src/components/MapView.tsx", import.meta.url), "utf8"),
  nearby: await readFile(new URL("../src/components/NearbyRoutes.tsx", import.meta.url), "utf8"),
  pulse: await readFile(new URL("../src/components/BiyahePulse.tsx", import.meta.url), "utf8"),
  ride: await readFile(new URL("../src/components/RideMode.tsx", import.meta.url), "utf8"),
  personal: await readFile(new URL("../src/components/PersonalCommuteTools.tsx", import.meta.url), "utf8"),
  field: await readFile(new URL("../src/components/FieldMode.tsx", import.meta.url), "utf8"),
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
  ["place search failure can retry the same query", files.page.includes("Retry place search") && files.page.includes("onRetrySearch") && files.page.includes("destinationSearchRetryToken") && files.page.includes("originSearchRetryToken")],
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
  ["route explorer distinguishes load failure from an empty network", files.page.includes("networkLoadError") && files.page.includes("Mapped route data could not be loaded") && files.page.includes("No mapped route patterns are currently available.")],
  ["route explorer offers an online retry", files.page.includes("onClick={retryNetworkLoad}") && files.page.includes("disabled={!online || loadingNetwork}")],
  ["nearby refresh clears stale candidates before location lookup", files.nearby.indexOf("setRoutes([]);") >= 0 && files.nearby.indexOf("setRoutes([]);") < files.nearby.indexOf("getCurrentPosition")],
  ["nearby reports candidate loading and clears failures", files.nearby.includes("Loading mapped boarding candidates for this location…") && files.nearby.includes("Nearby routes could not be loaded")],
  ["journey requests cancel and reject superseded results", files.page.includes("Cancel request") && files.page.includes("planJourney(from.id, to.id, request.signal)") && files.page.includes("isCurrent(request)")],
  ["changing selected places cancels pending journey and pattern work", files.page.includes("function cancelJourneySearch()") && files.page.includes("cancelJourneySearch();\n    cancelPatternRequest()")],
  ["Can I Ride and pattern detail reject late responses", files.page.includes("canIRide(rideRouteCode, origin.id, destination.id, request.signal)") && files.page.includes("getRoutePattern(pattern.id, request.signal)")],
  ["search selection stays in range after options shrink", files.page.includes("Math.min(activeIndex, options.length - 1)") && files.page.includes("options[selectedIndex]")],
  ["clearing a search returns keyboard focus to its field", files.page.includes("onClear(); inputRef.current?.focus()")],
  ["filtered journey empty state has an all-options action", files.page.includes("Show all journeys") && files.page.includes('setJourneyFilter("ALL")')],
  ["instruction map action scrolls to the focused map and respects reduced motion", files.page.includes("focusInstructionOnMap") && files.page.includes("scrollIntoView({ behavior: reduceMotion ? \"auto\" : \"smooth\"")],
  ["Pulse loading and failure are explicit and retryable", files.pulse.includes("loading && !pulse") && files.pulse.includes("error &&") && files.pulse.includes("Try again") && files.page.includes("Pulse could not be loaded")],
  ["location failure offers place-search fallback", files.nearby.includes("Search a place instead") && files.nearby.includes("href=\"#planning\"")],
  ["Ride Mode ending asks before clearing local progress", files.ride.includes("End this guide and clear its saved progress") && files.ride.includes("Keep Ride Mode open")],
  ["favorite places can fill either side of a commute", files.personal.includes("as starting place") && files.personal.includes("as To") && files.personal.includes('"destination"')],
  ["Route Explorer offers a one-tap filter reset", files.page.includes("No mapped route matches that search.") && files.page.includes("Clear route search") && files.page.includes('setRouteQuery("")')],
  ["Field Mode labels stale mission snapshots beside claim review", files.field.includes("staleMissionSnapshot &&") && files.field.includes("Claim status may have changed; verify before recording an observation.")],
  ["Field Mode cannot submit a mission with local drafts still pending", files.field.includes("const synced = await syncOutbox()") && files.field.includes("if (!synced)") && files.field.includes("Mission not submitted: local observations")],
  ["Field Mode shows per-draft receipt, rejection, and claim context", files.field.includes("Observation sync status") && files.field.includes("Server rejection:") && files.field.includes("pending human review") && files.field.includes("claimPromptFor")],
  ["Route Explorer shows pattern-load failure beside its retry action", files.page.includes("Could not load the stops for {selectedPattern.name}") && files.page.includes("Retry this direction") && files.page.includes("setPatternLoadError(true)")],
  ["saved offline snapshots show a deterministic age band", files.card.includes("savedSnapshotAgeLabel(savedAt)") && files.savedJourneyLibrary.includes("export function savedSnapshotAgeLabel")],
];

const failures = checks.filter(([, passed]) => !passed).map(([name]) => name);
if (failures.length > 0) {
  console.error(`UX_CONTRACT_HARNESS=FAIL\n${failures.map((failure) => `- ${failure}`).join("\n")}`);
  process.exit(1);
}

console.log(`UX_CONTRACT_HARNESS=PASS checks=${checks.length}`);
