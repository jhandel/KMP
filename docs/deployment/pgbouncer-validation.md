# PgBouncer Validation for KMP (CakePHP + PostgreSQL Multi-Tenancy)

This document records the current PgBouncer compatibility assessment for KMP and defines what is validated now vs. what still needs environment validation.

## Validation Scope and Current Status

### Validated now (codebase/static + local non-destructive checks)

- Reviewed tenant/platform datasource configuration in `app/config/app.php` (`default`, `platform`, `tenant`) and confirmed:
  - no persistent PDO connections (`"persistent" => false`)
  - no custom PDO flags currently set (`"flags" => []`)
- Searched app/migration code for session-state-heavy PostgreSQL features likely to break under transaction pooling:
  - no direct use found of `pg_advisory_lock`, `LISTEN/UNLISTEN`, `SET search_path`, temporary tables, or explicit SQL `PREPARE/DEALLOCATE`.
- Confirmed KMP uses explicit and nested transactions heavily (`Connection::transactional()`, `begin()/commit()/rollback()`) and at least one explicit `FOR UPDATE` lock path in workflow approvals.
- Attempted local Cake CLI command checks (`./bin/cake --version`, `migrations ... --help`, `tenant:migrate --help`, `platform:migrate --help`):
  - command bootstrap reaches application startup
  - full command execution blocked in this environment by missing local DB connection (`SQLSTATE[HY000] [2002] No such file or directory`) and local APCu warnings.

### Not yet validated (requires environment/integration testing)

- End-to-end runtime behavior with PgBouncer in front of real PostgreSQL for:
  - web traffic
  - queue workers
  - migration and provisioning commands
  - backup/restore flows

## Recommended Pooling Mode

### Recommendation: **Session pooling for initial production rollout**

Use **session pooling first** as the compatibility-safe default for KMP, then evaluate **transaction pooling** after checklist completion.

### Rationale

- CakePHP ORM + PDO PostgreSQL may rely on prepared statement semantics that are safer in session mode.
- KMP uses many explicit transactions and lock-sensitive paths; session mode minimizes cross-connection surprises during initial rollout.
- Multi-tenant operations (`tenant` + `platform` connections, migration/provisioning commands) are operationally sensitive; prioritize correctness before throughput optimization.

### Transaction pooling guidance (optional optimization phase)

Transaction mode can be evaluated later, but only with explicit compatibility controls and test evidence:

- Ensure prepared statement behavior is compatible with PgBouncer transaction pooling (typically by using client-side emulated prepares and/or PgBouncer settings compatible with prepared statements).
- Run the full checklist in this document against production-like load before enabling in production.

## Caveats to Track (CakePHP/PostgreSQL + PgBouncer)

### 1) Prepared statements

- Risk in transaction pooling: server-side prepared statements may not survive backend reassignment between transactions.
- For transaction pooling trials, validate PDO/Cake settings so statement execution does not depend on backend-pinned prepared state.

### 2) Transactions and row locks

- KMP has many transactional write paths and uses `FOR UPDATE` in workflow approval logic.
- These are generally compatible with PgBouncer, but behavior must be verified under concurrency and retry scenarios.

### 3) Advisory locks

- No direct advisory lock usage found in current code scan.
- If introduced later (`pg_advisory_lock*`), document and re-validate mode choice.

### 4) Session variables / connection state

- No direct SQL session-state commands (`SET search_path`, temp table usage, LISTEN/UNLISTEN) found in current scan.
- Transaction pooling is sensitive to hidden/session-scoped state; re-check whenever connection init behavior changes.

### 5) Migrations and provisioning commands

- Several migrations explicitly disable wrapping transactions for PostgreSQL edge cases.
- Validate `platform:migrate`, `tenant:migrate`, and plugin migrations with PgBouncer in the path before broad rollout.

## Required Test Checklist (Before Declaring Mode Supported)

Run all items against a staging environment that matches production topology (PgBouncer + PostgreSQL + KMP web + workers).

### A. Connectivity and bootstrap

- [ ] `platform` and `tenant` datasources connect successfully through PgBouncer.
- [ ] Tenant host resolution still points at the correct per-tenant DB config.
- [ ] No authentication/SSL regression with pooled endpoints.

### B. Read/write behavior

- [ ] Core CRUD flows succeed for representative tenant traffic.
- [ ] Multi-step transactions commit/rollback correctly.
- [ ] Concurrency paths using `FOR UPDATE` do not deadlock unexpectedly at normal load.

### C. Command paths

- [ ] `bin/cake platform:migrate` succeeds via PgBouncer.
- [ ] `bin/cake tenant:migrate --tenant=<tenant>` succeeds via PgBouncer.
- [ ] Any periodic/queue worker command path runs reliably under pooled connections.

### D. Prepared statement compatibility

- [ ] No `prepared statement does not exist` or related protocol errors under load.
- [ ] If transaction mode is tested, verify configured approach (emulated prepares and/or PgBouncer prepared statement support) with sustained traffic.

### E. Backup/restore and maintenance

- [ ] Backup/export and restore commands run successfully with PgBouncer in path.
- [ ] Post-restore integrity checks pass (including PostgreSQL FK validation paths).

### F. Observability and SLOs

- [ ] Capture baseline: error rate, p95 latency, DB connection counts before/after pooling.
- [ ] Confirm no increase in migration failures, queue retries, or transaction abort rates.

## Rollout Guidance and Fallback Plan

### Phase 1: Safe introduction (recommended)

1. Deploy PgBouncer with **session pooling**.
2. Route a limited tenant cohort/canary traffic first.
3. Monitor:
   - connection errors
   - transaction rollback anomalies
   - migration/provisioning failures
   - queue retry/error rates
4. Expand gradually after stable observation window.

### Phase 2: Optional optimization (transaction mode)

1. Enable transaction pooling in staging first.
2. Validate prepared statement behavior explicitly.
3. Run concurrency + command-path checklist.
4. Promote only if no regressions and clear connection-pressure benefit.

### Fallback plan (must be pre-approved before rollout)

- Keep PgBouncer config ready to revert from transaction mode to session mode quickly.
- Keep direct PostgreSQL connection endpoint available for emergency bypass.
- On severe regression:
  1. Switch application DSNs back to known-good path (session mode or direct DB).
  2. Restart web/worker processes to clear stale pooled state.
  3. Re-run smoke tests (`platform`, `tenant`, critical transactional workflows).
  4. Hold rollout and capture incident findings before next attempt.

## Decision Record (Current)

- **Current recommended production mode:** session pooling.
- **Transaction pooling status:** not yet approved for production in KMP until full checklist is completed in staging with representative load.
