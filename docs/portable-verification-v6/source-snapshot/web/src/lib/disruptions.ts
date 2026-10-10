import type { DisruptionImpactLevel, DisruptionSeverity, DisruptionSummary, JourneyDisruptionImpact } from "./api";

export function humanDisruptionCategory(category: string): string {
  return category.replaceAll("_", " ").toLowerCase().replace(/(^| )\S/g, (letter) => letter.toUpperCase());
}

export function humanImpact(level: DisruptionImpactLevel): string {
  switch (level) {
    case "SERVICE_UNAVAILABLE": return "Temporarily unavailable";
    case "AFFECTED": return "May affect this journey";
    case "POSSIBLY_AFFECTED": return "Report still being checked";
    default: return "Information";
  }
}

export function severityLabel(severity: DisruptionSeverity): string {
  return severity === "CRITICAL" ? "Critical" : severity === "MAJOR" ? "Major" : severity === "MINOR" ? "Minor" : "Info";
}

export function freshnessLabel(advisory: DisruptionSummary, now = Date.now()): string {
  const observed = advisory.source?.observed_at ?? advisory.starts_at;
  if (!observed) return "Time not provided";
  const timestamp = Date.parse(observed);
  if (!Number.isFinite(timestamp)) return "Time not provided";
  const minutes = Math.max(0, Math.round((now - timestamp) / 60000));
  if (minutes < 1) return "Reported just now";
  if (minutes < 60) return `Reported ${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  return `Reported ${hours} hr${hours === 1 ? "" : "s"} ago`;
}

export function advisorySourceLabel(advisory: DisruptionSummary): string {
  if (advisory.source?.conflict_status === "OPEN") return "Conflicting sources under review";
  if (advisory.source_class === "PUBLIC_REPORT") return "Commuter report under review";
  if (advisory.source_class === "OFFICIAL") return "Official advisory";
  if (advisory.source_class === "OPERATOR") return "Operator advisory";
  return "Reviewed source";
}

export function impactForLeg(impacts: JourneyDisruptionImpact[] | undefined, legIndex: number): JourneyDisruptionImpact[] {
  return (impacts ?? []).filter((impact) => {
    if (impact.affected_leg_index === legIndex) return true;
    return (impact.affected_legs ?? []).some((leg) => leg.index === legIndex);
  });
}

export function shareableDisruptionNote(impacts: JourneyDisruptionImpact[] | undefined): string | null {
  const warning = (impacts ?? []).find((impact) => impact.impact_level !== "INFORMATIONAL");
  return warning?.warning ?? null;
}

export function matchesEntity(advisory: DisruptionSummary, routeId?: number, stopId?: number, placeId?: number): boolean {
  return Boolean(
    (routeId && advisory.affected_entities.routes.some((route) => route.id === routeId))
    || (stopId && advisory.affected_entities.stops.some((stop) => stop.id === stopId))
    || (placeId && advisory.affected_entities.places.some((place) => place.id === placeId)),
  );
}
