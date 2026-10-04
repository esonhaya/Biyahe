# BIYAHE web

This is the mobile-first Next.js and MapLibre exploration interface. It reads
the Laravel API; it never connects directly to the database.

## Local setup

    cp .env.example .env.local
    npm install
    npm run dev

Set NEXT_PUBLIC_API_URL when the API is not at
http://127.0.0.1:8000/api/v1. Before a release, run:

    npm run lint
    npm run typecheck
    npm run build

The build uses Webpack because Next.js Turbopack native bindings are not
available on Termux Android/arm64. The map style is replaceable with
NEXT_PUBLIC_MAP_STYLE_URL in a later deployment; the development fallback
uses public OpenStreetMap raster tiles and must not be treated as a production
tile service.
