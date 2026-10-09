# Cebu offline graph coverage diagnostics

Mode: `CARRIED_FORWARD_FROM_PINNED_EVIDENCE`. This report summarizes the hash-pinned Batch032 report; it does not reopen or recompute the SQLite graph.

- Graph: `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`
- PBF: `787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d`
- Stops: 436/503 snappable at the pinned 25 m policy; 67 unsnappable.
- Places: 30/37 snappable; 7 unsnappable.
- Transfer records: 2 graph-connected; 2 not verified for physical interchange.
- Stored route corpus: 9 found, 1 unavailable, 1 explicitly component-disconnected.
- Restriction count: the pinned coverage report stores 619; the Batch039 narrative reports 648. Reconciliation was not performed.

Supplemental graph-level inventory from the Batch030 baseline, bound to the same graph SHA-256: 76,685 nodes, 78,225 edges, 2,754 total components, and 432 walkable components. These counts were carried forward for context and not recomputed by this summary.

## Coverage and confidence

Snapping and component membership describe graph coverage only. Trust/source counts are preserved separately. Administrative city-ID groupings do not establish geographic coverage or field access.

## Administrative city-ID grouping (not geographic proof)

The pinned source report groups records by `city_id`; it does not provide evidence that the ID corresponds to current boundaries or pedestrian access.

| Record type | City ID | Snappable | Total | Fraction |
|---|---:|---:|---:|---:|
| Stops | 6 | 436 | 503 | 86.7% |
| Places | 6 | 30 | 37 | 81.1% |

Snapped stop records by stored walkable component:

- Component 280324085: 433 snapped stop record(s); 433 marked as in the largest component.
- Component 6822000500: 1 snapped stop record(s); 0 marked as in the largest component.
- Component 8315066871: 1 snapped stop record(s); 0 marked as in the largest component.
- Component 8317880605: 1 snapped stop record(s); 0 marked as in the largest component.

## Graph quality evidence discrepancies

| Summary value | Detailed evidence value |
|---|---:|
| `long_edges_over_250m`: 0 | `long_edges_over_250m`: 26 |
| `boundary_nodes_within_0_0005_degrees`: 0 | `boundary_nodes`: 944 |
| `crossing_tagged_nodes`: 0 | `crossing_tagged_nodes`: 1510 |

The discrepancy is surfaced without editing the historical input. Detailed `quality_evidence.counts` is retained as the carried-forward evidence source.

## Unsnapppable stop records

- ID 262: `snap_unavailable`, trust `DRAFT_DEMO`
- ID 263: `snap_unavailable`, trust `DRAFT_DEMO`
- ID 282: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 283: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 284: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 295: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 297: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 300: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 307: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 313: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 320: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 330: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 341: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 346: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 351: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 355: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 362: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 367: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 382: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 387: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 389: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 390: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 403: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 414: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 446: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 464: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 467: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 470: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 471: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 483: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 490: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 497: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 500: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 504: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 510: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 529: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 531: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 532: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 534: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 539: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 542: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 561: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 565: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 571: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 575: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 580: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 607: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 610: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 626: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 643: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 646: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 648: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 654: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 664: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 669: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 674: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 681: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 685: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 687: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 704: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 705: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 706: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 707: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 735: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 760: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 761: `snap_unavailable`, trust `UNVERIFIED_RECORD`
- ID 763: `snap_unavailable`, trust `UNVERIFIED_RECORD`

## Unsnapppable place records

- ID 128: `snap_unavailable`, trust `DRAFT_DEMO`
- ID 129: `snap_unavailable`, trust `VERIFIED_RECORD`
- ID 142: `snap_unavailable`, trust `VERIFIED_RECORD`
- ID 144: `snap_unavailable`, trust `VERIFIED_RECORD`
- ID 148: `snap_unavailable`, trust `VERIFIED_RECORD`
- ID 149: `snap_unavailable`, trust `VERIFIED_RECORD`
- ID 154: `snap_unavailable`, trust `VERIFIED_RECORD`

## Disconnected stored corpus cases

- Case 6 `disconnected_component_journey`: ['place', 136] → ['place', 140]

## Limits

This stored evidence contains no field-safety verification and no fare verification. A graph path does not prove sidewalk continuity, crossing safety, legal access, current conditions, or a safe transfer. The ten stored Cebu cases are carried forward, not replayed by this command.

The stored coordinate fields contain usable latitude/longitude values for 503/503 stops and 37/37 places. This validates coordinate syntax and range only; it does not verify those locations in the field.
