# Cebu sources and licensing

## Current review slice

The checked datasets are `cebu-central-network-2026-10-05.json` and
`cebu-network-expansion-2026-10-05.json`. The expansion source record is
`source-osm-cebu-batch004`:

- source type: COMMUNITY_OPEN_DATA;
- checked date: 2026-10-05;
- license: OpenStreetMap ODbL;
- verification method: inspection of cited route-master metadata, directional
  PTv2 relations, sampled platform members, and retained route-member paths;
- reviewer: BIYAHE Batch 004 data review.

The source record links to the OpenStreetMap copyright page and records the
OSM object references in its notes. Route relations used for the initial
review slice are:

- relation 3169782, Jeepney 12L: Ayala - Escario - Labangon;
- relation 3177015, Jeepney 17B: Apas - Lahug - Carbon.

The expansion route identities are represented by OSM route masters 3182142
(03B), 3168675 (04L), 3201532 (06B), 3201551 (06C), 3179558 (06H), and
3179695 (13C). Route identity and cited OSM geography are VERIFIED for those
specific facts. Sampled platform records, directional pattern records, and
pattern-stop service associations remain UNVERIFIED: an OSM platform member
is not by itself proof of current curbside boarding, alighting, ordered
passenger service, or operating schedule. Generic platform names deliberately
retain that uncertainty until local or operator evidence is reviewed.

The reviewed place and stop coordinates are OSM-derived geographic facts.
Route geometry and route identities are derived from member ways and the
cited route relations, so the two Route records are VERIFIED for those
specific facts. RoutePattern records and their pattern-stop service
associations remain UNVERIFIED: the current evidence does not by itself prove
every boarding, direction, or currently operating passenger-service claim.
The two curated B. Rodriguez Extension ↔ Fuente transfer records are also
UNVERIFIED straight-line screening relationships, not confirmed pedestrian
routes. The expansion also stores the official LTFRB 2023 general traditional
PUJ fare guide as STALE historical evidence; it is not used to calculate a
current journey fare. No fare is invented in this slice.

The network currently has eight route identities, sixteen directional
patterns, forty-eight mapped stops, and two explicit unverified transfer
relationships. These counts are dataset observations, not completeness
targets. Exact route-pattern associations and boarding/alighting applicability
remain separately unverified where the cited evidence does not support them.

The OSM route research is cross-checked against the public Metro Cebu
transport overview at
https://wiki.openstreetmap.org/wiki/Public_transportation_in_Metro_Cebu.
The historical fare record links the official LTFRB publication at
https://ltfrb.gov.ph/wp-content/uploads/2024/11/Fare-Guide_Traditional-PUJ-Provisional-Fare-Increase_08Oct2023.pdf.

The evidence recheck did not find a current official Cebu route-and-stop
dataset. An [LTFRB Freedom of Information route-list request](https://www.foi.gov.ph/agencies/ltfrb/information-of-traditional-jeepney-routes-within-cebu-city/)
remained marked PENDING when reviewed; it is not itself route evidence. PNA's
[2020 report](https://www.pna.gov.ph/articles/1120577) that 04L was among
routes reopened between Lahug and Ayala is useful historical
context, not proof of today's status, exact path, boarding points, or stop
sequence. Those claims remain at the scope supported by current OSM relations
and are not promoted from the old report.

## Current fare review

PNA reported on 2026-09-28 that traditional-PUJ fares are PHP 14 for the first
four kilometres and PHP 2 per succeeding kilometre, and that the temporary
guide is valid through 2026-12-31. PTV reported the regular and
student/senior/PWD figures; PIA independently corroborated the regular rule.
These are government-reporting sources, not a directly inspected Cebu
operator fare matrix. All four 2026 general vehicle-class rules remain
UNVERIFIED and no journey total is calculated. Older general rates remain
historical STALE evidence. Legacy copies keyed to individual route IDs are
retired because the source did not support per-route fare claims.

Sources: [PNA rate report, September 28, 2026](https://www.pna.gov.ph/articles/1284951),
[PNA temporary-guide validity report, September 28, 2026](https://www.pna.gov.ph/articles/1285005),
[PTV fare guide report](https://ptvnews.ph/ltfrb-releases-new-matrices-for-puv-fare-hikes-effective-sept-28/),
[PIA, October 1, 2026](https://pia.gov.ph/news/luzon/car/fare-hike-eases-puj-drivers-rising-operating-costs-in-benguet/).

## ODbL responsibilities

OpenStreetMap data is © OpenStreetMap contributors and is available under the
Open Data Commons Open Database License (ODbL). Any distribution of
OSM-derived database contents or a derivative database must preserve the
license obligations, attribution, and share-alike requirements that apply to
the distributed result. Keep the OSM attribution visible in product and
documentation surfaces, and review the current ODbL terms before publishing a
larger extract.

Do not copy proprietary operator, mapping, or timetable datasets unless their
terms permit redistribution in an AGPL project. Record the license and
attribution for every imported source. A source without a URL can still be
recorded, but the notes must explain how it was checked and who checked it.

## Contributor research checklist

1. Prefer primary official, open, or directly observed evidence.
2. Record the exact object, document, notice, or observation and checked date.
3. Separate geographic existence from service operation and route association.
4. Use UNVERIFIED when the evidence supports a lead but not a trusted claim.
5. Never infer a jeepney path from a map line or name alone.
6. Check license, attribution, and redistribution terms before committing data.
7. Validate, dry-run, inspect the map, and run Doctor before import.
8. Use `php artisan biyahe:transfers:candidates` to find bounded review leads;
   never promote its output automatically.
