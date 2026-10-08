"use client";

import type { DisruptionSummary, Journey, PulseResponse } from "@/lib/api";
import DisruptionNotice from "@/components/DisruptionNotice";
import styles from "@/app/page.module.css";

type PulseData = PulseResponse["data"];
type PulseFilter = "ALL" | "ROUTES" | "STOPS" | "AREAS" | "MAJOR";

type BiyahePulseProps = {
  pulse: PulseData | null;
  loading?: boolean;
  selectedJourney?: Journey | null;
  onRefresh: () => void;
  onFilter: (filter: PulseFilter) => void;
  onAdvisorySelect?: (advisory: DisruptionSummary) => void;
};

function journeyImpactCount(journey: Journey | null | undefined): number {
  return (journey?.disruptions ?? []).filter((impact) => impact.impact_level !== "INFORMATIONAL").length;
}

export default function BiyahePulse({ pulse, loading = false, selectedJourney = null, onRefresh, onFilter, onAdvisorySelect }: BiyahePulseProps) {
  const journeyImpacts = journeyImpactCount(selectedJourney);
  const status = pulse?.network_status;

  return (
    <section className={styles.pulseSection} id="pulse" aria-labelledby="pulse-heading">
      <div className={styles.sectionHeading}>
        <div><p className={styles.eyebrow}>Operational awareness</p><h2 id="pulse-heading">Biyahe Pulse</h2></div>
        <button type="button" className={styles.secondaryButton} onClick={onRefresh} disabled={loading}>{loading ? "Refreshing…" : "Refresh"}</button>
      </div>
      <p className={styles.sectionCopy}>A concise view of reviewed temporary advisories and clearly labelled commuter signals. No known advisory is not a guarantee that every service is operating normally.</p>
      {status && (
        <div className={styles.pulseStatus} role="status">
          <strong>{status.label}</strong>
          <span>{status.explanation}</span>
          {selectedJourney && <span>{journeyImpacts > 0 ? `${journeyImpacts} advisory item${journeyImpacts === 1 ? " affects" : "s affect"} your selected journey.` : "No active advisory is currently matched to your selected journey."}</span>}
        </div>
      )}
      {pulse && (
        <>
          <div className={styles.pulseSummary} aria-label="Pulse summary">
            <span>{pulse.summary.active_advisories} reviewed advisory{pulse.summary.active_advisories === 1 ? "" : "ies"}</span>
            <span>{pulse.summary.routes_affected} route{pulse.summary.routes_affected === 1 ? "" : "s"} affected</span>
            <span>{pulse.summary.stops_affected} stop{pulse.summary.stops_affected === 1 ? "" : "s"} affected</span>
            {pulse.summary.unresolved_public_signals > 0 && <span>{pulse.summary.unresolved_public_signals} community signal{pulse.summary.unresolved_public_signals === 1 ? "" : "s"}</span>}
          </div>
          <div className={styles.pulseFilters} role="group" aria-label="Filter Pulse items">
            {(["ALL", "ROUTES", "STOPS", "AREAS", "MAJOR"] as const).map((filter) => (
              <button type="button" key={filter} className={pulse.meta.filter === filter ? styles.pulseFilterActive : styles.secondaryButton} onClick={() => onFilter(filter)}>{filter === "ALL" ? "All" : filter[0] + filter.slice(1).toLowerCase()}</button>
            ))}
          </div>
          {pulse.items.length > 0 ? (
            <div className={styles.advisoryGrid}>
              {pulse.items.slice(0, 5).map((item) => <DisruptionNotice key={item.stable_id} advisory={item} onSelect={onAdvisorySelect} />)}
            </div>
          ) : <p className={styles.emptyState}>No reviewed advisory matches this Pulse filter.</p>}
          {pulse.community_signals.length > 0 && (
            <details className={styles.pulseSignals}>
              <summary>Community signals — still being checked</summary>
              <div className={styles.advisoryGrid}>
                {pulse.community_signals.slice(0, 4).map((signal) => <p key={`${signal.category}-${signal.observed_at}-${signal.message}`} className={styles.pulseSignal}>{signal.message} <small>{signal.freshness_label}{signal.conflicted ? " · reports disagree" : ""}</small></p>)}
              </div>
            </details>
          )}
        </>
      )}
    </section>
  );
}
