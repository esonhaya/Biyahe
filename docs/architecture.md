# Architecture

BIYAHE is a monorepo with a Next.js App Router client, a Laravel REST API
under /api/v1, PostgreSQL/PostGIS storage, and an authenticated Filament
admin panel. The web client communicates with JSON endpoints and never
connects directly to the database.

The model keeps the public identity of a service (Route) separate from each
direction or variant (RoutePattern). Ordered RoutePatternStop records
describe the stops visited by a pattern. Place represents a destination or
landmark; Stop represents a boarding or alighting location.

Places and stops use PostGIS geography(Point,4326). Pattern paths use
geometry(MultiLineString,4326) with GiST indexes. The SQLite test fallback
stores WKT text and uses a bounded PHP distance fallback only when PostGIS is
not available; it is not a production spatial implementation. Transfer
relationships are explicit, bounded walking-transfer evidence rather than a
general road graph.

Collection endpoints paginate and eager-load the relationships needed for
their response. Nearby-stop queries use ST_DWithin and ST_Distance on
PostGIS. `JourneyPlanner` keeps spatial candidate discovery in PostGIS and
performs bounded direct, one-transfer, and two-transfer stop-sequence
reasoning in the application. Search state, transfer, pattern, and result
limits are configuration bounds; journeys are deduplicated by meaningful
boarding, ride, transfer, and alighting decisions. Journey results are
ephemeral and are not persisted.

The planner exposes separate access, ride, transfer-walk, and egress legs.
Walking is a bounded straight-line estimate, never a claimed pedestrian
route. Routing excludes demo, draft, stale, and retired service records and
derives a VERIFIED, MIXED, or UNVERIFIED result state from its underlying
facts.

## Scaling points

Future batches can add cache policy for stable geography, bounded map
viewport endpoints, schedules, fare products, better transfer evidence, and
a pedestrian-routing adapter after real transport data and usage patterns
exist. Unrestricted multi-transfer graph search, road navigation, and
turn-by-turn walking remain deliberately out of scope.
