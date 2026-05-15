# Tenant Onboarding Runbook (Gateway + Jobs + Migration)

Use this runbook to onboard one new tenant into managed multi-tenant KMP using the implemented platform registry, operations gateway, and durable `tenant_operation_jobs` worker flow.

[← Back to Deployment Guide](README.md)

## Scope and alignment

This runbook aligns with:

- [Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Production Command Strategy](production-command-strategy.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)

## Preconditions

Before starting:

- You have platform-admin requester/approver accounts (active in `platform_admins`).
- `platform` datasource points to the platform DB (not a tenant DB).
- A worker runner is available for `tenant_operation:worker`.
- Tenant slug, hostnames, DB naming, and secret reference names are approved.
- Rollback target is defined (status fallback + DNS rollback + DB cleanup policy).

## 1) DNS and host setup

1. Choose tenant identifiers:
   - slug: `<tenant-slug>`
   - primary host: `<tenant>.kmp.example.org`
   - aliases (optional): `www.<tenant>.kmp.example.org`, legacy hostnames
2. Create DNS records for primary host and aliases pointing to the app ingress/load balancer.
3. Confirm ingress/TLS covers the hostnames.
4. Validate resolution before activation:

```bash
dig +short <tenant>.kmp.example.org
```

Checkpoint A (host readiness):

- DNS resolves to expected ingress.
- TLS/host routing is configured.
- Tenant is still non-active (no public traffic yet).

## 2) Database and DB user setup

Create one logical DB and one least-privilege DB user for the tenant on the target shard/server.

- DB name: `kmp_<tenant-slug>` (or your shard naming standard)
- DB user: tenant-specific service account
- Grants: only this tenant DB

You may create the DB in either path:

- externally via your DBA/IaC process (preferred), or
- `tenant:create --create-database` (best-effort; requires compatible driver/privileges).

Checkpoint B (data-plane readiness):

- Tenant DB exists.
- Tenant DB user can connect to that DB.
- No tenant permissions on platform DB or other tenant DBs.

## 3) Tenant metadata + aliases (platform registry)

Run platform migrations first (idempotent/safe to rerun):

```bash
cd app
bin/cake platform:migrate
```

Preview onboarding without writes:

```bash
bin/cake tenant:create <tenant-slug> \
  --display-name="<Tenant Display Name>" \
  --primary-host=<tenant>.kmp.example.org \
  --alias=www.<tenant>.kmp.example.org \
  --database-name=kmp_<tenant-slug> \
  --host=<db-host> \
  --port=<db-port> \
  --username=<db-user> \
  --secret-reference=env:<TENANT_DB_PASSWORD_ENV> \
  --dry-run
```

Persist tenant metadata and aliases:

```bash
bin/cake tenant:create <tenant-slug> \
  --display-name="<Tenant Display Name>" \
  --primary-host=<tenant>.kmp.example.org \
  --alias=www.<tenant>.kmp.example.org \
  --database-name=kmp_<tenant-slug> \
  --host=<db-host> \
  --port=<db-port> \
  --username=<db-user> \
  --secret-reference=env:<TENANT_DB_PASSWORD_ENV>
```

Checkpoint C (registry readiness):

- `tenant:list` shows tenant with expected slug/host.
- `tenant_aliases` include primary + expected aliases.
- Tenant status remains `provisioning` until activation gates pass.

## 4) Secrets setup (DB/email/storage)

Store secrets as references in platform metadata (do not inline plaintext secrets in docs/commands/history).

Examples during create/update:

- DB password: `--secret-reference=env:<TENANT_DB_PASSWORD_ENV>`
- Email password: `--email-secret-reference=env:<TENANT_SMTP_PASSWORD_ENV>`
- Storage secret: `--storage-secret-reference=env:<TENANT_STORAGE_SECRET_ENV>`

Optional email/storage metadata:

```bash
bin/cake tenant:create <tenant-slug> \
  --email-config-json='{"transport":{"host":"smtp.example.org","username":"mailer"},"email":{"from":"noreply@example.org"}}' \
  --email-secret-reference=env:<TENANT_SMTP_PASSWORD_ENV> \
  --storage-adapter=s3 \
  --storage-config-json='{"s3":{"bucket":"kmp-<tenant>-docs","region":"us-east-1"}}' \
  --storage-secret-reference=env:<TENANT_STORAGE_SECRET_ENV>
```

Checkpoint D (secrets readiness):

- All required secret references resolve in runtime environment.
- No tenant credentials stored in plain text in platform metadata or logs.

## 5) Migration and activation (gateway + worker path)

Use operations gateway + durable jobs for migration/validation/activation steps.

### 5.1 Enqueue tenant migration

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_migrate \
  --tenant=<tenant-slug> \
  --parameters-json='{}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-migrate:<tenant-slug>:v1
```

Process jobs:

```bash
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-tenant-onboarding}"
```

### 5.2 Enqueue tenant doctor

```bash
bin/cake tenant_operation:enqueue \
  --operation=tenant_doctor \
  --tenant=<tenant-slug> \
  --parameters-json='{}' \
  --requester-email=<requester@example.com> \
  --idempotency-key=tenant-doctor:<tenant-slug>:post-migrate
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-tenant-onboarding}"
```

### 5.3 Activate tenant (status -> active)

```bash
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenant=<tenant-slug> \
  --parameters-json='{"status":"active"}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-status:<tenant-slug>:active
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-tenant-onboarding}"
```

Checkpoint E (go-live gate):

- Migration job reaches `completed` with expected schema change.
- Doctor job reports all required checks `ok`.
- Status job reaches `completed` and tenant status is `active`.
- Tenant host login + basic read/write smoke checks pass.

## 6) Validation checkpoints and rollback

### Required validation checklist

1. `bin/cake tenant:list` shows expected host + status.
2. `bin/cake tenant:doctor --tenant=<tenant-slug>` passes after activation.
3. No schema mismatch/unavailable response for active tenant.
4. No cross-tenant data exposure indicators in logs/audit.
5. Worker/audit events include correlation and job IDs for traceability.

### Rollback decision points

- **Before activation failure** (migration/doctor failed):
  - Keep tenant non-active (`provisioning` or `maintenance`).
  - Fix DB/metadata/secrets issue.
  - Re-enqueue operation with a new idempotency key after remediation.
- **After activation failure** (tenant-facing errors):
  - Enqueue `tenant_status` -> `maintenance` immediately.
  - Execute repair or restore/cutover runbook as needed.
  - Re-run doctor + smoke checks.
  - Re-activate only after all gates pass.

Rollback command example:

```bash
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenant=<tenant-slug> \
  --parameters-json='{"status":"maintenance"}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-status:<tenant-slug>:maintenance
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-tenant-onboarding}"
```

## References

- [Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Production Command Strategy](production-command-strategy.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Backup & Restore](backup-restore-runbook.md)
