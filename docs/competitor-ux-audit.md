# Biyahe competitor UX audit

Checked 2026-10-08 (Asia/Manila). This is a product-flow review, not a
transport-data source. Competitor route information was not imported into
Biyahe. No protected service was scraped and no competitor graphics were
copied.

## Sources reviewed

| Product | Public source | Checked | Useful observation |
| --- | --- | --- | --- |
| Google Maps | https://support.google.com/maps/answer/10271256?hl=en-uk | 2026-10-08 | Frequent trips can be saved from a selected public-transport route and surfaced on mobile. |
| Google Maps | https://support.google.com/maps/answer/6291838?hl=en-NL | 2026-10-08 | Offline areas are explicit and expire/update; public-transport directions are unavailable offline. |
| Google Maps | https://support.google.com/maps/answer/7101463?hl=en | 2026-10-08 | Directions can be shared as a bounded link or text flow. |
| Moovit | https://support.moovitapp.com/hc/en-us/articles/212011605-Plan-a-Trip-Get-Directions | 2026-10-08 | Destination-first planning, favorites/recents, comparable options, then a step-by-step itinerary. |
| Moovit | https://support.moovitapp.com/hc/en-us/articles/211391869-Getting-Started-with-Moovit | 2026-10-08 | Separate Directions, Stops and Lines entry points reduce the “what can I ride here?” problem. |
| Moovit | https://moovit.com/features/accessibility/ | 2026-10-08 | Large touch targets, bottom-positioned key controls, screen-reader support and dynamic text are explicit product concerns. |
| Citymapper | https://citymapper.com/news/1196/offline-support | 2026-10-08 | Saved itineraries are a first-class offline home surface. |
| Citymapper | https://citymapper.com/news/823/get-off-alerts | 2026-10-08 | A focused “GO” state centers the current action and get-off preparation. |
| Transit | https://help.transitapp.com/article/93-how-to-use-transit | 2026-10-08 | Nearby lines, direction switching, destination-first planning, detail expansion and pinned lines form a simple loop. |
| Transit | https://help.transitapp.com/article/90-does-transit-work-offline | 2026-10-08 | Offline capabilities are explicitly split from real-time alerts and current arrivals. |
| Sakay.ph | https://github.com/sakayph/sakayph and https://www.sakay.ph/ | 2026-10-08 | Philippine precedent: a map-based jeepney/bus/train route planner; scope is Metro Manila, not Cebu evidence. |

The source pages describe their own products and limitations. Dates above are
the audit/check dates, not claims about when each feature was first released.

## Biyahe gap matrix

| Priority | Finding in the existing Biyahe web flow | Feasible response in Batch 021 |
| --- | --- | --- |
| P0 | Home opens as a long document; the next action is not visually separated from secondary tools. | Add an app header, skip link, compact quick navigation, onboarding, connection status and a destination-led search hierarchy. |
| P0 | Search suggestions are clickable but lack robust keyboard navigation, cancellation/debounce, stale-response protection and useful empty states. | Make each field an accessible combobox with Escape/arrow/Enter behavior, debounced requests and request identity guards. |
| P0 | Journey cards contain the facts but make comparison laborious on a phone. | Add factual route/direction chips, compact transfer/walking/trust summaries, ranking explanation and explicit “Open this journey”. |
| P0 | Map information has no complete textual equivalent and can show too many labels. | Add a text map summary, route-focused emphasis, accessible controls and lower-noise stop labels. |
| P1 | Pulse appears before journey results and competes with the primary planning flow. | Keep Pulse discoverable but move it after the selected journey/detail context. |
| P1 | Route Explorer is a large unfiltered list and exposes internal verification words. | Add bounded client-side route filtering and commuter-facing trust language. |
| P1 | Offline and stale states exist but are visually quiet. | Use a consistent snapshot/offline banner and preserve “current status unavailable” wording. |
| P1 | First-time users have no explanation of mapped-vs-confirmed information or Ride Mode. | Add a dismissible three-step onboarding card; no account or location prompt. |
| P1 | Focus, reduced motion, safe-area spacing and 44px touch targets are inconsistent. | Add shared tokens, visible focus, motion reduction, safe-area padding and mobile control sizing. |
| P2 | No visual browser/device capture is possible in this environment. | Use source-level/DOM contract checks and document browser/device QA as pending. |

## Patterns adopted

- Destination-led, low-friction planning with an optional editable origin.
- Recent/favorite shortcuts near planning, while keeping the A-to-B form primary.
- Factual journey comparison instead of an unexplained “best” score.
- A step list that becomes a focused current-action surface in Ride Mode.
- Explicit snapshot freshness and offline limitations.
- Nearby and route-directory discovery with plain commuter language.
- Text alternatives and state labels that do not depend on map color.

## Patterns rejected or out of scope

- ETA, live vehicle presence, real-time arrivals and automatic get-off alerts:
  Biyahe has no authoritative live feed and existing scope forbids these claims.
- Accounts, cloud trip history, social profiles, background GPS and automatic
  rerouting: these conflict with the local-only/privacy boundaries.
- Competitor route data, screenshots, proprietary icons and copied visual assets.
- “Everything is normal” language when there are no known reviewed advisories.
- Accessibility or WCAG certification claims without a browser/device audit.

## Licensing and evidence boundary

Only source URLs and short product observations are retained here. No
competitor route facts are imported into the canonical Cebu dataset. MapLibre
continues to use the project’s existing style and OpenStreetMap attribution.
