# Batch041 test and resource plan

This plan separates deterministic report checks from functional routing and device-sensitive suites. Resource floors are the existing 524,288 KiB `MemAvailable` and 131,072 KiB `SwapFree`, sampled three times five seconds apart. A floor miss blocks the guarded task; it does not become a test failure or a pass.

The four Python fixture groups use standard-library JSON/report checks. They do not start Laravel, open the SQLite graph, read the PBF, or contact PostgreSQL. The comparator fixture constructs synthetic report-shaped data from the pinned expected schema; it tests comparison rules only and is not a route replay.

The PHP adapter tests and frontend Node harnesses are moderate on this device and require the sustained resource preflight. Corpus replay is heavy because it opens the graph and initializes route indexes. Browser interaction checks are heavy and require an available browser. PostgreSQL staging and field verification are external and field-dependent gates, respectively.

Batch041 measured SwapFree at 137,020, 129,792, and 130,244 KiB, five seconds apart. Two readings were below the unchanged floor, so the PHP/Node harnesses and real corpus replay were not launched. No resource guard was lowered and no repeat was attempted. Exact commands and results are in [the machine-readable plan](testing/batch041-test-plan.json).

The pure route-geometry contract also has a dependency-free PHP smoke command: `php api/scripts/test_offline_route_geometry_contract.php`. Its seven valid/rejected fixtures passed without Laravel bootstrap or graph access. This does not replace the blocked router/preview PHPUnit integration suite.
