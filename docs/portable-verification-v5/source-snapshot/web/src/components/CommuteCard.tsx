"use client";

import { useEffect, useMemo, useReducer, useRef, useState } from "react";
import { canUseOfflineWalkingPreview, previewJourneyWalkingOffline, type Journey } from "@/lib/api";
import DisruptionNotice from "@/components/DisruptionNotice";
import {
  buildShareText,
  generateCommuteInstructions,
  walkingEvidenceLabels,
  type CommuteInstruction,
  type CommuteInstructionType,
} from "@/lib/commuteInstructions";
import { explainJourney } from "@/lib/journeyExplanation";
import {
  createOfflineWalkingPreviewState,
  offlineWalkingPreviewRequestIsCurrent,
  transitionOfflineWalkingPreview,
} from "@/lib/offlineWalkingPreviewState";
import { offlineWalkingRequestIdentity } from "@/lib/rideMode";
import { savedSnapshotAgeLabel } from "@/lib/savedJourneys";
import styles from "@/app/page.module.css";

type CommuteCardProps = {
  journey: Journey;
  variant?: "compact" | "detail" | "saved";
  recommended?: boolean;
  selected?: boolean;
  offlineSnapshot?: boolean;
  savedAt?: string | null;
  isSaved?: boolean;
  onSelect?: () => void;
  onInstructionFocus?: (instruction: CommuteInstruction) => void;
  onSave?: () => void;
  onRemove?: () => void;
  onOpen?: () => void;
  onRefresh?: () => void;
  onReplan?: () => void;
  onOfflinePreview?: (journey: Journey, requestIdentity: string) => void;
  onClearOfflinePreview?: (requestIdentity: string) => void;
};

function typeLabel(type: CommuteInstructionType): string {
  switch (type) {
    case "ACCESS_WALK": return "WALK TO BOARD";
    case "BOARD": return "BOARD";
    case "RIDE": return "RIDE";
    case "ALIGHT": return "GET OFF";
    case "TRANSFER_WALK": return "TRANSFER WALK";
    case "TRANSFER_BOARD": return "BOARD NEXT RIDE";
    case "FINAL_WALK": return "FINAL WALK";
    case "ARRIVE": return "ARRIVE";
  }
}

function trustLabel(state: Journey["trust"]["state"], localWalkingPreview = false): string {
  if (localWalkingPreview && state === "VERIFIED") return "Mapped transit details · walking not field checked";
  switch (state) {
    case "VERIFIED": return "Confirmed details";
    case "MIXED": return "Mapped, some details need checking";
    case "UNVERIFIED": return "Mapped, details not fully checked";
  }
}

async function copyText(value: string): Promise<void> {
  if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
    return;
  }
  if (typeof document === "undefined") throw new Error("Clipboard is unavailable.");
  const textarea = document.createElement("textarea");
  textarea.value = value;
  textarea.setAttribute("readonly", "true");
  textarea.style.position = "fixed";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.select();
  const copied = document.execCommand("copy");
  textarea.remove();
  if (!copied) throw new Error("Clipboard is unavailable.");
}

