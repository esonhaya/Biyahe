"use client";

import type { Journey, JourneyResponse } from "@/lib/api";
import CommuteCard from "@/components/CommuteCard";
import { replanStatusLabel } from "@/lib/pulse";
import styles from "@/app/page.module.css";

type ReplanPanelProps = {
  currentJourney: Journey;
  result: JourneyResponse | null;
  loading: boolean;
  error: string | null;
  onRequest: () => void;
  onChoose: (journey: Journey) => void;
};

function statusMessage(status: JourneyResponse["meta"]["replan_status"]): string {
  switch (status) {
    case "ALTERNATIVES_FOUND": return `${replanStatusLabel(status)} These alternatives use the current planner and active operational overlays.`;
    case "ALL_KNOWN_ALTERNATIVES_AFFECTED": return `${replanStatusLabel(status)} Choose only if you accept the warning.`;
    case "TEMPORARILY_UNAVAILABLE": return `${replanStatusLabel(status)} The current planner could not find a usable service under the active closure.`;
    default: return `${replanStatusLabel(status)} in the supported network.`;
  }
}

export default function ReplanPanel({ currentJourney, result, loading, error, onRequest, onChoose }: ReplanPanelProps) {
  const alternatives = (result?.data ?? []).filter((journey) => journey.id !== currentJourney.id);
  return (
    <section className={styles.replanPanel} aria-labelledby="replan-heading">
      <div className={styles.sectionHeading}><div><p className={styles.eyebrow}>Explicit choice</p><h3 id="replan-heading">Find another way</h3></div></div>
      <p className={styles.sectionCopy}>Biyahe will search the same origin and destination with current reviewed advisories. Your journey will not change until you choose an alternative.</p>
      <button type="button" className={styles.primaryButton} onClick={onRequest} disabled={loading}>{loading ? "Looking for alternatives…" : "Find another way"}</button>
      {error && <p className={styles.error} role="alert">{error}</p>}
      {result && <p className={styles.status} role="status">{statusMessage(result.meta.replan_status)} </p>}
      {alternatives.length > 0 && (
        <div className={styles.replanGrid}>
          {alternatives.map((journey) => <div className={styles.replanOption} key={journey.id}>
            <CommuteCard journey={journey} variant="compact" />
            <button type="button" className={styles.secondaryButton} onClick={() => onChoose(journey)}>Use this journey</button>
          </div>)}
        </div>
      )}
    </section>
  );
}
