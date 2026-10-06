# Data verification

For controlled offline field collection, observation review, claim attachment,
privacy, and survey import rules, see [Trusted Field Surveys](field-surveys.md).
Accepted field observations remain evidence; they do not automatically
promote a production fact to VERIFIED.

## States

Every transport entity has a verification_state and an independent is_demo
flag:

- DRAFT: new or incomplete work. It is not trusted commuter guidance.
- UNVERIFIED: a real-world lead or plausible association with recorded
  provenance, but insufficient evidence for a trusted claim.
- VERIFIED: a reviewer checked the fact against the recorded source and
  method. Demo records can never use this state.
- STALE: previously checked information whose source is outside the review
  freshness policy or needs rechecking.
- RETIRED: no longer current; retained for audit history and excluded from
  default public queries.
- DEMO: not a verification state. is_demo=true identifies artificial fixture
  data and keeps it separate from production-like records.

The API excludes demo and retired records by default. Journey routing also
excludes DRAFT and STALE service records. trusted_only=1 further restricts
collections to non-demo VERIFIED records. Responses expose
verification.state, verification.is_demo, and verification.trusted so a
client cannot mistake an unverified record for trusted guidance.

## Provenance

Important places, stops, routes, patterns, transfer relationships, and fare
records must point to a `ProvenanceSource` when they are not demo data. A
source records the practical evidence trail:

- source type and title;
- publisher and optional URL/reference;
- published/observed dates, `checked_at`, and optional effective dates;
- fact scope, conflict group/status, and optional superseding source;
- license;
- verification method and reviewer/process;
- notes.

A URL is optional because observations, printed notices, or direct
administrative checks may not have one. A URL alone is not proof of
verification.

Pattern-stop associations track service, sequence, boarding, and alighting
verification independently, each with its own provenance reference. A
verified stop coordinate, route identity, or route geometry never promotes
the associated boarding/alighting sequence automatically. Stops track
boarding precision separately from coordinate precision. `UNKNOWN` boarding
precision cannot support a `VERIFIED` boarding fact, and exact precision
without current scoped evidence is a Doctor failure.

## Review workflow

1. Research the fact and record the source before importing it.
2. Use DRAFT while the record is incomplete.
3. Use UNVERIFIED for a plausible but not yet proven route, pattern, or
   service association.
4. Use VERIFIED only after checking the fact, source date, and method.
5. Move changed information to STALE, then recheck or RETIRED it.
6. Run php artisan biyahe:doctor before publishing a reviewed dataset.

The Filament owner/admin panel exposes state, demo, stable ID, provenance, and
review filters for transport facts, pattern-stop associations, fares, and
explicit transfer relationships. The
VERIFIED option is disabled for demo records in the form; Doctor remains the
authoritative integrity backstop. A journey marked MIXED or UNVERIFIED is
shown with a plain-language warning rather than being presented as a
confirmed commute.

Transfer candidate output is intentionally advisory. It reports route and
pattern overlap, distance, current trust, and a candidate reason, but it does
not create a transfer relationship or claim a safe pedestrian crossing.
Transfer records use `PENDING_REVIEW`, `SUPPORTED`, `REJECTED`, or
`NEEDS_RECHECK`; a rejection must keep a review reason. Rejected pairs are
suppressed in both directions from proximity-candidate output. Transfer
verification still requires fact-scoped provenance, and nearby stops never
become verified automatically.

## Batch 005 evidence closure

The eight represented route identities and their cited OSM route geography
remain VERIFIED only for those specific facts. The sixteen directional
patterns and their ordered pattern-stop service associations remain
UNVERIFIED because the reviewed open data does not establish current
curbside boarding, alighting practice, or a complete passenger sequence for
each direction. The two explicit Fuente/B. Rodriguez transfer relationships
remain UNVERIFIED straight-line transfer estimates, not confirmed pedestrian
routes. Current general fare reporting is recorded for research, but no
route-specific official fare matrix was available for these services, so
journey fares remain unavailable. Batch 005 does not promote any of these
facts merely to increase routing coverage.

Community accounts, reputation, submissions, and moderation queues are not
implemented yet.

## Batch 006 claim-level review

`EvidenceFreshnessPolicy` classifies claims as `CURRENT`, `AGING`, `STALE`, or
`UNKNOWN_FRESHNESS` using claim-specific review windows. Old evidence is not
silently treated as false, but it cannot continue to support a `VERIFIED`
claim after its configured aging window. Open conflicts and superseded
sources also prevent verified trust while preserving their historical source
records.

`php artisan biyahe:evidence:report --json` outputs dynamic production claim
coverage, source freshness/conflicts, curated journey trust, and bounded
commuter-impact verification priorities. Priority items identify the
underlying record and affected O/D pairs and journeys. The report does not
edit data or promote verification. See `docs/evidence-verification.md` for
the verified-journey definition, source policy, freshness windows, fare
limitations, and review commands.
