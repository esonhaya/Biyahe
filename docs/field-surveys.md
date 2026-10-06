# Trusted Field Surveys

Biyahe field surveys are private transport-evidence collection, not public crowdsourcing or user-location tracking. They let an accountable observer record a bounded ride or boarding-area visit while offline, then import and review it later. A recorded observation is evidence of what was observed on that occasion; it is not automatically a canonical route, verified service pattern, fare rule, or transfer.

## Offline package v1

The versioned JSON format is `api/database/data/field-survey-package-v1.template.json`. The template is deliberately empty. The current Cebu campaign snapshot is `api/database/data/field-survey-priority-package-2026-10-06.json`: it contains PLANNED 12L outbound, 17B outbound, and both unresolved Fuente/B. Rodriguez interchange tasks, with no observations or collection timestamps. It is a plan only; replace the observer placeholder before validation. Regenerate current priorities and the detailed checklists with `php artisan biyahe:surveys:plan --pairs=6 --json`.

The root is an object with `schema_version: 1` and a `surveys` array. Each survey requires a stable ID, title, purpose, Cebu city stable ID, accountable `observer_reference`, and a session status. Planned survey packages contain an explicit observer placeholder and validation rejects it until replaced. Route and directional-pattern stable IDs are optional. `observer_user_id` may identify an existing Biyahe account without embedding contact details. Session timestamps, notes, observations, and trace points are optional as appropriate to the lifecycle.

Each observation has a stable ID, contiguous positive sequence, supported `type`, explicit-timezone ISO 8601 `observed_at`, and a structured `fact` object. It can refer to existing route, pattern, stop, and place stable IDs. A location is optional except for device-derived points and negative `SERVICE_NOT_OBSERVED` evidence. Locations use GeoJSON `Point` with `[longitude, latitude]` in WGS84/SRID 4326. `accuracy_m` records reported device accuracy, while optional `location_precision` uses `POINT_ESTIMATE`, `BOARDING_AREA`, `LANDMARK`, `GENERAL_AREA`, or `UNKNOWN`; neither implies an exact curb or bay. `location_method` records DEVICE, MANUAL, or UNKNOWN. Trace points are optional and ordered; timestamps, when supplied, cannot move backwards. The importer caps packages at 5 MiB, 20 surveys, 1,000 observations and 2,000 trace points per survey.

Observation types include route seen, vehicle boarded, boarding/alighting point, stop served, pass-through, direction cue, signboard, terminal, transfer, fare paid, route endpoint, service not observed, and other. Type-specific facts are validated (for example, a fare observation requires a positive amount and a negative service observation requires its expected service, watched location, and 1–720 minute observation window). Free-text notes supplement these fields; they do not replace them.

Supporting media are references only: up to ten HTTPS URLs or opaque `ref:` identifiers per observation. The importer does not upload files, follow URLs, accept local paths, or store arbitrary binaries. Keep photos and private media in access-controlled storage and do not place secrets, personal contact information, or unrelated location history in survey packages.

## Validate and import

Run commands from `api/`:

```sh
php artisan biyahe:surveys:validate /path/to/field-survey.json
php artisan biyahe:surveys:import /path/to/field-survey.json --dry-run
php artisan biyahe:surveys:import /path/to/field-survey.json
php artisan biyahe:surveys:report --json
php artisan biyahe:surveys:plan --pairs=6 --json
```

Validation resolves stable references, checks city/route/pattern consistency, coordinate order/ranges, sequence order, timestamps, status, field limits, and structured fact values before any write. Invalid packages return a non-zero exit. Imports are transactional and append-safe: identical stable IDs and content are skipped; reused IDs with different immutable identity or conflicting session metadata fail and roll back the whole package. A planned package may be completed offline under the same ID: collection timestamps and status advance, while review states never move backward. For an offline continuation, re-export the complete session with the same identity and all existing ordered records plus additions. Imports never overwrite reviewer decisions or promote production transport facts. Once a session enters review, additional evidence belongs in a new survey.

The survey plan uses the existing evidence-priority engine once per command run and reuses its per-run journey/pattern/trust context cache, then groups current unresolved 12L and 17B pattern/association/boarding priorities and active unresolved transfers into tasks. Its default impact sample is six ordered O/D pairs; adjust `--pairs` within configured bounds. It prints the source record IDs, current fact states, impact counts, checklist, an empty import template, elapsed time, query count, planner runs, and cache counters. Grouped candidate-journey impact values are explicitly labeled as non-deduplicated; sampled O/D pairs are deduplicated. Survey planning does not create a survey row or mark a task complete. The field-survey report also includes the ranked unresolved facts from one planner run.

