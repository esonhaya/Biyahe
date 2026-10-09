# Batch 037: offline commuter experience

Batch037 reuses the existing journey planner, `CommuteCard` instruction model,
saved journey snapshots, and MapLibre view. It does not add a second planner or
claim live PostgreSQL integration.

The planning form now presents **From** before **To**. Compact result cards
show ride and stop sequence, transfer count, fare availability, and separate
walking evidence labels. Selecting a result still opens the existing ordered
detail card, which retains the full boarding, ride, alighting, walking,
uncertainty, save, and share controls.

Walking summaries distinguish mapped graph distance, mapped distance with
approximate endpoint access, straight-line estimates, missing route distance,
and missing path geometry. Estimate-only walking geometry is suppressed from
the map. Missing transit or mapped walking lines receive an explicit map
disclosure. Map labels call out unverified boarding areas, get-off sides, and
transfer locations; a graph connection does not establish a safe physical
interchange.

The focused commute-card harness passed the direct, one-transfer, and
two-transfer instruction fixtures, walking evidence breakdown, estimate-only
geometry suppression, uncertainty labels, and saved-snapshot checks. It ran
before a final wording-only adjustment to missing-distance uncertainty; that
adjustment was not retested because SwapFree fell below the existing 128 MiB
floor. The real ten-case Cebu corpus remains the Batch032 source-bound baseline
(9 found, 1 disconnected); this batch did not rerun graph routing. No browser
rendering, typecheck, lint, PostgreSQL staging, field verification, or private
beta gate was completed.

The machine-readable status is in
[`docs/reports/batch037-doctor-v37.json`](reports/batch037-doctor-v37.json).
