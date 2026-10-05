# Data verification

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

Important places, stops, routes, patterns, transfer relationships, and fare records must point to a
ProvenanceSource when they are not demo data. A source records the practical
evidence trail:

- source type and title;
- publisher and optional URL/reference;
- checked_at and optional effective dates;
- license;
- verification method and reviewer/process;
- notes.

A URL is optional because observations, printed notices, or direct
administrative checks may not have one. A URL alone is not proof of
verification.

Pattern-stop associations have their own verification state and provenance.
A verified stop coordinate, route identity, or route geometry never promotes
the associated boarding/alighting sequence automatically. Stops may carry a
boarding note, side, and direction lead; these remain reviewable claims until
the evidence supports the practical waiting instruction.

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

Community accounts, reputation, submissions, and moderation queues are not
implemented yet.
