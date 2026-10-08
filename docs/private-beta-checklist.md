# Private beta readiness checklist

Use this checklist for a small, invited Cebu commuter beta. A checked item needs
review evidence from the target deployment; local static checks do not prove
device, network, or production behavior. Do not publish or collect real field
evidence until the applicable operator and privacy checks are complete.

## Data and recovery

- [ ] Identify the production database by host and database name; confirm test
  commands and graph importers target a separate disposable database.
- [ ] Take and verify a current PostgreSQL backup. Restore it to staging and
  record schema version, database identity, and backup checksum before recovery
  or activation work.
- [ ] Recover a valid active pedestrian graph from a provenance-recorded,
  bounded OSM source. Record source URL, snapshot timestamp, SHA-256, bounds,
  license/attribution, importer version, and graph counts. OSM geometry must
  not be treated as proof of transit service.
- [ ] Check graph integrity, routeable components, stop/place connectivity,
  rollback, and preservation of canonical transport and field records before
  any activation.
- [ ] Rebuild fieldwork planning only from current canonical unresolved claims.
  Keep regenerated plans distinct from missing historical missions and require
  reviewer approval; create no synthetic observations or automatic promotion.

## Service and release

- [ ] Set production secrets outside Git; use HTTPS, a restricted database
  account, explicit trusted proxy/CORS settings, and reviewed rate limits.
- [ ] Confirm health checks, privacy-safe logging, error reporting, backup
  retention, restore instructions, and an operator rollback procedure.
- [ ] Run current migrations and the release check, Doctor, focused API checks,
  and frontend typecheck/lint/build against the intended release revision.
- [ ] Verify the active graph, supported transport claims, evidence provenance,
  and absence of synthetic operational or field records in the release target.
- [ ] Review map tile/provider terms, attribution, availability, and API limits.

## Commuter and field experience

- [ ] Test destination search, ambiguous-place selection, journey alternatives,
  boarding uncertainty, transfer/alighting steps, and text alternatives with
  local commuters. Do not display invented fare or arrival certainty.
- [ ] Check 320/360/390 px layouts, tablet/desktop, keyboard/focus order,
  reduced motion, safe areas, screen-reader labels, and touch targets on real
  devices. Record browser and device versions.
- [ ] Test offline saved-journey reopen and stale labels; verify API responses
  are not cached and local drafts survive an interrupted sync.
- [ ] Confirm Ride Mode progresses only through deliberate foreground user
  actions and does not persist or upload background location.
- [ ] If Field Mode is enabled, restrict access to named operators, publish the
  collection purpose and retention period, test idempotent sync/review, and
  verify location is collected only for an explicit bounded observation.
- [ ] Test Pulse freshness and disruption provenance; show stale status and
  avoid implying live feeds where none exist.

## Pilot controls

- [ ] Define invitation, feedback, incident contact, support window, rollback
  trigger, and beta end date before sending invitations.
- [ ] Start with a small cohort. Review errors, performance, route gaps,
  privacy requests, and feedback on a fixed cadence; pause the pilot if
  location, service, or evidence trust boundaries fail.
- [ ] Keep collected feedback separate from transit evidence. Require explicit
  review before any evidence changes canonical service data.

## Batch 023 inspection status

As of 2026-10-08, the local PostgreSQL cluster was stopped and its control file
reported `in production`; no database connection or write was attempted. The
historical Cebu pedestrian graph and field mission rows were absent from the
previous local archive audit. A verified Central Visayas OSM source snapshot
was present locally, but a bounded filter was stopped after memory availability
dropped sharply. Therefore graph activation, live release checks, and private
beta approval remain blocked until a verified backup and adequately resourced
staging restore are available. The local fieldwork plan is a draft that must be
revalidated against live canonical claims.
