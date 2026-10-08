# Batch 021 product audit and release notes

Checked 2026-10-08. The repository has no browser binary, Playwright package,
or physical-device harness available, so visual findings below are split into
source-tested defects and design improvements. No screenshot is claimed.

## Screen and journey inventory

| Surface | Primary journey | Before | Batch 021 response |
| --- | --- | --- | --- |
| Home | Open → choose From/To → search | Strong data layer, weak hierarchy; long secondary sections | App header, quick anchors, onboarding, connection state, improved search form |
| Search | Type → choose place → plan | No keyboard selection or stale-response guard | Accessible debounced comboboxes and swap/clear actions |
| Results | Compare → open option | Facts existed but were visually dense | Route/direction chips, factual comparison filters and ranking reasons |
| Commute Card | Read → save/share/replan | Shared instructions were present | Stronger current-action labels, uncertainty treatment and mobile actions |
| Map | Inspect route/boarding | Map had all-place/stop noise and no text equivalent | Lower-noise labels, route focus, text summary and map error copy |
| Saved journeys | Reopen offline → refresh online | Correct snapshot semantics but quiet stale state | More prominent local snapshot language and navigation anchor |
| Ride Mode | Start → manual board/alight/arrive | Correct foreground-only state machine | Focused current-action presentation and pause/offline copy polish |
| Pulse | Check advisories | Correct semantics but competed with planning | Secondary placement and clearer source/freshness hierarchy |
| Route Explorer | Browse route/pattern | Full list, raw verification labels | Filtered directory and commuter-facing trust language |
| Nearby | Explicit location → discover | Correct privacy boundary | Plain “mapped candidate” language and accessible result details |
| Field Mode | Authorized mission → draft/sync | Batch 020 feature | Kept separate and discoverable only as an operator tool |

## P0/P1 implementation record

P0 fixed: primary hierarchy, search keyboard behavior, debouncing/stale
responses, factual journey comparison, map text alternative, visible online /
offline state, focus styling and touch targets.

P1 fixed: onboarding, route filter, section navigation, plain trust labels,
saved/offline emphasis, reduced-motion handling and error/empty copy. Browser
visual QA remains unavailable and is not represented as passed.

Remaining P2: real-device install testing, screen-reader testing on Android or
iOS, full WCAG audit, and physical outdoor map usability.

## Screens changed and next release checks

The changed production surfaces are the home/search shell, journey comparison
and detail, Commute Card, MapLibre panel, Pulse, Route Explorer, Nearby,
Personal Commute Tools, Ride Mode and the Field Mode entry point. The static
UX contract covers keyboard combobox behavior, stale-search protection,
connection/offline messaging, onboarding dismissal, factual trust labels,
map text fallback and the conservative service-worker cache boundary.

Before a real-device beta, run the built app over HTTPS on a narrow Android
phone and an iPhone-sized viewport; test denied geolocation, large text,
screen-reader focus, reduced motion, install/update, offline reload, saved
journey reopening, Ride Mode reload and an offline Field Mode draft/sync. A
human should also verify map gestures and boarding-language comprehension at
Ayala, Carbon and one transfer area. These are release checks, not claims
made by the local build.

## Performance and known product limits

The captured live PostgreSQL/PostGIS planner sample was 5.23s for a bounded
Colon-to-Ayala case, with 4.18s in transfer expansion, 65 queries and eight
pedestrian computations. Batch 021 did not change planner semantics; the
remaining transfer bottleneck is measured and should be optimized in a
planner-focused batch rather than hidden behind UI changes. The current
network remains evidence-limited: mapped routes are not automatically
confirmed service, boarding precision can be unknown, straight-line walking
is only an estimate, no ETA/live vehicles are available, Ride Mode is
foreground/manual, and real field observations are still required for further
trust promotion.

## Capability boundaries retained

- 30 routes / 58 patterns and canonical transport/evidence data are unchanged.
- Unknown boarding remains unknown; route geometry does not verify service.
- Straight-line walking fallback is labeled as an estimate.
- Ride Mode remains foreground-only with manual boarding/alighting and no ETA.
- Saved journeys, favorites and recents remain local browser data.
- Field Mode remains separate from Ride Mode and does not auto-promote evidence.
