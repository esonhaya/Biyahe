# Batch 026 commuter product gap audit

Checked 2026-10-08 against the current client and the Batch 021 audit. Batch
021 work is treated as complete where the current implementation and harness
show it; this list records remaining opportunities rather than replaying that
work. No live browser or physical-device findings are claimed.

Priority is ranked by commuter impact first. Effort is S/M/L; dependencies name
the source of evidence or service needed. A11y/mobile marks whether the gap
directly affects those users. Testability notes the smallest useful check.

| Rank | Surface | Concrete opportunity in current flow | Impact | Effort | Dependency | A11y/mobile | Testability | Batch 026 |
| ---: | --- | --- | :---: | :---: | --- | --- | --- | --- |
| 1 | Search / results | A slow A-to-B request has no cancel action; changing places can clear the screen while a late response puts the old journey back. | H | S | API request | Keyboard and mobile | Request-gate fixture | Done |
| 2 | Results | A transfer filter can hide every result; the explanatory text says to show all but had no control to do so. | H | S | None | Touch and keyboard | UX contract | Done |
| 3 | Home / saved | A favorite place only fills From, so using a saved destination requires extra editing or swapping. | H | S | Local place snapshot | Touch targets | UX contract | Done |
| 4 | Pulse | Failed initial/filter refresh cleared advisories and looked like an empty feed; no retry was shown in the Pulse section. | H | S | Pulse API | Screen-reader status | Source contract | Done |
| 5 | Journey detail / map | “Show … on map” changed map state but left the map far below the instruction; reduced-motion users also need nonanimated focus. | H | S | Existing geometry | Keyboard/mobile | UX contract | Done |
| 6 | Ride Mode | End Ride Mode immediately cleared persisted progress without a confirmation step. | H | S | Local session | Accidental touch | State and UX harnesses | Done |
| 7 | Search | Suggestion selection can point beyond the current list after a retry returns fewer options. | M | S | Search API | Combobox keyboard | UX contract | Done |
| 8 | Search | Clearing a field leaves keyboard focus on the clear button instead of returning it to the edited field. | M | S | None | Keyboard/mobile | UX contract | Done |
| 9 | Nearby | Permission denial and unsupported location explain the fallback but offer no direct link to place search. | M | S | None | Keyboard/mobile | UX contract | Done |
| 10 | Route Explorer | A slower pattern-detail response can replace a newer pattern selection. | M | S | Pattern API | Status announcement | Request-gate/source contract | Done |
| 11 | Can I Ride | A delayed check can finish after From, To, or route changes and leave a result for old inputs. | M | S | Can I Ride API | Status announcement | Request-gate/source contract | Done |
| 12 | Saved journeys | Refresh and other planning requests can overlap; stale completions can replace a newer selected journey. | M | S | Journey API | Mobile status | Request-gate/source contract | Done |
| 13 | Search | Alias suggestions show a useful alias, but do not say when the typed query matched that alias rather than the canonical place name. | M | M | Search match metadata | Ambiguous selection | API fixture | Follow-up |
| 14 | Map | MapLibre tile/style failure is reported with a text alternative, but a transient failure has no retry control. | M | M | Tile/style service | Map alternative | Map component fixture | Follow-up |
| 15 | Route Explorer | Pattern-load errors use the page-wide alert, so the failed direction and retry action are not adjacent to the selected pattern. | M | S | Pattern API | Focus and context | Component fixture | Done |
| 16 | Nearby | A location lookup has no explicit cancel action while the browser permission prompt or network request is pending. | M | M | Browser geolocation | Mobile foreground flow | Mock geolocation | Follow-up |
| 17 | Offline | Saved journey age is available, but there is no compact age band to help sort old snapshots before opening one. | M | S | Local saved timestamp | Small screen scanning | Saved-journey fixture | Done |
| 18 | Ride Mode | A resumed local guide needs a visible age/current-service reminder; progress persistence alone does not refresh route or advisory truth. | M | S | Local session timestamp | Mobile trip use | Ride Mode fixture | Follow-up |
| 19 | Route Explorer | A no-match route filter has no one-tap clear action. | L | S | None | Touch and keyboard | UX contract | Done |
| 20 | Field Mode | The offline mission view should keep the cache timestamp adjacent to claims after a failed refresh, not only above the overall mission list. | M | S | Local mission cache | Field screen readability | Field Mode harness | Done |
| 21 | Field Mode | A duplicate outbox sync needs per-draft visible status so an operator can distinguish queued, accepted, and review-pending work. | H | M | Sync response | Field mobile use | Idempotency fixture | Done |
| 22 | Field Mode | Conflict copy can link the conflicting claim and review state to the affected draft before the operator retries. | M | M | Sync conflict response | Error comprehension | Conflict fixture | Done |
| 23 | Home | Long secondary sections make return-to-planning cumbersome after scrolling through map and field tools. | L | M | None | Mobile navigation | DOM/keyboard check | Follow-up |
| 24 | Accessibility | Static checks do not validate 320px layout, zoom, high-contrast rendering, Android screen-reader order, or real focus visibility. | H | L | Real browser/device | All | Physical device QA | Device required |

## Batch 026 implementation summary

- Added a small request gate used by journey, saved-journey refresh, route
  pattern, and Can I Ride requests. Superseded requests are aborted and late
  results are ignored; journey requests expose a cancel action.
- Made the transfer filter empty state actionable and reset the filter for a
  new journey search.
- Added direct destination assignment for local favorite places.
- Made Pulse loading and failure explicit and retryable; aborted filter
  requests no longer clear a newer result.
- Connected explicit instruction/pattern map actions to the map section and
  honored reduced-motion preferences.
- Kept route-pattern detail loading, failure, and retry feedback beside the
  selected direction; changing the journey clears pending pattern state.
- Added an inline Ride Mode end confirmation before clearing local progress.
- Added a one-tap Route Explorer search reset and placed the offline mission
  snapshot timestamp/staleness warning beside the claim list.
- Added a relative saved-snapshot age label while retaining the explicit
  warning that route and service details may have changed.
- Shows each queued/rejected/received Field Mode draft with its claim prompt
  and receipt/review state, and blocks mission submission while that session
  has pending drafts. Evidence remains review-only and is never auto-promoted.
- Kept the outstanding items above as follow-up candidates; this audit does
  not imply visual, screen-reader, or field validation.
