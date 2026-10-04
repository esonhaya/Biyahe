# Domain model

- City is a service area and data scope. Cebu City is the first reviewed
  service area, while the schema supports additional cities.
- Place is a searchable destination or landmark with a category,
  description, point, active flag, verification state, and provenance.
- PlaceAlias stores a local or common name and resolves to its canonical
  place during place search.
- Stop is a physical boarding or alighting location, not a landmark. It can
  reference a nearby place and carries boarding-point notes.
- Operator is optional because informal services may have no known provider.
  VehicleType currently supports JEEPNEY and BUS and reserves extensible
  codes for later modes.
- Route is the public identity of a service, such as a route code.
- RoutePattern is one direction or variant of a route and owns its geometry
  and ordered stops. Opposite directions are separate patterns.
- RoutePatternStop orders shared stops and records boarding/alighting
  permissions, commuter instructions, and optional service metadata. Database
  uniqueness and Doctor checks protect sequence integrity.
- Fare is a dated, sourced foundation scoped to a route and vehicle type, with
  optional pattern, stop-pair, distance, and fare-class fields. No fare engine
  or authoritative estimate is implemented.
- ProvenanceSource records the practical source, publisher, optional URL,
  access/check date, effective dates, license, verification method, reviewer,
  and notes for important transport facts.
- Stable IDs on imported entities make reviewed dataset re-imports
  deterministic without requiring migration edits.

Therefore, Route is not RoutePattern, Stop, or Place. A route may have
several directional patterns; a pattern visits ordered stops; a place is a
destination that may be near a stop.

## Verification

Transport records use DRAFT, UNVERIFIED, VERIFIED, STALE, or RETIRED. Demo
records are separately flagged with is_demo; Doctor rejects demo data marked
VERIFIED. Public API defaults exclude demo and retired records, while route
service associations in the first Cebu network remain UNVERIFIED. Community
submissions and moderation are future work. Until then, an administrator must
review provenance before promoting a record.
