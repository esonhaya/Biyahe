import type { CanIRideResponse } from "./api";

export type CanIRideStatus = CanIRideResponse["data"]["status"];
export type CanIRideTone = "decisionYes" | "decisionAffected" | "decisionNo" | "decisionUncertain" | "decisionDirection" | "decisionUnknown";

export type CanIRidePresentation = {
  headline: string;
  tone: CanIRideTone;
};

/** Present the existing checker result without changing route associations or confidence. */
export function canIRidePresentation(status: CanIRideStatus, routeCode: string | null): CanIRidePresentation {
  switch (status) {
    case "YES":
      return { headline: `YES — ride ${routeCode ?? "this route"}`, tone: "decisionYes" };
    case "YES_BUT_AFFECTED":
      return { headline: "YES, BUT CHECK THE ADVISORY", tone: "decisionAffected" };
    case "TEMPORARILY_UNAVAILABLE":
      return { headline: "TEMPORARILY UNAVAILABLE", tone: "decisionNo" };
    case "UNCERTAIN_DUE_TO_REPORT":
      return { headline: "UNCERTAIN — REPORT UNDER REVIEW", tone: "decisionUncertain" };
    case "DIRECTION_DEPENDENT":
      return { headline: "NOT THIS DIRECTION", tone: "decisionDirection" };
    case "NO":
      return { headline: "NOT A KNOWN RIDE", tone: "decisionNo" };
    case "UNKNOWN":
      return { headline: "NOT ENOUGH INFORMATION YET", tone: "decisionUnknown" };
  }
}
