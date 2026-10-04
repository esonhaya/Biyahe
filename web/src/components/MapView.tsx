"use client";

import * as maplibregl from "maplibre-gl";
import type { Map as MapInstance, MapLayerMouseEvent, StyleSpecification } from "maplibre-gl";
import { useEffect, useRef, useState } from "react";
import type { Place, RoutePattern, Stop } from "@/lib/api";

type MapViewProps = {
  places: Place[];
  stops: Stop[];
  selectedPattern: RoutePattern | null;
  onStopSelect?: (stop: Stop) => void;
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

function pointFeature(id: number, name: string, location: { lat: number; lng: number }) {
  return {
    type: "Feature" as const,
    id,
    properties: { name },
    geometry: { type: "Point" as const, coordinates: [location.lng, location.lat] },
  };
}

export default function MapView({ places, stops, selectedPattern, onStopSelect }: MapViewProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<MapInstance | null>(null);
  const stopLookupRef = useRef(new Map<number, Stop>());
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
        paint: { "circle-color": "#e07a5f", "circle-radius": 6, "circle-stroke-color": "#fff", "circle-stroke-width": 2 },
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
        paint: { "circle-color": "#176b87", "circle-radius": 5, "circle-stroke-color": "#fff", "circle-stroke-width": 1.5 },
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
    const routeSource = map.getSource("route-pattern") as maplibregl.GeoJSONSource | undefined;
    if (!placeSource || !stopSource || !patternStopSource || !routeSource) {
      return;
    }

    placeSource.setData({
      type: "FeatureCollection",
      features: places.filter((place) => place.location).map((place) => pointFeature(place.id, place.name, place.location!)),
    });
    stopLookupRef.current = new Map(stops.map((stop) => [stop.id, stop]));
    stopSource.setData({
      type: "FeatureCollection",
      features: stops.filter((stop) => stop.location).map((stop) => ({
        ...pointFeature(stop.id, stop.name, stop.location!),
        properties: { name: stop.name, stopId: stop.id },
      })),
    });
    patternStopSource.setData({
      type: "FeatureCollection",
      features: (selectedPattern?.stops ?? []).filter((patternStop) => patternStop.stop.location).map((patternStop) => ({
        type: "Feature",
        id: `${selectedPattern?.id}-${patternStop.sequence}`,
        properties: { sequence: patternStop.sequence, name: patternStop.stop.name },
        geometry: { type: "Point", coordinates: [patternStop.stop.location!.lng, patternStop.stop.location!.lat] },
      })),
    });
    routeSource.setData({
      type: "FeatureCollection",
      features: selectedPattern?.geometry
        ? [{ type: "Feature", properties: { name: selectedPattern.name }, geometry: selectedPattern.geometry }]
        : [],
    });
  }, [mapReady, places, stops, selectedPattern]);

  return <div className="map-frame" ref={containerRef} aria-label="Biyahe Cebu exploration map" />;
}