## Review and evidence claims

The only review UI is the authenticated Filament admin panel:

- **Transport Survey Sessions** lists and inspects survey status, route/direction, observer reference, observations, trace samples, reported accuracy, current mapped route geometry/stops, and a private schematic comparison view. Suspicious trace jumps are highlighted for review; nothing is snapped to roads.
- **Field Observations** supports submit, accept as evidence, request clarification, and reject with a recorded reason.
- **Field Evidence Claims** lets a reviewer propose a claim against a stable production record and narrow fact scope, link accepted real observations and optional matching-scope provenance sources, then separately attach or reject that claim.

Observation states are `DRAFT`, `SUBMITTED`, `ACCEPTED_AS_EVIDENCE`, `REJECTED`, and `NEEDS_CLARIFICATION`. Session states are `PLANNED`, `IN_PROGRESS`, `COMPLETED`, `ABORTED`, `UNDER_REVIEW`, and `REVIEWED`. Claims begin `PROPOSED`, then become `ATTACHED` or `REJECTED`. Synthetic test sessions cannot be accepted or used in claims.

Attaching a claim creates a `DIRECT_OBSERVATION` provenance source scoped to that claim and links it to the fact-target claim. It does **not** alter the target record's verification state, existing provenance pointer, geometry, stop order, or routing eligibility. The normal evidence process must separately determine whether the evidence is adequate to change production facts. Claiming `CONFLICTS_WITH_EXISTING` opens a retained evidence conflict; the old production fact is not silently overwritten. `SUPPORTS_EXISTING`, `ADDS_NEW_INFORMATION`, and `INSUFFICIENT_TO_DETERMINE` are review classifications, not trust states.

The JSON report excludes synthetic surveys and observations. It reports accepted/rejected observations, claim comparisons, supported fact count, multiple-observation support, supporting source count, and observation count. Observer references and private review notes are not included in report output. Multiple observations are not automatically considered independent: the report distinguishes accounts/references where present, but a reviewer still evaluates observer independence and correlated conditions.

## Conservative field policy

- One observation supports only the occurrence recorded: e.g. one vehicle boarded at a loading area on the observed date.
- A completed ride can support that observed direction and observed sequence. One ride alone does not prove a service always follows that exact pattern.
- Observed service at a stop is not automatically permission to board/alight there on every trip. Review association, sequence, direction, and permission facts independently.
- Device coordinates retain reported accuracy and observation precision. A coordinate does not establish an exact curb, terminal bay, or safe pedestrian path.
- Seeing two services nearby does not prove a practical transfer. Observe both boarding points, direction compatibility, access, walking separation, and crossing conditions; do not convert proximity into verification.
- `SERVICE_NOT_OBSERVED` records a bounded observation window. It never proves a service does not operate.
- `FARE_PAID` records the amount observed for that passenger/trip. It cannot establish an official fare formula or calculate a journey fare.
- GPS samples remain an observation trace, not canonical `RoutePattern` geometry. The admin sketch compares samples with current route geometry and mapped stops, flags possible jumps for review, and does not claim street matching.

## Privacy and retention

Start and stop a survey deliberately. For an offline Termux collection, copy the planned JSON package to a private working folder, replace the observer placeholder, record only during the planned survey, then update the session interval/status and add ordered observations before reconnecting. From `api/`, run `php artisan biyahe:surveys:validate /private/path/survey.json`, then `php artisan biyahe:surveys:import /private/path/survey.json --dry-run`, and import only after the dry run succeeds. Capture only transit-relevant stops and trace points during that bounded task; do not run a background location service or retain unrelated movement before/after the ride. Use an accountable short observer reference or existing account ID instead of contact details. Observer IDs, references, notes, precise field locations, and raw survey traces are not exposed through public APIs; there are no public field-survey endpoints. Access is through the existing authenticated admin panel or controlled server-side CLI. Limit database, log, backup, and media access accordingly. Do not commit real observations or large field files to Git.

No field observations were created for Batch 008. All automated test records are marked synthetic or are isolated test-database fixtures with explicit `SYNTHETIC TEST` identifiers/notes; they are not seeded into production. The production report excludes `is_synthetic` sessions.
