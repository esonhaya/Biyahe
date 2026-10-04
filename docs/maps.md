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
  points, and a selected route-pattern MultiLineString.

The initial viewport is Cebu City. The displayed geometry is artificial demo
geometry and does not claim a real jeepney or bus path. A future deployment
can replace the style and tile source without changing the transport model.
