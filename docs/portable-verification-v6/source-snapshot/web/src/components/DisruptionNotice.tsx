"use client";

import type { DisruptionImpactLevel, DisruptionSummary, JourneyDisruptionImpact } from "@/lib/api";
import { advisorySourceLabel, freshnessLabel, humanDisruptionCategory, humanImpact, severityLabel } from "@/lib/disruptions";
import styles from "@/app/page.module.css";

type NoticeProps = {
  impact?: JourneyDisruptionImpact;
  advisory?: DisruptionSummary;
  staleSnapshot?: boolean;
  onSelect?: (advisory: DisruptionSummary) => void;
};

function tone(level: DisruptionImpactLevel | undefined): string {
  return level === "SERVICE_UNAVAILABLE" || level === "AFFECTED" ? styles.disruptionMajor : level === "POSSIBLY_AFFECTED" ? styles.disruptionUncertain : styles.disruptionInfo;
}

export default function DisruptionNotice({ impact, advisory, staleSnapshot = false, onSelect }: NoticeProps) {
  const current = impact?.advisory ?? advisory;
  if (!current) return null;
  const level = impact?.impact_level ?? "INFORMATIONAL";
  return (
    <aside className={`${styles.disruptionNotice} ${tone(level)}`} role="status">
      <div className={styles.disruptionNoticeHeader}>
        <span className={styles.disruptionIcon} aria-hidden="true">!</span>
        <div>
          <strong>{humanImpact(level)} · {severityLabel(current.severity)}</strong>
          <p>{current.headline}</p>
        </div>
      </div>
      <p>{impact?.warning ?? current.description}</p>
      <small>{humanDisruptionCategory(current.category)} · {advisorySourceLabel(current)} · {staleSnapshot ? "Saved snapshot; current status is unknown offline." : freshnessLabel(current)}</small>
      {current.ends_at && <small>Expected until {new Date(current.ends_at).toLocaleString()}</small>}
      {onSelect && <button type="button" className={styles.textButton} onClick={() => onSelect(current)}>Show advisory on map</button>}
    </aside>
  );
}
