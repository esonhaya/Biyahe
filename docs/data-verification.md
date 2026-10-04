# Data verification

## Lifecycle

- DRAFT is new or unconfirmed information. It must not be presented as a
  verified commuter instruction.
- VERIFIED means a reviewer checked the fact against recorded provenance.
- STALE means previously verified information may have changed and needs
  rechecking.
- RETIRED means the record is no longer current while its history remains
  auditable.

Important places, stops, routes, patterns, and fare records should point to a
provenance source with a source type, title, publisher when known, URL when
available, and access date. A link alone is not proof of verification.

Community accounts, reputation, submissions, and moderation queues are not
implemented in Batch 001. The authenticated admin is the controlled editing
surface; a reviewer should check evidence before changing a record to
VERIFIED.

The seeded records use a DEMO_FIXTURE source, DRAFT verification, and
is_demo=true. They exist to exercise relationships, ordering, spatial
rendering, and admin forms; they are not real Cebu transport claims.
