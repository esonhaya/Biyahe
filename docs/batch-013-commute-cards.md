# Batch 013 — Commute Cards and local journey snapshots

Batch 013 turns the existing structured journey response into one derived,
trust-aware instruction model used by result cards, the selected journey
detail, saved snapshots, and Ride Mode. It does not change the transport
dataset, pedestrian graph, route geometry, fares, or evidence records.

## Focused checks

From `web/`:

```sh
npm run test:commute-cards
npm run test:ride-mode
npm run typecheck
npm run lint
npm run build
```

The commute harness covers direct, one-transfer, and two-transfer journeys,
network and fallback walking, precision/trust/fare wording, versioned local
snapshot restore, offline instruction rendering, and bounded share text.

## Saved journeys and offline use

Saved journeys use the browser key `biyahe.saved-journeys.v1`, schema version
`1`, and a maximum of 20 snapshots. A snapshot contains the selected journey
needed to render instructions and any retained journey geometry; it contains
no foreground location samples or movement history. Corrupt, incompatible,
oversized, or GPS-bearing snapshots are ignored safely.

Opening a saved snapshot does not call the journey API. It can start manual
Ride Mode and use retained geometry locally where available. New search or
refresh is explicitly online-only. Basemap tiles are not promised offline.

## Sharing

Share produces short human-readable text through the Web Share API when
available, with clipboard fallback and a separate Copy action. It excludes
GPS, internal IDs, evidence metadata, and raw journey JSON. Stable share links
are intentionally not implemented in this batch.

## Validation / Doctor

Run Doctor with the existing PostgreSQL/PostGIS runtime when available:

```sh
DB_CONNECTION=pgsql DB_HOST="$PWD/api/.runtime" DB_PORT=55432 \
DB_DATABASE=biyahe DB_USERNAME=u0_a664 DB_PASSWORD= \
php artisan biyahe:doctor
```

Doctor V13 checks the absence of server-side saved journey history and records
the instruction, precision, and fare trust boundaries. It does not require
browser localStorage or device geolocation.
