# Evidence verification policy

## Fact-level trust

Verification is attached to the fact being asserted, not inherited from a
parent record. A verified landmark coordinate does not verify a stop's
boarding area; a verified route identity does not verify a directional
pattern; a route pattern or nearby stop does not verify a pattern-stop
service association or a transfer.

Each pattern-stop association tracks independent `service`, `sequence`,
`boarding`, and `alighting` states and provenance references. For a ride,
Biyahe evaluates service, sequence, and boarding evidence at the boarding
association, and service, sequence, and alighting evidence at the alighting
association. It does not downgrade a ride because an unrelated permission at
that stop is unknown. Stop-coordinate trust and stop-level boarding trust are
separate. A boarding fact also requires a usable precision: `UNKNOWN` cannot
support a verified boarding instruction.

A `VERIFIED` journey means every critical used fact is currently supported:
origin/destination locations, route identity, directional pattern, service
association, forward sequence, boarding permission/location, alighting
permission/location, and each explicit transfer relationship when present.
Fare evidence is independent. A journey can have verified route guidance and
still return `Fare unavailable`; no fare amount is implied by that route
trust.

Public trust states retain their existing meanings: `VERIFIED` when all
critical claims qualify, `MIXED` when verified and unverified claims are
combined, and `UNVERIFIED` when no critical verified claim remains. `STALE`
or `RETIRED` transport records are not eligible for ordinary routing. A
source that has aged out, is superseded, or has an open conflict cannot
support a `VERIFIED` claim. An old source by itself does not prove a fact
false; a record whose entity state remains eligible may be used only with
reduced trust until reviewed.

## Source quality and scope

The configured source-quality order is:

1. Competent government authority, including LTFRB, DOTr, or an LGU.
2. Operator, terminal, or transport cooperative.
3. Official institutional transport guidance or government reporting of an
   authority's decision.
4. Compatible public/open transport datasets.
5. OpenStreetMap community data.
6. Credible current secondary references.

This is a research priority, not automatic verification. Source scope must
match the claim: a route-list source may support identity but not a precise
ordered stop sequence, a curbside boarding point, transfer safety, current
fare applicability, or operating hours. Direct observation can be recorded
without a URL, including its method and reviewer.

`ProvenanceSource` records source type, title, publisher, optional URL,
published/observed/effective/checked dates, license, fact scopes,
verification method, reviewer, notes, conflict group/status, and a
superseding source reference. Historical source rows remain available after
supersession. An unresolved credible-source disagreement is marked `OPEN`
and prevents a verified claim until it is reviewed; `RESOLVED` retains the
conflict history without blocking the selected current evidence.

## Freshness

Freshness is a review reminder and a verified-claim gate, not an automatic
assertion that an old fact is false. Current/aging windows are configurable
in `api/config/biyahe.php`:

- Landmark location: current 730 days, aging through 1,460 days.
- Stop location: current 730 days, aging through 1,095 days.
- Route identity: current 365 days, aging through 730 days.
- Pattern and pattern-stop facts: current 180 days, aging through 365 days.
- Boarding and transfer facts: current 90 days, aging through 180 days.
- Fare evidence: current 30 days, aging through 90 days.
- Missing check date: `UNKNOWN_FRESHNESS`.

The policy classifies evidence as `CURRENT`, `AGING`, `STALE`, or
`UNKNOWN_FRESHNESS`. `checked_at` describes the latest review, while source
publication/observation and effective dates preserve when the underlying
claim was issued or applied. Fare rules with an expired effective period are
not current even if recently opened.

## Boarding and transfers

Boarding precision is explicit: `EXACT_BOARDING_POINT`, `BOARDING_AREA`,
`APPROXIMATE_LOCATION`, or `UNKNOWN`. A stop's coordinate does not imply an
exact waiting curb. Public instructions use “at”, “in the mapped area”, or
“near” according to recorded precision and evidence; no street-side
instruction is generated from route geometry alone.

Transfer records use `PENDING_REVIEW`, `SUPPORTED`, `REJECTED`, and
`NEEDS_RECHECK`. Nearby-stop candidates are screening leads, never automatic
transfers. Rejected dataset entries require `review_notes`; a rejection in
one direction suppresses the mirrored proximity candidate too. Transfers
remain directional where road sides or service permissions may differ.
Rejections store a fingerprint of the two stop locations, service associations,
permissions, verification metadata, and relevant provenance; unchanged rejected
pairs stay suppressed, changed pairs resurface, and `NEEDS_RECHECK` always
returns to review. No candidate is automatically promoted to `SUPPORTED`.
`php artisan biyahe:transfers:candidates` reports bounded stop pairs,
distance, route/pattern context, and current trust without promoting them.

## Fare evidence

The expansion dataset keeps general traditional-PUJ fare rules at vehicle
class scope, never copied across route IDs. The 2023 LTFRB guide is retained as
historical `STALE` evidence. PNA reports the September 2026 provisional
traditional-PUJ rate (PHP 14 for the first four kilometres, PHP 2 per
succeeding kilometre) and temporary-guide validity through December 31, 2026.
PTV reports the regular and discounted amounts; PIA corroborates the regular
rule. These are government reports, not a directly inspected route-specific
Cebu matrix, so the four current rules remain `UNVERIFIED`. No fare total is
calculated from these general rates.

`FARE_RULE_KNOWN` is distinct from `FARE_CALCULABLE_FOR_JOURNEY`. No
straight-line origin/destination or stop distance is used as fare distance.
Until authoritative applicability and a fare-distance basis are available,
journey responses keep the amount null and say `Fare unavailable`.

## Evidence review and priorities

Validate a dataset before import and use the importer's dry-run before
writing:

```sh
php artisan biyahe:data:validate cebu-network-expansion-2026-10-05.json
php artisan biyahe:data:import cebu-network-expansion-2026-10-05.json --dry-run
php artisan biyahe:evidence:report --json --pairs=24 --max-transfers=2
```

`biyahe:evidence:report` emits dynamic counts and a machine-readable audit
for each production place, alias, route, pattern, pattern-stop fact, stop
location/boarding fact, transfer, fare, and provenance source. Its priority
list is bounded and deterministic. It combines affected O/D pairs, candidate
and recommended journeys, transfer/boarding importance, trust bottlenecks,
and source freshness using the centralized weights in configuration. Each
item identifies the production record, current trust, affected pairs, and
reason; its score is a work-order aid, not a statistical confidence value.

Curated journeys in `api/database/data/curated-cebu-journeys-2026-10-05.json`
are a bounded progress benchmark. The report records the route path actually
returned and its current critical-evidence bottlenecks; expected route codes
are context, not a rule that forces a preferred path.

Run `php artisan biyahe:doctor` after reviewing evidence. Doctor V6 fails
deterministic provenance-scope, conflict, exact-boarding, and transfer-review
integrity violations; incomplete coverage and stale evidence are warnings.
