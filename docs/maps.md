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
  ordered pattern stops.

The initial viewport is Cebu City. The Batch 002 review slice shows
OSM-derived place and stop geography, plus OSM-derived route-member geometry.
The route and pattern service associations are visibly marked UNVERIFIED;
they are not presented as trusted journey instructions. Demo overlays are
excluded unless a development request explicitly includes them.

The map is a data-review surface, not a journey planner. Reviewers should use
it to spot longitude/latitude reversals, geometry gaps, direction anomalies,
and stop-order mistakes. A future deployment can replace the style and tile
source without changing the transport model.