export default function CommuteCard({
  journey,
  variant = "detail",
  recommended = false,
  selected = false,
  offlineSnapshot = false,
  savedAt = null,
  isSaved = false,
  onSelect,
  onInstructionFocus,
  onSave,
  onRemove,
  onOpen,
  onRefresh,
  onReplan,
  onOfflinePreview,
  onClearOfflinePreview,
}: CommuteCardProps) {
  const model = useMemo(() => generateCommuteInstructions(journey), [journey]);
  const explanation = useMemo(() => explainJourney(journey), [journey]);
  const [shareStatus, setShareStatus] = useState<string | null>(null);
  const offlinePreviewRequest = useRef<AbortController | null>(null);
  const requestIdentity = useMemo(() => offlineWalkingRequestIdentity(journey), [journey]);
  const currentRequestIdentity = useRef(requestIdentity);
  currentRequestIdentity.current = requestIdentity;
  const compact = variant === "compact";
  const savedAge = savedAt ? savedSnapshotAgeLabel(savedAt) : null;
  const rideInstructions = model.instructions.filter((instruction) => instruction.type === "RIDE");
  const compactRideRows = rideInstructions.map((ride) => ({
    ride,
    board: model.instructions.find((instruction) => instruction.legIndex === ride.legIndex && (instruction.type === "BOARD" || instruction.type === "TRANSFER_BOARD")),
    alight: model.instructions.find((instruction) => instruction.legIndex === ride.legIndex && instruction.type === "ALIGHT"),
  }));
  const routeCodes = Array.from(new Set(rideInstructions.map((instruction) => instruction.routeCode).filter((code): code is string => Boolean(code))));
  const canPreviewOfflineWalking = canUseOfflineWalkingPreview()
    && variant === "detail" && !offlineSnapshot && onOfflinePreview !== undefined;
  const currentPreviewEnabled = useRef(canPreviewOfflineWalking);
  currentPreviewEnabled.current = canPreviewOfflineWalking;
  const [offlinePreviewState, dispatchOfflinePreview] = useReducer(
    transitionOfflineWalkingPreview,
    { identity: requestIdentity, enabled: canPreviewOfflineWalking },
    ({ identity, enabled }) => createOfflineWalkingPreviewState(identity, enabled),
  );

  useEffect(() => {
    dispatchOfflinePreview({ type: "SELECTED_JOURNEY", identity: requestIdentity, enabled: canPreviewOfflineWalking });
    return () => {
      offlinePreviewRequest.current?.abort();
      offlinePreviewRequest.current = null;
    };
  }, [requestIdentity, canPreviewOfflineWalking]);

  async function previewOfflineWalking() {
    if (!canPreviewOfflineWalking) return;
    offlinePreviewRequest.current?.abort();
    const request = new AbortController();
    offlinePreviewRequest.current = request;
    dispatchOfflinePreview({ type: "REQUEST_STARTED", identity: requestIdentity });
    try {
      const result = await previewJourneyWalkingOffline(journey, request.signal);
      if (request.signal.aborted) return;
      const selectedIdentity = currentRequestIdentity.current;
      if (!offlineWalkingPreviewRequestIsCurrent(requestIdentity, selectedIdentity, currentPreviewEnabled.current, request.signal)) {
        dispatchOfflinePreview({ type: "STALE_RESPONSE_DISCARDED", requestIdentity, selectedIdentity });
        return;
      }
      const responseIdentity = offlineWalkingRequestIdentity(result.journey);
      const responseSummary = result.journey.offline_walking_preview;
      const sourceMatches = responseIdentity === requestIdentity
        && responseSummary?.mode === "DEVELOPMENT_ONLY"
        && responseSummary.status === result.preview.status
        && responseSummary.source_sha256 === result.preview.source_sha256
        && responseSummary.source_snapshot === result.preview.source_snapshot
        && responseSummary.graph_sha256 === result.preview.graph_sha256
        && responseSummary.graph_fingerprint === result.preview.graph_fingerprint;
      dispatchOfflinePreview({
        type: "RESPONSE_RECEIVED",
        identity: requestIdentity,
        responseIdentity,
        status: result.preview.status,
        sourceMatches,
      });
      if (!sourceMatches) return;
      onOfflinePreview?.(result.journey, requestIdentity);
    } catch (previewError) {
      if (request.signal.aborted) return;
      const selectedIdentity = currentRequestIdentity.current;
      if (!offlineWalkingPreviewRequestIsCurrent(requestIdentity, selectedIdentity, currentPreviewEnabled.current, request.signal)) {
        dispatchOfflinePreview({ type: "STALE_RESPONSE_DISCARDED", requestIdentity, selectedIdentity });
        return;
      }
      dispatchOfflinePreview({
        type: "REQUEST_FAILED",
        identity: requestIdentity,
        message: previewError instanceof Error ? previewError.message : "The local graph preview could not be completed.",
      });
    } finally {
      if (offlinePreviewRequest.current === request) offlinePreviewRequest.current = null;
    }
  }

  function cancelOfflineWalkingPreview() {
    const request = offlinePreviewRequest.current;
    if (!request) return;
    request.abort();
    offlinePreviewRequest.current = null;
    dispatchOfflinePreview({ type: "REQUEST_CANCELLED", identity: requestIdentity });
  }

  function removeOfflineWalkingPreview() {
    onClearOfflinePreview?.(requestIdentity);
    dispatchOfflinePreview({ type: "PREVIEW_REMOVED", identity: requestIdentity });
  }

  async function share() {
    const advisory = journey.disruptions?.find((impact) => impact.impact_level !== "INFORMATIONAL")?.warning;
    const preview = journey.offline_walking_preview
      ? `Local development walking preview · OSM snapshot ${journey.offline_walking_preview.source_snapshot} · not field verified.`
      : null;
    const text = [buildShareText(model), preview, advisory ? `Important: ${advisory}` : null].filter(Boolean).join("\n");
    try {
      if (typeof navigator !== "undefined" && typeof navigator.share === "function") {
        await navigator.share({ title: `Biyahe: ${model.title}`, text });
        setShareStatus("Shared");
        return;
      }
      await copyText(text);
      setShareStatus("Copied");
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") return;
      setShareStatus("Sharing is unavailable");
    }
  }

  async function copy() {
    try {
      const advisory = journey.disruptions?.find((impact) => impact.impact_level !== "INFORMATIONAL")?.warning;
      const preview = journey.offline_walking_preview
        ? `Local development walking preview · OSM snapshot ${journey.offline_walking_preview.source_snapshot} · not field verified.`
        : null;
      await copyText([buildShareText(model), preview, advisory ? `Important: ${advisory}` : null].filter(Boolean).join("\n"));
      setShareStatus("Copied");
    } catch {
      setShareStatus("Copy is unavailable");
    }
  }

  return (
    <article className={`${styles.commuteCard} ${compact ? styles.commuteCardCompact : ""}`} aria-label={`${model.originName} to ${model.destinationName} journey`} aria-current={selected ? "true" : undefined}>
      <div className={styles.journeyCardHeader}>
        <div>
          <p className={styles.cardKicker}>{recommended ? "RECOMMENDED" : variant === "saved" ? "SAVED SNAPSHOT" : "COMMUTE CARD"}</p>
          <h3>{model.title}</h3>
        </div>
        <span className={styles.trustBadge}>{trustLabel(journey.trust.state, Boolean(journey.offline_walking_preview))}</span>
      </div>

      <div className={styles.commuteSummary} aria-label="Journey summary">
        <span>{model.summary.rides} ride{model.summary.rides === 1 ? "" : "s"}</span>
        <span>{model.summary.transfers} transfer{model.summary.transfers === 1 ? "" : "s"}</span>
        {walkingEvidenceLabels(model.summary.walkingEvidence).map((label, index) => <span key={`${index}-${label}`} data-walking-evidence>{label}</span>)}
        <span>{model.summary.fare}</span>
      </div>
      {!compact && routeCodes.length > 0 && <div className={styles.routePills} aria-label="Routes used"><strong>Ride:</strong>{routeCodes.map((code) => <span key={code}>{code}</span>)}</div>}
      {explanation.plannerReasons.length > 0 && <p className={styles.rankingReasons}><strong>Why this option:</strong> {explanation.plannerReasons.slice(0, 2).join(" · ")}</p>}
      <details className={styles.journeyEvidenceDetails}>
        <summary>Route evidence and limitations</summary>
        <p><strong>{explanation.structure}</strong></p>
        {explanation.evidence.length > 0 && <ul aria-label="Journey evidence">{explanation.evidence.map((item) => <li key={item}>{item}</li>)}</ul>}
        {explanation.limitations.length > 0 && <ul aria-label="Journey limitations">{explanation.limitations.slice(0, 6).map((item) => <li key={item}>{item}</li>)}</ul>}
      </details>
      {model.summary.walkingDistanceHasFallback && <p className={styles.warning}>Walking evidence varies by leg. Estimates, approximate access, and missing route lines are labeled separately.</p>}
      {compact && compactRideRows.length > 0 && <ol className={styles.compactRideList} aria-label="Ride and stop sequence">
        {compactRideRows.map(({ ride, board, alight }) => (
          <li key={ride.id}>
            <strong>{ride.routeCode ?? "Mapped route"}{ride.direction ? ` · toward ${ride.direction}` : " · direction unavailable"}</strong>
            <span><b>Board near:</b> {board?.locationName ?? "Location not recorded"}{board?.precision === "EXACT_BOARDING_POINT" ? " · mapped point; confirm local conditions" : " · exact point not verified"}</span>
            <span><b>Get off near:</b> {alight?.locationName ?? "Area not recorded"} · exact side not verified</span>
          </li>
        ))}
      </ol>}
      {compact && journey.transfer_count > 0 && <p className={styles.transferCaution}>Mapped transfer connections do not confirm a safe physical interchange.</p>}
      {offlineSnapshot && (
        <p className={styles.snapshotNote}>
          Offline saved snapshot{savedAge ? ` · ${savedAge}` : ""}. Route and service details may have changed.
        </p>
      )}
      {journey.offline_walking_preview && (
        <p className={styles.warning} role="note" aria-label="Development-only offline walking preview">
          Local graph preview · OSM snapshot {journey.offline_walking_preview.source_snapshot} · {journey.offline_walking_preview.graph_sha256.slice(0, 12)} · © OpenStreetMap contributors · not field-safety verification. This preview does not replace live directions.
        </p>
      )}
      {(journey.disruptions ?? []).filter((impact) => impact.affected_leg_index === null || impact.affected_leg_index === undefined).map((impact) => (
        <DisruptionNotice key={impact.advisory.stable_id} impact={impact} staleSnapshot={offlineSnapshot} />
      ))}

      {!compact && <ol className={styles.instructionList}>
        {model.instructions.map((instruction) => {
          const legDisruptions = (journey.disruptions ?? []).filter((impact) => {
            if (impact.affected_leg_index === instruction.legIndex) return true;
            return (impact.affected_legs ?? []).some((leg) => leg.index === instruction.legIndex);
          });
          const content = (
            <>
              <span className={styles.instructionMarker}>{instruction.type === "RIDE" ? instruction.routeCode ?? "RIDE" : instruction.type === "ARRIVE" ? "✓" : "•"}</span>
              <span className={styles.instructionBody}>
                {legDisruptions.map((impact) => <DisruptionNotice key={`${impact.advisory.stable_id}-${instruction.id}`} impact={impact} staleSnapshot={offlineSnapshot} />)}
                <span className={styles.instructionLabel}>{typeLabel(instruction.type)}</span>
                <strong>{instruction.primaryAction}</strong>
                {instruction.secondaryDetail && <span>{instruction.secondaryDetail}</span>}
                {instruction.driverInstruction && <span className={styles.driverPhrase}>{instruction.driverInstruction}</span>}
                {(instruction.uncertainty || instruction.warnings.length > 0) && (
                  <details className={styles.instructionDetails}>
                    <summary>Details that may affect this step</summary>
                    {instruction.uncertainty && <span>{instruction.uncertainty}</span>}
                    {instruction.warnings.map((warning) => <span key={warning}>{warning}</span>)}
                  </details>
                )}
              </span>
            </>
          );
          return (
            <li key={instruction.id} className={styles.instructionItem}>
              {onInstructionFocus ? (
                <button type="button" className={styles.instructionButton} onClick={() => onInstructionFocus(instruction)} aria-label={`Show ${typeLabel(instruction.type)} on map`}>
                  {content}
                </button>
              ) : <div className={styles.instructionButtonStatic}>{content}</div>}
            </li>
          );
        })}
      </ol>}

      {model.warnings.map((warning) => <p className={styles.warning} key={warning}>{warning}</p>)}
      <p className={styles.commuteTrustNote}>{model.summary.trust}</p>

      <div className={styles.commuteActions}>
        {onSelect && <button type="button" onClick={onSelect} className={styles.secondaryButton}>{compact ? "Open this journey" : "Show on map"}</button>}
        {onOpen && <button type="button" onClick={onOpen} className={styles.primaryButton}>Open saved journey</button>}
        {onSave && !isSaved && !journey.offline_walking_preview && <button type="button" onClick={onSave} className={styles.secondaryButton}>Save journey</button>}
        {canPreviewOfflineWalking && <button type="button" onClick={() => { void previewOfflineWalking(); }} className={styles.secondaryButton} disabled={offlinePreviewState.status === "LOADING"} aria-describedby="offline-walking-preview-note">{offlinePreviewState.status === "LOADING" ? "Checking local walking graph…" : journey.offline_walking_preview ? "Refresh local walking preview" : "Preview walking from local graph"}</button>}
        {canPreviewOfflineWalking && offlinePreviewState.status === "LOADING" && <button type="button" onClick={cancelOfflineWalkingPreview} className={styles.secondaryButton}>Cancel walking preview</button>}
        {canPreviewOfflineWalking && journey.offline_walking_preview && onClearOfflinePreview && <button type="button" onClick={removeOfflineWalkingPreview} className={styles.secondaryButton}>Remove local preview</button>}
        {onRefresh && <button type="button" onClick={onRefresh} className={styles.secondaryButton}>Refresh journey online</button>}
        {onReplan && <button type="button" onClick={onReplan} className={styles.secondaryButton}>Find another way</button>}
        {onRemove && <button type="button" onClick={onRemove} className={styles.secondaryButton}>Remove saved</button>}
        {!compact && <button type="button" onClick={share} className={styles.secondaryButton}>Share</button>}
        {!compact && <button type="button" onClick={copy} className={styles.secondaryButton}>Copy</button>}
      </div>
      {canPreviewOfflineWalking && <p id="offline-walking-preview-note" className={styles.status}>Development preview only. Uses the pinned 25 m graph snapshot; it does not change transit routing or ranking.</p>}
      {canPreviewOfflineWalking && offlinePreviewState.message && <p className={styles.status} role="status" data-preview-state={offlinePreviewState.status}>{offlinePreviewState.message}</p>}
      {isSaved && !onRemove && <p className={styles.savedState}>Saved on this device.</p>}
      {shareStatus && <p className={styles.status} role="status">{shareStatus}</p>}
    </article>
  );
}

export { copyText };
