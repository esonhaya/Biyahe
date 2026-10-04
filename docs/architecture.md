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
not available; it is not a production spatial implementation.

Collection endpoints paginate and eager-load the relationships needed for
their response. Nearby-stop queries use ST_DWithin and ST_Distance on
PostGIS. There is no street network, road matcher, or journey planner in
this batch.

## Scaling points

Future batches can add cache policy for stable geography, bounded map
viewport endpoints, reviewed imports, and a routing graph after real
transport data and usage patterns exist.
