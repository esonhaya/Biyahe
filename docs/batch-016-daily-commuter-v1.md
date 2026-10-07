# Batch 016 — Cebu Daily Commuter V1

Batch 016 deepens the existing bounded Cebu network and connects it to a
daily commuter workflow. It does not claim complete Cebu coverage or replace
transport evidence with map proximity.

## Current capability

Supported in the current production slice:

- bounded place search using canonical names, validated aliases, normalized
  punctuation/case, and city context;
- direct, one-transfer, and two-transfer journey planning over the existing
  transport and pedestrian data;
- request-scoped planner reuse for patterns, transfer snapshots, and trust
  facts;
- factual journey explanations, Commute Cards, saved offline snapshots, and
  foreground Ride Mode;
- local-only recent searches, explicitly labelled favorite places, and
  favorite A → B commutes;
- Biyahe Pulse, reviewed disruption overlays, explicit replan, Can I Ride
  reason codes, and operational uncertainty; and
- no-background-GPS privacy boundaries and the existing field/evidence
  workflow.

Partial or evidence-limited:

- route coverage is strong near Colon/Carbon, Fuente/Jones, Capitol/Escario,
  Ayala/Business Park, IT Park/Lahug, Mabolo, SM/North Reclamation,
  Guadalupe, and the Pardo/south anchors, while Banilad, Talamban, and
  Labangon remain partial in the bounded audit;
- 47 of the 48 production stops still have UNKNOWN boarding precision;
- transfer verification, exact curb/bay guidance, many alighting details, and
  current fares remain evidence-limited; and
- some useful origin/destination pairs remain unsupported or have unresolved
  walking access. The release audit records these instead of implying that
  Cebu has no service.

Unavailable by design:

- ETA, live vehicles, traffic prediction, automatic rerouting, background or
  continuous GPS, automatic boarding/alighting detection, full offline city
  routing, and automatic evidence harvesting.

## Release audit

Run the bounded real-PostGIS audit from the API directory:

```sh
php artisan biyahe:batch016:audit --pairs=12
php artisan biyahe:doctor --no-ansi
```

The audit is deliberately an explicit representative matrix, not an
all-pairs benchmark. It reports production/demo/synthetic counts separately,
route-corridor coverage, route-option shape, pedestrian fallback use,
remaining gaps, and request-scoped planner-cache metrics.

For the Batch 016 release matrix, the real graph produced 10 routable of 12
pairs, with direct, one-transfer, and two-transfer options represented. The
remaining gaps were `banilad-town-center → colon` (no nearby supported
boarding access) and `labangon → cebu-it-park` (unresolved walk access).

## Reproducible checks

From `web/`:

```sh
npm run test:personal-commute
npm run test:commute-cards
npm run test:ride-mode
npm run test:pulse-replan
npm run typecheck
npm run lint
npm run build
```

Local recent searches, favorite places, favorite commutes, saved journey
snapshots, and Ride Mode state are versioned and validated browser data.
They contain no GPS trail, device identifier, or server-side commute history.
Favorite commutes request a fresh route while online; saved journeys remain
specific offline snapshots. New replanning still requires connectivity.

## Data and privacy boundary

Batch 016 adds no transport evidence, field observations, fare claims, route
geometry, OSM data, or synthetic production rows. Existing canonical trust,
boarding precision, transfer uncertainty, disruption authority, foreground
location, and pedestrian-network semantics remain unchanged. Any future
evidence expansion must go through the versioned provenance/import workflow
and support the exact fact being promoted.
