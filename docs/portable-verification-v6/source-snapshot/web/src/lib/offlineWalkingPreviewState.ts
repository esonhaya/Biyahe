import type { Journey } from "./api";

export type OfflineWalkingPreviewStatus =
  | "DISABLED"
  | "IDLE"
  | "LOADING"
  | "AVAILABLE"
  | "PARTIAL"
  | "UNAVAILABLE"
  | "STALE"
  | "ERROR";

export type OfflineWalkingPreviewState = {
  status: OfflineWalkingPreviewStatus;
  identity: string | null;
  message: string | null;
  responseStatus?: NonNullable<Journey["offline_walking_preview"]>["status"];
};

export type OfflineWalkingPreviewEvent =
  | { type: "SELECTED_JOURNEY"; identity: string; enabled: boolean }
  | { type: "REQUEST_STARTED"; identity: string }
  | { type: "REQUEST_CANCELLED"; identity: string }
  | { type: "RESPONSE_RECEIVED"; identity: string; responseIdentity: string; sourceMatches: boolean; status: NonNullable<Journey["offline_walking_preview"]>["status"] }
  | { type: "REQUEST_FAILED"; identity: string; message: string }
  | { type: "STALE_RESPONSE_DISCARDED"; requestIdentity: string; selectedIdentity: string }
  | { type: "PREVIEW_REMOVED"; identity: string };

export function createOfflineWalkingPreviewState(identity: string, enabled: boolean): OfflineWalkingPreviewState {
  return { status: enabled ? "IDLE" : "DISABLED", identity: enabled ? identity : null, message: null };
}

export function offlineWalkingPreviewRequestIsCurrent(
  requestIdentity: string,
  selectedIdentity: string,
  enabled: boolean,
  signal?: AbortSignal,
): boolean {
  return enabled && !signal?.aborted && requestIdentity === selectedIdentity;
}

export function transitionOfflineWalkingPreview(
  state: OfflineWalkingPreviewState,
  event: OfflineWalkingPreviewEvent,
): OfflineWalkingPreviewState {
  switch (event.type) {
    case "SELECTED_JOURNEY": {
      if (!event.enabled) return { status: "DISABLED", identity: null, message: null };
      if (state.identity === event.identity && state.status !== "DISABLED") return state;
      if (state.status === "LOADING") {
        return {
          status: "STALE",
          identity: event.identity,
          message: "An earlier walking request was discarded because the selected journey changed.",
        };
      }
      return { status: "IDLE", identity: event.identity, message: null };
    }
    case "REQUEST_STARTED":
      if (state.status === "DISABLED" || state.identity !== event.identity) return state;
      return { status: "LOADING", identity: event.identity, message: null };
    case "REQUEST_CANCELLED":
      if (state.status !== "LOADING" || state.identity !== event.identity) return state;
      return {
        status: "STALE",
        identity: event.identity,
        message: "The local walking preview was cancelled. You can retry it.",
      };
    case "RESPONSE_RECEIVED":
      if (state.status !== "LOADING" || state.identity !== event.identity) return state;
      if (!event.sourceMatches || event.responseIdentity !== event.identity) {
        return {
          status: "ERROR",
          identity: event.identity,
          message: "The local walking response did not match the selected journey, so it was discarded.",
        };
      }
      if (event.status === "PARTIAL") {
        return {
          status: "PARTIAL",
          identity: event.identity,
          responseStatus: event.status,
          message: "Some walking legs were routed. Unavailable legs remain without a route line.",
        };
      }
      if (event.status === "UNAVAILABLE") {
        return {
          status: "UNAVAILABLE",
          identity: event.identity,
          responseStatus: event.status,
          message: "The local graph could not route these walking legs. Their reason codes are shown in the leg details.",
        };
      }
      return {
        status: "AVAILABLE",
        identity: event.identity,
        responseStatus: event.status,
        message: event.status === "NO_WALKING_LEGS"
          ? "This journey has no separate walking legs to preview."
          : "The local walking preview is ready. Field access remains unverified.",
      };
    case "REQUEST_FAILED":
      if (state.status !== "LOADING" || state.identity !== event.identity) return state;
      return {
        status: "ERROR",
        identity: event.identity,
        message: event.message.trim() || "The local graph preview could not be completed.",
      };
    case "STALE_RESPONSE_DISCARDED":
      if (state.status === "DISABLED" || state.identity !== event.selectedIdentity
        || event.requestIdentity === event.selectedIdentity) return state;
      return {
        status: "STALE",
        identity: event.selectedIdentity,
        message: "An earlier walking response was discarded because the selected journey changed.",
      };
    case "PREVIEW_REMOVED":
      if (state.status === "DISABLED" || state.identity !== event.identity) return state;
      return { status: "IDLE", identity: event.identity, message: null };
  }
}
