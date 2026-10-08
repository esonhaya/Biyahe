"use client";

import { useMemo, useState } from "react";
import type { Journey } from "@/lib/api";
import DisruptionNotice from "@/components/DisruptionNotice";
import {
  buildShareText,
  generateCommuteInstructions,
  type CommuteInstruction,
  type CommuteInstructionType,
} from "@/lib/commuteInstructions";
import styles from "@/app/page.module.css";

type CommuteCardProps = {
  journey: Journey;
  variant?: "compact" | "detail" | "saved";
  recommended?: boolean;
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

function trustLabel(state: Journey["trust"]["state"]): string {
  switch (state) {
    case "VERIFIED": return "Confirmed details";
    case "MIXED": return "Mapped, some details need checking";
    case "UNVERIFIED": return "Mapped, details not fully checked";
  }
}

function walkingLabel(meters: number): string {
  if (!Number.isFinite(meters) || meters <= 0) return "Walking distance unavailable";
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km walking` : `${Math.round(meters)} m walking`;
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
}: CommuteCardProps) {
  const model = useMemo(() => generateCommuteInstructions(journey), [journey]);
  const [shareStatus, setShareStatus] = useState<string | null>(null);
  const compact = variant === "compact";
  const savedDate = savedAt ? new Date(savedAt) : null;
  const rideInstructions = model.instructions.filter((instruction) => instruction.type === "RIDE");
  const routeCodes = Array.from(new Set(rideInstructions.map((instruction) => instruction.routeCode).filter((code): code is string => Boolean(code))));

  async function share() {
    const advisory = journey.disruptions?.find((impact) => impact.impact_level !== "INFORMATIONAL")?.warning;
    const text = [buildShareText(model), advisory ? `Important: ${advisory}` : null].filter(Boolean).join("\n");
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
      await copyText([buildShareText(model), advisory ? `Important: ${advisory}` : null].filter(Boolean).join("\n"));
      setShareStatus("Copied");
    } catch {
      setShareStatus("Copy is unavailable");
    }
  }

  return (
    <article className={`${styles.commuteCard} ${compact ? styles.commuteCardCompact : ""}`} aria-label={`${model.originName} to ${model.destinationName} journey`}>
      <div className={styles.journeyCardHeader}>
        <div>
          <p className={styles.cardKicker}>{recommended ? "RECOMMENDED" : variant === "saved" ? "SAVED SNAPSHOT" : "COMMUTE CARD"}</p>
          <h3>{model.title}</h3>
        </div>
        <span className={styles.trustBadge}>{trustLabel(journey.trust.state)}</span>
      </div>

      <div className={styles.commuteSummary} aria-label="Journey summary">
        <span>{model.summary.rides} ride{model.summary.rides === 1 ? "" : "s"}</span>
        <span>{model.summary.transfers} transfer{model.summary.transfers === 1 ? "" : "s"}</span>
        <span>{walkingLabel(model.summary.walkingDistanceMeters)}</span>
        <span>{model.summary.fare}</span>
      </div>
      {routeCodes.length > 0 && <div className={styles.routePills} aria-label="Routes used"><strong>Ride:</strong>{routeCodes.map((code) => <span key={code}>{code}</span>)}</div>}
      {journey.ranking.reasons.length > 0 && <p className={styles.rankingReasons}><strong>Why this option:</strong> {journey.ranking.reasons.slice(0, 2).join(" · ")}</p>}
      {model.summary.walkingDistanceHasFallback && <p className={styles.warning}>Walking includes an estimate; Biyahe is not claiming a mapped street path for every walking leg.</p>}
      {offlineSnapshot && (
        <p className={styles.snapshotNote}>
          Offline saved snapshot{savedDate && Number.isFinite(savedDate.getTime()) ? ` · saved ${savedDate.toLocaleDateString()}` : ""}. It may be out of date.
        </p>
      )}
      {(journey.disruptions ?? []).filter((impact) => impact.affected_leg_index === null || impact.affected_leg_index === undefined).map((impact) => (
        <DisruptionNotice key={impact.advisory.stable_id} impact={impact} staleSnapshot={offlineSnapshot} />
      ))}

      <ol className={styles.instructionList}>
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
      </ol>

      {model.warnings.map((warning) => <p className={styles.warning} key={warning}>{warning}</p>)}
      <p className={styles.commuteTrustNote}>{model.summary.trust}</p>

      <div className={styles.commuteActions}>
        {onSelect && <button type="button" onClick={onSelect} className={styles.secondaryButton}>{compact ? "Open this journey" : "Show on map"}</button>}
        {onOpen && <button type="button" onClick={onOpen} className={styles.primaryButton}>Open saved journey</button>}
        {onSave && !isSaved && <button type="button" onClick={onSave} className={styles.secondaryButton}>Save journey</button>}
        {onRefresh && <button type="button" onClick={onRefresh} className={styles.secondaryButton}>Refresh journey online</button>}
        {onReplan && <button type="button" onClick={onReplan} className={styles.secondaryButton}>Find another way</button>}
        {onRemove && <button type="button" onClick={onRemove} className={styles.secondaryButton}>Remove saved</button>}
        <button type="button" onClick={share} className={styles.secondaryButton}>Share</button>
        <button type="button" onClick={copy} className={styles.secondaryButton}>Copy</button>
      </div>
      {isSaved && !onRemove && <p className={styles.savedState}>Saved on this device.</p>}
      {shareStatus && <p className={styles.status} role="status">{shareStatus}</p>}
    </article>
  );
}

export { copyText };
