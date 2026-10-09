# Pedestrian turn-restriction policy

## Evidence and source interpretation

OSM uses generic `type=restriction` relations for vehicle turn restrictions.
The [OSM turn restriction relation documentation](https://wiki.openstreetmap.org/wiki/Turn_restriction_relation)
states that these do not apply to pedestrians unless the relation uses the
foot-specific restriction form. The policy therefore does not translate a
generic `no_left_turn` or `only_straight_on` rule into a walking prohibition.
`except=foot` is preserved as an explicit pedestrian exemption. A
`restriction:pedestrian` key is treated conservatively as pedestrian-applicable
even though the current Cebu source does not use it.

The Batch030 v3 graph stored 619 restriction relations under a provisional
filter. That filter did not retain relations that had only
`restriction:conditional` or `restriction:psv`. A single bounded Osmium
`tags-filter` pass over the hash-verified PBF found 648 relations with
restriction tags, all with `type=restriction`: 619 with a base `restriction`
tag, 33 with `restriction:conditional`, and one with `restriction:psv`. The 29
not stored in v3 are listed with their tags and IDs in
[`batch031-cebu-restriction-source-scan.json`](reports/batch031-cebu-restriction-source-scan.json).
None of the 648 has `restriction:foot`, `restriction:pedestrian`, or a
pedestrian-specific conditional key. No relation has `except=foot`.

The 619 stored relation tags and ordered members are classified in
[`batch031-cebu-restriction-audit.json`](reports/batch031-cebu-restriction-audit.json).
That report binds the source SHA-256 and snapshot to the SQLite graph-content
fingerprint and member fingerprint. The audit is a sidecar; the validated v3
SQLite file is not changed. The 29 omitted relations are all generic vehicle
conditional restrictions except one public-service-vehicle restriction. The
audit records them as non-pedestrian and does not infer any relation geometry
from the absence of stored members.
The via-node, via-way, and malformed-member form counts describe the 619 stored
relations only; the bounded source tag scan did not infer member forms for the
29 omitted relations.

The source index cannot resolve 75 stored via-node member references to node
objects in the PBF extract. All 75 belong to generic vehicle turn restrictions;
none belongs to a pedestrian-applicable or mode-ambiguous relation. The
machine-readable
[`graph quality and member review`](reports/batch031-cebu-graph-quality-and-member-review.json)
reports unresolved relation-member dependencies separately from malformed role
shapes, and does not infer whether the missing source objects reflect extract
clipping or stale OSM relation data.

## Applicability and failure behavior

`OfflinePedestrianRestrictionPolicy` classifies mode applicability separately
from member form. Member forms include via-node, via-way, mixed, nested, and
malformed. A generic vehicle-only restriction remains non-applicable to walking
even if its member roles are malformed; malformed relations are still reported
for source-quality review. Relation geometry is not used to invent graph edges.

The following conditions block routing import:

- `restriction:foot`, `restriction:pedestrian`, or their conditional forms.
- A restriction key for an unknown mode.
- A conflict between a pedestrian-specific restriction and `except=foot`.
- An incomplete source restriction inventory.

Conditional pedestrian rules are not evaluated by date or time. Via-node and
via-way turn transitions are not enforced by the node-based Dijkstra router.
The importer therefore rejects an artifact if any pedestrian-applicable or
ambiguous turn restriction remains. The Cebu source contains none, so the
current graph does not need an incoming-edge or way-history routing state.
This is a source-specific capability finding; it is not a claim that the router
supports relation-based turn restrictions generally.

For newly built artifacts, the source passes retain every relation with a
restriction key, and their checkpoint fingerprints include the inventory
policy version and completeness marker. Legacy v3 artifacts need a matching
audit sidecar. A sidecar with a mismatched source hash, snapshot, graph
fingerprint, stored relation fingerprint, or omitted relation ID fails closed.

## Import and activation boundary

`importOfflineSqlite()` runs the independent validator before opening a write
transaction. A passing graph is recorded as an inactive staging dataset with
restriction capability metadata; this batch did not stage it in PostgreSQL.
Existing matching datasets are reused only when their inactive staging and
capability metadata validate. The local router refuses an offline-staging
dataset whose restriction capability metadata is absent or unsafe.

The older JSON importer also inspects any restriction relations present in its
input and rejects pedestrian-applicable or mode-ambiguous ones. Generic vehicle
restrictions do not create walking restrictions. That import format does not
carry the complete-source audit contract used by the SQLite path, so it cannot
be used as evidence for Batch031's offline graph readiness.

## Topology and remaining uncertainty

The quality audit reads only the completed SQLite artifact. It reports weak
components, largest walkable component, isolated barrier nodes, crossing tags,
long edge records, nodes within 0.0005 degrees of a clip boundary, and source
references outside the clipped graph scope. It does not join geometries that
merely cross. A source way with out-of-scope references retains those
references in the source index while only in-scope segments become graph
edges.

The Batch031 read-only audit reproduced 432 walkable components, a largest
component of 70,401 nodes, 26 edges longer than 250 m (maximum 867 m), 944
nodes within 0.0005 degrees of the clip boundary, 1,510 crossing-tagged nodes,
2,322 isolated blocked barrier nodes, and no isolated walkable nodes. It also
finds 171 ways with both in-scope and out-of-scope source references and 1,740
referenced nodes outside the clip.
These edge lengths, boundary nodes, clipped references, and disconnected
fragments are diagnostics for map/source review. There is no source evidence to
add shortcuts or infer crossing safety, sidewalks, or field conditions.
Private-beta routing remains blocked until a separate staging, coverage, and
field-review process is completed.

The policy follows [OSM access restrictions](https://wiki.openstreetmap.org/wiki/Restrictions)
for explicit mode-specific access and exception tagging. Conditional syntax,
nested relation semantics, and relation transition enforcement remain
unsupported for pedestrian-applicable rules and are never silently treated as
unrestricted.
