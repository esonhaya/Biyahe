# Batch 043 external Linux staging runbook

This runbook describes a reproducible staging exercise on an already provisioned, isolated Linux host. It does not provision infrastructure, start PostgreSQL from this Termux device, contact production, enable the local walking endpoint, or claim that staging has run.

## Runtime baseline

Use a non-root deploy account on a supported Linux distribution. The repository documents PHP 8.3+, `pdo_pgsql`, Composer, Node.js 20.9+, and npm. CI currently exercises PHP 8.3, Node 20.9, and `postgis/postgis:16-3.4`; that is the tested CI baseline, not a claim that every newer PostgreSQL/PostGIS combination has been validated. Install the PHP extensions listed in `.github/workflows/ci.yml` and required by `api/composer.json`. Use a supported PostgreSQL release with PostGIS and verify its exact server versions in the staging record.

Keep secrets in the host's secret manager or protected environment. Required runtime configuration includes `APP_ENV=staging`, `APP_DEBUG=false`, an existing `APP_KEY`, `DB_CONNECTION=pgsql`, and the database host, port, name, and username. Never place credentials in the release manifest or command output. Keep `BIYAHE_OFFLINE_WALKING_PREVIEW=false` or unset.

On the external host, the passive check validates runtimes, checkout files, and required environment keys without printing values or making a database connection:

```sh
api/scripts/check-batch043-staging-preflight.sh --preflight
api/scripts/check-batch043-staging-preflight.sh --dry-run
```

The second command prints the proposed order only. Both modes leave services and files unchanged. The development walking route is registered only for Laravel's `local` environment with debug and the explicit feature flag enabled; staging must keep those conditions false. Verify that `POST /api/v1/dev/offline-walking-preview` is not registered or reachable in staging.

## Database and release sequence

1. Check out a pinned release commit and record `git rev-parse HEAD`, `git status --porcelain`, the PHP/Composer/Node/npm/PostgreSQL/PostGIS versions, and SHA256 values for the release archive and approved input datasets.
2. Provision a separate staging database through the organization's normal infrastructure process. Use a least-privilege application role, network allowlists, TLS where supported, and a staging-only secret set. Enable PostGIS in that database using the approved DBA process. Do not reuse production credentials or a production database.
3. Install locked application dependencies from the checked-in lockfiles: `composer install --no-interaction --no-progress --prefer-dist` in `api/`, then `npm ci` in `web/`. Do not run install hooks with production secrets available.
4. Before schema changes, take a PostgreSQL custom-format backup and verify that it is readable:

   ```sh
   pg_dump --format=custom --file="$BACKUP_DIR/biyahe-before-batch043.dump" --dbname="$STAGING_DATABASE"
   sha256sum "$BACKUP_DIR/biyahe-before-batch043.dump"
   pg_restore --list "$BACKUP_DIR/biyahe-before-batch043.dump" >/dev/null
   ```

   `BACKUP_DIR` and `STAGING_DATABASE` are supplied by the host operator and must not be printed in logs. Supply credentials through the host's protected `PGPASSFILE` or service configuration, never on the command line. Preserve the backup checksum with the release record.
5. Review migration status with `php artisan migrate:status` from `api/`. Apply only the reviewed forward migrations with `php artisan migrate --force --no-interaction`. Never use `migrate:fresh` against a persistent staging database. Record the migration output and resulting schema version.
6. For the reviewed transport data, run `php artisan biyahe:data:validate cebu-central-network-2026-10-05.json` and `php artisan biyahe:data:validate cebu-network-expansion-2026-10-05.json`. Run each `php artisan biyahe:data:import <dataset> --dry-run` and retain its validation result. Import only after the staging owner approves the dataset checksums and dry-run report. The importer is transactional, but the database backup remains the rollback boundary.
7. The offline walking graph is a separate evidence gate. Do not build it from a PBF in this deployment procedure. Do not stage or activate it unless an independently verified artifact matches the pinned graph identity, passes read-only integrity and routing-corpus validation, and the deployment owner approves an isolated staging import using the existing pedestrian staging commands. The current Batch 043 device did not verify the graph or execute the Cebu replay.

## Health, API, and frontend checks

After migrations and approved imports, check the Laravel liveness route `/up`, then `GET /api/v1/health` (database and PostGIS checks) and `GET /api/v1/ready` (deployment-readiness contract). Save response status and redacted JSON. A passing health route proves service connectivity only; it does not verify route coverage, fare correctness, walking access, transfer safety, or field conditions.

From `web/`, run the repository commands `npm run typecheck`, `npm run lint`, and `npm run build`. Then run the focused frontend harnesses documented by `web/package.json` and execute browser checks at 320, 360, and 390 CSS pixels. Verify search, selected-journey synchronization, unknown-fare and unknown-ETA disclosures, walking-preview state, map clearing when geometry is missing, Ride Mode manual controls, saved-journey reopening, keyboard focus, and touch targets. Record screenshots and browser/runtime versions only when those checks actually run.

Keep browser/API requests on the staging origin, use a non-production test account, and do not substitute a production API URL. Walking preview is a development-only local adapter; staging and production continue using the normal planner/API paths. Unknown fares, ETAs, transfer conditions, boarding access, and physical accessibility must remain explicitly unknown unless separately supported by evidence.

## Resource limits, backup, and rollback

Run resource-intensive verification one worker at a time through `api/scripts/run-bounded-process.sh`, with MemAvailable at least 524288 KiB, SwapFree at least 131072 KiB, five samples spaced five seconds apart, first-breach cancellation, and process-tree RSS no higher than 262144 KiB. A resource refusal is a blocked result; do not lower limits or repeat the same blocked command.

For application rollback, return the deployment pointer to the prior release and retain the failed release logs. Migrations are forward-only in this runbook: do not run speculative down-migrations. If data restoration is required, an authorized staging owner must stop writes, verify the backup checksum, restore to the isolated staging database, and rerun health/readiness checks. Record the restore decision and result.

## Readiness record

The external staging record should include the pinned commit, tool versions, environment-key names (never values), migration list, dataset hashes and dry-run/import outputs, backup hash, health/readiness responses, frontend build output, browser evidence, resource logs, rollback owner, and unresolved issues. A staging run does not establish field transfer safety, private-beta readiness, or production activation. Those gates remain blocked until their separate evidence is reviewed.
