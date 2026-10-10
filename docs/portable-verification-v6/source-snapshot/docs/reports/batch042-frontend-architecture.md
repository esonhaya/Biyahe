# Batch042 frontend architecture and change map

## Journey ownership

```text
Home page (origin/destination, planner request, journey list, selected journey)
  ├─ LatestPlaceSearch per field owns debounce, generation, and abort
  ├─ RequestGate owns planner, pattern, Can-I-Ride, and replan request lifetimes
  ├─ CommuteCard renders an immutable journey and owns its local walking-preview request
  ├─ MapView receives the selected journey and its eligible graph geometry
  ├─ savedJourneys.ts validates and stores snapshot-v1 records in localStorage
  └─ RideMode receives the selected journey and persists manual progress locally
```

`Home` remains the single owner of journey selection and planner results. `CommuteCard` may request a development-only walking preview, but it can publish the response only while the same selected journey identity and preview eligibility still hold. `Home` keeps the clean baseline long enough to remove that preview. Preview data is excluded from saved journey snapshots.

Search uses the existing `LatestPlaceSearch` helper. It aborts a superseded request and uses a generation check to reject a late response. Planner, pattern, Can-I-Ride, and replan requests use independent `RequestGate` instances so cancelling one interaction does not cancel unrelated work. The replan result now binds to the selected journey identity and request ticket.

## Map, instructions, and uncertainty

The selected journey and current instruction focus feed the map view. Transit geometry remains attached to the existing transit legs. Walking geometry is eligible only when it comes from the development adapter response bound to the graph and source metadata. Failed or unavailable walking legs do not gain a fallback polyline. Connector distances and straight-line access estimates keep their own labels; unknown values remain unknown. Transfer locations retain unverified disclosure.

Journey explanation and commute-instruction helpers provide source-aware fare, distance, boarding, alighting, and transfer text. Manual Ride Mode advances an explicit step index and stores the journey version; it does not infer arrival or track location. Opening a saved snapshot labels its age and possible staleness.

## Batch042 correctness changes

- Added an explicit walking-preview reducer for `DISABLED`, `IDLE`, `LOADING`, `AVAILABLE`, `PARTIAL`, `UNAVAILABLE`, `STALE`, and `ERROR`.
- Added request cancellation on journey identity changes, eligibility changes, and component unmount. Responses must match current journey and graph/source metadata before they reach page state.
- Added identity-bound replan results and cancellation when search inputs or selected journey change.
- Tightened snapshot-v1 parsing for nested forbidden location keys, malformed points/legs, geometry coordinates, route/pattern shapes, oversized structures, and older optional fields.
- Added deterministic state-transition, request-gate, snapshot, Can-I-Ride, and existing frontend contract fixtures.

## Mobile review boundary

Static CSS inspection found a single-column search layout below 700 px, one-column route cards, a map with a 360 px mobile minimum, horizontally scrollable section navigation, visible focus rules, and 44 px controls. This is source inspection only. No browser viewport or screenshot execution is represented by this document.
