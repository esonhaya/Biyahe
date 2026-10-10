import type { Journey, JourneyResponse } from "./api";

export const PULSE_FILTERS = ["ALL", "ROUTES", "STOPS", "AREAS", "MAJOR"] as const;
export type PulseFilter = typeof PULSE_FILTERS[number];

export function journeyHasOperationalImpact(journey: Journey | null | undefined): boolean {
  return (journey?.disruptions ?? []).some((impact) => impact.impact_level !== "INFORMATIONAL");
}

export function replanStatusLabel(status: JourneyResponse["meta"]["replan_status"]): string {
  switch (status) {
    case "ALTERNATIVES_FOUND": return "Alternatives found";
    case "ALL_KNOWN_ALTERNATIVES_AFFECTED": return "All known alternatives still have warnings";
    case "TEMPORARILY_UNAVAILABLE": return "Known options are temporarily unavailable";
    default: return "No unaffected alternative found";
  }
}

export function pulseNetworkStatusIsUncertain(code: string): boolean {
  return code === "STATUS_UNCERTAIN" || code === "NO_KNOWN_ACTIVE_ADVISORIES";
}
