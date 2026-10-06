"use client";

import * as maplibregl from "maplibre-gl";
import type { Map as MapInstance, MapLayerMouseEvent, StyleSpecification } from "maplibre-gl";
import { useEffect, useRef, useState } from "react";
import type { Journey, Place, RoutePattern, Stop } from "@/lib/api";
import type { RideModeMapOverlay } from "@/lib/rideMode";

type MapViewProps = {
  places: Place[];
  stops: Stop[];
  journey: Journey | null;
  selectedPattern: RoutePattern | null;
  onStopSelect?: (stop: Stop) => void;
  rideMode?: RideModeMapOverlay | null;
};

const cebuCenter: [number, number] = [123.8854, 10.3157];

const fallbackMapStyle: StyleSpecification = {
  version: 8,
  glyphs: "https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf",
  sources: {
    openStreetMap: {
      type: "raster",
      tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"],
      tileSize: 256,
      attribution: "© OpenStreetMap contributors",
    },
  },
  layers: [{ id: "openStreetMap", type: "raster", source: "openStreetMap" }],
};

const mapStyle: string | StyleSpecification = process.env.NEXT_PUBLIC_MAP_STYLE_URL || fallbackMapStyle;

function pointFeature(id: string | number, name: string, location: { lat: number; lng: number }, properties: Record<string, string | number>) {
  return {
    type: "Feature" as const,
    id,
    properties: { name, ...properties },
    geometry: { type: "Point" as const, coordinates: [location.lng, location.lat] },
  };
}

function journeyPointFeatures(journey: Journey) {
  const points = [
    journey.origin.location ? pointFeature(`origin-${journey.origin.id}`, journey.origin.name, journey.origin.location, { kind: "origin" }) : null,
    journey.destination.location ? pointFeature(`destination-${journey.destination.id}`, journey.destination.name, journey.destination.location, { kind: "destination" }) : null,
  ];

  journey.legs.forEach((leg, index) => {
    if (leg.type === "RIDE" && leg.boarding_stop?.location) {
      points.push(pointFeature(`boarding-${index}-${leg.boarding_stop.id}`, leg.boarding_stop.name, leg.boarding_stop.location, { kind: "boarding" }));
    }
    if (leg.type === "RIDE" && leg.alighting_stop?.location) {
      points.push(pointFeature(`alighting-${index}-${leg.alighting_stop.id}`, leg.alighting_stop.name, leg.alighting_stop.location, { kind: "alighting" }));
    }
    if (leg.type === "TRANSFER_WALK") {
      if (leg.origin.location) {
        points.push(pointFeature(`transfer-from-${index}-${leg.origin.id}`, leg.origin.name, leg.origin.location, { kind: "transfer" }));
      }
      if (leg.destination.location) {
        points.push(pointFeature(`transfer-to-${index}-${leg.destination.id}`, leg.destination.name, leg.destination.location, { kind: "transfer" }));
      }
    }
  });

  return points.filter((point): point is NonNullable<typeof point> => point !== null);
}

function journeyRouteFeatures(journey: Journey) {
  return journey.legs
    .map((leg, index) => leg.geometry ? {
      type: "Feature" as const,
      id: `leg-${index}`,
      properties: { kind: leg.type, route: leg.route?.code ?? "route" },
      geometry: leg.geometry,
    } : null)
    .filter((feature): feature is NonNullable<typeof feature> => feature !== null);
}

function geometryPositions(coordinates: unknown): [number, number][] {
  if (!Array.isArray(coordinates)) return [];
  if (coordinates.length >= 2 && typeof coordinates[0] === "number" && typeof coordinates[1] === "number") {
    return [[coordinates[0], coordinates[1]]];
  }
  return coordinates.flatMap(geometryPositions);
}

