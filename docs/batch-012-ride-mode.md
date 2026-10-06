# Batch 012 foreground Ride Mode

Ride Mode is a browser-local guide for an already-planned journey. It
supports `WALK_ACCESS`, `RIDE`, `TRANSFER_WALK`, and `WALK_EGRESS` legs for
direct, one-transfer, and two-transfer journeys.

## Privacy boundary

- The session is persisted only in browser local storage so a refresh can
  restore the selected journey and manual progress.
- The persisted shape contains no position sample, GPS trace, accuracy, or
  location timestamp.
- Browser geolocation starts only after the user presses **Enable foreground
  location** while Ride Mode is active. It uses `watchPosition` only while
  the page/session is active and clears the watch on cancel, completion, or
  unmount.
- Position samples are evaluated locally against the selected journey. They
  are never posted to the Laravel API, written to logs, promoted to field
  observations, or used to mutate route geometry/evidence.
- Boarding, alighting, and arrival always require explicit user actions.
  Location can show conservative proximity/corridor guidance but cannot
  confirm a vehicle, boarding, alighting, transfer, or service operation.

Location quality is bounded: `GOOD` is at most 20 m accuracy, `USABLE` is at
most 75 m, `POOR` is above 75 m, and samples older than 30 seconds are
`STALE`. Poor/stale samples do not trigger proximity guidance. Alighting
bands are 500 m (`APPROACHING`), 250 m (`GET_READY`), and 100 m
(`GET_OFF_AREA`) with 35 m hysteresis. No ETA, speed forecast, or traffic
claim is produced.

## Development checks

From the repository root:

    cd web
    npm run test:ride-mode
    npm run typecheck
    npm run lint
    npm run build

The deterministic harness uses the same Ride Mode domain functions for
direct, one-transfer, and two-transfer synthetic journeys. It also checks
poor/stale fixes, off-route handling, threshold hysteresis, and that a
serialized session contains no raw location state. Synthetic traces are test
data only.

For the backend integrity gate, with PostgreSQL/PostGIS running:

    cd api
    php artisan biyahe:doctor

Doctor V12 checks the supported journey-leg contract, absence of Ride Mode
raw-location/session tables, and the existing pedestrian, evidence, and
field-survey integrity checks. Browser geolocation availability is not a
Doctor requirement.

No OSM acquisition, pedestrian import, or evidence report is required for
Ride Mode development. The active Batch 011 graph is reused. The ordinary
web workflow remains:

    cd web
    npm run dev

Set `NEXT_PUBLIC_API_URL` when the API is not at
`http://127.0.0.1:8000/api/v1`. Continued foreground GPS use consumes device
battery; Ride Mode does not run in the background.
