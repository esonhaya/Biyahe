import type { Journey, JourneyLeg, JourneyPoint, LocationPoint } from "./api";

export type JourneyRouteFeature = {
  type: "Feature";
  id: string;
  properties: { kind: JourneyLeg["type"]; route: string };
  geometry: Exclude<JourneyLeg["geometry"], null>;
};

export type JourneyPointFeature = {
  type: "Feature";
  id: string;
  properties: {
    kind: "origin" | "destination" | "boarding" | "alighting" | "transfer";
    name: string;
    label: string;
  };
  geometry: { type: "Point"; coordinates: [number, number] };
};

const ROUTED_WALK_TYPES = new Set([
  "PEDESTRIAN_NETWORK",
  "PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS",
]);

export type JourneyGeometryDisclosure = {
  rideLegsWithoutGeometry: number;
  mappedWalkingLegsWithoutGeometry: number;
  estimateOnlyWalkingLegs: number;
};

export function journeyGeometryDisclosure(journey: Journey): JourneyGeometryDisclosure {
  return journey.legs.reduce<JourneyGeometryDisclosure>((summary, leg) => {
    if (leg.type === "RIDE") {
      if (!leg.geometry) summary.rideLegsWithoutGeometry += 1;
      return summary;
    }

    if (ROUTED_WALK_TYPES.has(leg.distance_type ?? "")) {
      if (!leg.geometry) summary.mappedWalkingLegsWithoutGeometry += 1;
    } else if (leg.distance_type !== "NOT_REQUIRED") {
      summary.estimateOnlyWalkingLegs += 1;
    }

    return summary;
  }, {
    rideLegsWithoutGeometry: 0,
    mappedWalkingLegsWithoutGeometry: 0,
    estimateOnlyWalkingLegs: 0,
  });
}

function pointFeature(
  id: string,
  point: JourneyPoint,
  kind: JourneyPointFeature["properties"]["kind"],
  label: string,
): JourneyPointFeature | null {
  const location: LocationPoint | null = point.location;
  if (!location) return null;

  return {
    type: "Feature",
    id,
    properties: { kind, name: point.name, label },
    geometry: { type: "Point", coordinates: [location.lng, location.lat] },
  };
}

/** Point labels preserve location uncertainty instead of implying surveyed pins. */
export function journeyPointFeatures(journey: Journey): JourneyPointFeature[] {
  const features: JourneyPointFeature[] = [];
  const origin = pointFeature(`origin-${journey.origin.id}`, journey.origin, "origin", journey.origin.name);
  const destination = pointFeature(`destination-${journey.destination.id}`, journey.destination, "destination", journey.destination.name);
  if (origin) features.push(origin);
  if (destination) features.push(destination);

  journey.legs.forEach((leg, index) => {
    if (leg.type === "RIDE") {
      const boarding = leg.boarding_stop;
      if (boarding) {
        const boardingLabel = boarding.boarding_precision === "EXACT_BOARDING_POINT"
          ? `Mapped boarding point · ${boarding.name} · confirm local conditions`
          : `Boarding area · exact point unverified · ${boarding.name}`;
        const feature = pointFeature(`boarding-${index}-${boarding.id}`, boarding, "boarding", boardingLabel);
        if (feature) features.push(feature);
      }

      const alighting = leg.alighting_stop;
      if (alighting) {
        const feature = pointFeature(
          `alighting-${index}-${alighting.id}`,
          alighting,
          "alighting",
          `Get-off area · exact side unverified · ${alighting.name}`,
        );
        if (feature) features.push(feature);
      }
    }

    if (leg.type === "TRANSFER_WALK") {
      for (const [side, point] of [["from", leg.origin], ["to", leg.destination]] as const) {
        const feature = pointFeature(
          `transfer-${side}-${index}-${point.id}`,
          point,
          "transfer",
          `Transfer · unverified · ${point.name}`,
        );
        if (feature) features.push(feature);
      }
    }
  });

  return features;
}

/** Only source geometry is drawn. Straight-line estimates are never route lines. */
export function journeyRouteFeatures(journey: Journey): JourneyRouteFeature[] {
  return journey.legs.flatMap((leg, index) => {
    if (!leg.geometry) return [];
    if (leg.type !== "RIDE" && !ROUTED_WALK_TYPES.has(leg.distance_type ?? "")) return [];

    return [{
      type: "Feature" as const,
      id: `leg-${index}`,
      properties: { kind: leg.type, route: leg.route?.code ?? "route" },
      geometry: leg.geometry,
    }];
  });
}