function journeyBounds(journey: Journey): maplibregl.LngLatBounds | null {
  const bounds = new maplibregl.LngLatBounds();
  const points = journeyPointFeatures(journey);
  points.forEach((point) => bounds.extend(point.geometry.coordinates as [number, number]));

  journeyRouteFeatures(journey).forEach((feature) => {
    geometryPositions(feature.geometry.coordinates).forEach((coordinate) => bounds.extend(coordinate));
  });

  return bounds.isEmpty() ? null : bounds;
}

function patternBounds(pattern: RoutePattern): maplibregl.LngLatBounds | null {
  const bounds = new maplibregl.LngLatBounds();
  pattern.geometry?.coordinates.flat().forEach((coordinate) => bounds.extend([coordinate[0], coordinate[1]]));
  pattern.stops?.forEach((patternStop) => {
    const location = patternStop.stop.location;
    if (location) {
      bounds.extend([location.lng, location.lat]);
    }
  });

  return bounds.isEmpty() ? null : bounds;
}

export default function MapView({ places, stops, journey, selectedPattern, onStopSelect, rideMode = null }: MapViewProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<MapInstance | null>(null);
  const stopLookupRef = useRef(new Map<number, Stop>());
  const rideFocusRef = useRef<string | null>(null);
  const [mapReady, setMapReady] = useState(false);

  useEffect(() => {
    if (!containerRef.current || mapRef.current) {
      return;
    }

    const map = new maplibregl.Map({
      container: containerRef.current,
      style: mapStyle,
      center: cebuCenter,
      zoom: 12.5,
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-right");
    mapRef.current = map;

    map.on("load", () => {
      map.addSource("places", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "place-points",
        type: "circle",
        source: "places",
        paint: { "circle-color": "#e07a5f", "circle-radius": 5, "circle-stroke-color": "#fff", "circle-stroke-width": 2 },
      });
      map.addLayer({
        id: "place-labels",
        type: "symbol",
        source: "places",
        layout: { "text-field": ["get", "name"], "text-size": 11, "text-offset": [0, 1.2], "text-anchor": "top" },
        paint: { "text-color": "#7a2e2e", "text-halo-color": "#fff", "text-halo-width": 1 },
      });
      map.addSource("stops", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "stop-points",
        type: "circle",
        source: "stops",
        paint: { "circle-color": "#176b87", "circle-radius": 4, "circle-stroke-color": "#fff", "circle-stroke-width": 1.5 },
      });
      map.addLayer({
        id: "stop-labels",
        type: "symbol",
        source: "stops",
        layout: { "text-field": ["get", "name"], "text-size": 11, "text-offset": [0, 1.2], "text-anchor": "top" },
        paint: { "text-color": "#102a43", "text-halo-color": "#fff", "text-halo-width": 1 },
      });
      map.addSource("pattern-stops", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "pattern-stop-sequence",
        type: "circle",
        source: "pattern-stops",
        paint: { "circle-color": "#f4a261", "circle-radius": 8, "circle-stroke-color": "#fff", "circle-stroke-width": 2 },
      });
      map.addLayer({
        id: "pattern-stop-sequence-labels",
        type: "symbol",
        source: "pattern-stops",
        layout: { "text-field": ["get", "sequence"], "text-size": 10, "text-offset": [0, 0.1], "text-anchor": "center" },
        paint: { "text-color": "#102a43", "text-halo-color": "#fff", "text-halo-width": 1 },
      });
      map.addSource("route-pattern", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "route-line",
        type: "line",
        source: "route-pattern",
        paint: { "line-color": "#e07a5f", "line-width": 4, "line-opacity": 0.9 },
      });
      map.addSource("journey-points", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "journey-points-layer",
        type: "circle",
        source: "journey-points",
        paint: {
          "circle-color": ["match", ["get", "kind"], "origin", "#153b45", "destination", "#d3624d", "boarding", "#f4a261", "alighting", "#e07a5f", "transfer", "#7b61a8", "#176b87"],
          "circle-radius": ["match", ["get", "kind"], "origin", 9, "destination", 9, 7],
          "circle-stroke-color": "#fff",
          "circle-stroke-width": 2,
        },
      });
      map.addLayer({
        id: "journey-point-labels",
        type: "symbol",
        source: "journey-points",
        layout: { "text-field": ["get", "name"], "text-size": 11, "text-offset": [0, 1.2], "text-anchor": "top" },
        paint: { "text-color": "#153b45", "text-halo-color": "#fff", "text-halo-width": 1 },
      });
      map.addSource("journey-routes", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "journey-transit-lines",
        type: "line",
        source: "journey-routes",
        filter: ["==", ["get", "kind"], "RIDE"],
        paint: { "line-color": "#d3624d", "line-width": 5, "line-opacity": 0.88 },
      });
      map.addLayer({
        id: "journey-walking-lines",
        type: "line",
        source: "journey-routes",
        filter: ["!=", ["get", "kind"], "RIDE"],
        paint: {
          "line-color": ["match", ["get", "kind"], "TRANSFER_WALK", "#7b61a8", "#087f8c"],
          "line-width": 4,
          "line-opacity": 0.92,
          "line-dasharray": [2, 1.5],
        },
      });
      map.addSource("ride-active-leg", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "ride-active-line",
        type: "line",
        source: "ride-active-leg",
        paint: { "line-color": "#153b45", "line-width": 8, "line-opacity": 0.72 },
      });
      map.addSource("ride-location", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "ride-location-point",
        type: "circle",
        source: "ride-location",
        paint: { "circle-color": "#176b87", "circle-radius": 8, "circle-stroke-color": "#fff", "circle-stroke-width": 3 },
      });
      map.addSource("ride-targets", { type: "geojson", data: { type: "FeatureCollection", features: [] } });
      map.addLayer({
        id: "ride-target-points",
        type: "circle",
        source: "ride-targets",
        paint: {
          "circle-color": ["match", ["get", "kind"], "boarding", "#f4a261", "#d3624d"],
          "circle-radius": 9,
          "circle-stroke-color": "#fff",
          "circle-stroke-width": 2,
        },
      });
      setMapReady(true);
    });

    map.on("click", "stop-points", (event: MapLayerMouseEvent) => {
      const feature = event.features?.[0];
      const stopId = Number(feature?.properties?.stopId);
      const stop = stopLookupRef.current.get(stopId);
      if (stop) {
        onStopSelect?.(stop);
      }
    });
    map.on("mouseenter", "stop-points", () => {
      map.getCanvas().style.cursor = "pointer";
    });
    map.on("mouseleave", "stop-points", () => {
      map.getCanvas().style.cursor = "";
    });

    return () => {
      map.remove();
      mapRef.current = null;
      setMapReady(false);
    };
  }, [onStopSelect]);

  useEffect(() => {
    const map = mapRef.current;
    if (!mapReady || !map || !map.isStyleLoaded()) {
      return;
    }

    const placeSource = map.getSource("places") as maplibregl.GeoJSONSource | undefined;
    const stopSource = map.getSource("stops") as maplibregl.GeoJSONSource | undefined;
    const patternStopSource = map.getSource("pattern-stops") as maplibregl.GeoJSONSource | undefined;
    const patternRouteSource = map.getSource("route-pattern") as maplibregl.GeoJSONSource | undefined;
    const journeyPointSource = map.getSource("journey-points") as maplibregl.GeoJSONSource | undefined;
    const journeyRouteSource = map.getSource("journey-routes") as maplibregl.GeoJSONSource | undefined;
    if (!placeSource || !stopSource || !patternStopSource || !patternRouteSource || !journeyPointSource || !journeyRouteSource) {
      return;
    }

    placeSource.setData({
      type: "FeatureCollection",
      features: places.filter((place) => place.location).map((place) => pointFeature(place.id, place.name, place.location!, { kind: "place" })),
    });
    stopLookupRef.current = new Map(stops.map((stop) => [stop.id, stop]));
    stopSource.setData({
      type: "FeatureCollection",
      features: stops.filter((stop) => stop.location).map((stop) => pointFeature(stop.id, stop.name, stop.location!, { kind: "stop", stopId: stop.id })),
    });
    patternStopSource.setData({
      type: "FeatureCollection",
      features: journey ? [] : (selectedPattern?.stops ?? []).filter((patternStop) => patternStop.stop.location).map((patternStop) => ({
        type: "Feature" as const,
        id: `${selectedPattern?.id}-${patternStop.sequence}`,
        properties: { sequence: patternStop.sequence, name: patternStop.stop.name },
        geometry: { type: "Point" as const, coordinates: [patternStop.stop.location!.lng, patternStop.stop.location!.lat] },
      })),
    });
    patternRouteSource.setData({
      type: "FeatureCollection",
      features: journey || !selectedPattern?.geometry ? [] : [{ type: "Feature" as const, properties: { name: selectedPattern.name }, geometry: selectedPattern.geometry }],
    });
    journeyPointSource.setData({
      type: "FeatureCollection",
      features: journey ? journeyPointFeatures(journey) : [],
    });
    journeyRouteSource.setData({
      type: "FeatureCollection",
      features: journey ? journeyRouteFeatures(journey) : [],
    });

    const bounds = journey ? journeyBounds(journey) : selectedPattern ? patternBounds(selectedPattern) : null;
    if (bounds) {
      map.fitBounds(bounds, { padding: 48, maxZoom: 14, duration: 0 });
    }
  }, [journey, mapReady, places, selectedPattern, stops]);

  useEffect(() => {
    const map = mapRef.current;
    if (!mapReady || !map || !map.isStyleLoaded()) {
      return;
    }
    const rideActiveSource = map.getSource("ride-active-leg") as maplibregl.GeoJSONSource | undefined;
    const rideLocationSource = map.getSource("ride-location") as maplibregl.GeoJSONSource | undefined;
    const rideTargetSource = map.getSource("ride-targets") as maplibregl.GeoJSONSource | undefined;
    if (!rideActiveSource || !rideLocationSource || !rideTargetSource) {
      return;
    }
    rideActiveSource.setData({
      type: "FeatureCollection",
      features: rideMode?.activeGeometry ? [{
        type: "Feature" as const,
        properties: { kind: "active" },
        geometry: rideMode.activeGeometry,
      }] : [],
    });
    rideLocationSource.setData({
      type: "FeatureCollection",
      features: rideMode?.currentLocation ? [pointFeature("ride-current", "Current device location", rideMode.currentLocation, { kind: "current" })] : [],
    });
    rideTargetSource.setData({
      type: "FeatureCollection",
      features: [
        rideMode?.boardingLocation ? pointFeature("ride-boarding", "Mapped boarding area", rideMode.boardingLocation, { kind: "boarding" }) : null,
        rideMode?.alightingLocation ? pointFeature("ride-alighting", "Mapped get-off area", rideMode.alightingLocation, { kind: "alighting" }) : null,
      ].filter((point): point is NonNullable<typeof point> => point !== null),
    });
  }, [mapReady, rideMode]);

  useEffect(() => {
    const map = mapRef.current;
    if (!mapReady || !map || !rideMode) {
      return;
    }

    const focusKey = `${rideMode.journeyId}:${rideMode.activeLegIndex}:${rideMode.recenterToken}`;
    if (rideFocusRef.current === focusKey) {
      return;
    }
    rideFocusRef.current = focusKey;
    const focus = rideMode.currentLocation ?? rideMode.alightingLocation ?? rideMode.boardingLocation;
    if (focus) {
      map.easeTo({ center: [focus.lng, focus.lat], zoom: Math.max(map.getZoom(), 14), duration: 300 });
    }
  }, [mapReady, rideMode]);

  return <div className="map-frame" ref={containerRef} aria-label="Biyahe journey review map" />;
}
