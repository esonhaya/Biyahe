# Maps

The web app uses MapLibre GL JS to render a replaceable style and overlay
Biyahe transport data.

- OpenStreetMap data is the collaborative geographic database. It is not the
  same as BIYAHE's places, stops, routes, verification states, or fares.
- Tiles are map imagery or vector data served by a tile provider. The
  development fallback uses public OpenStreetMap raster tiles and credits
  contributors. That service has usage policies, capacity limits, and no
  production availability guarantee; do not bulk-download or prefetch it.
- MapLibre is the open-source renderer that draws the configured style and
  BIYAHE overlays.
- BIYAHE transport data comes from the API and includes place points, stop
  points, selected route-pattern MultiLineStrings, direction labels, and
  ordered pattern stops. When an OSM pedestrian extract is active, computed
  access, egress, and transfer-walk LineStrings are separate dashed overlays;
  they are not merged with transit geometry.

The initial viewport is Cebu City. The Batch 003 review slice shows
OSM-derived place and stop geography, plus OSM-derived route-member geometry.
Route-pattern overlays are shown with their direction, ordered stops, and
trust state; unverified service associations are not presented as confirmed
journey instructions. Demo overlays are excluded unless a development request
explicitly includes them.

The map is both a data-review surface and a selected-journey surface.
Reviewers should use it to spot longitude/latitude reversals, geometry gaps,
direction anomalies, and stop-order mistakes. A selected journey displays
origin, destination, boarding, alighting, transfer, transit geometry, and any
computed pedestrian path. A straight-line estimate displays points and an
explicit estimate label only; it never implies a known pedestrian path.
Pedestrian geometry follows mapped OSM ways and does not guarantee sidewalks,
crossings, temporary access, or current ground conditions. A deployment can
replace the style, tile source, or local routing implementation without
changing the transport trust model.

The expansion overlay intentionally shows sampled OSM platform members as
reviewable stop points, not confirmed curbside boarding. Admins should compare
route direction, ordered sequence, boarding/alighting permissions, and source
freshness before promoting an association.
