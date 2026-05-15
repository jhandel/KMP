# Tenant Database Topology for Azure PostgreSQL Flexible Server

This guide defines the **production tenant database topology** for KMP multi-tenancy on Azure Database for PostgreSQL Flexible Server. It complements the capacity and shard-threshold guidance in [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md) and the execution workflow in [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md).

## Scope and baseline assumptions

- KMP uses host-based multi-tenancy with a separate `platform` datasource for tenant registry/provisioning metadata and a `tenant` datasource for tenant application data.
- The platform and tenant data planes are isolated at the database and role level.
- Topology decisions must follow capacity trigger gates and split thresholds in the capacity model.

## Recommended baseline topology (production default)

Use a **two-tier topology**:

1. **Platform server** (dedicated Flexible Server):
   - Hosts one `kmp_platform` database only.
   - Stores global tenant registry and service metadata.
   - No tenant application tables or queue payload data.
2. **Tenant shard servers** (one or more Flexible Servers):
   - Each tenant gets its own logical PostgreSQL database (for example `kmp_tenant_<slug>`).
   - Multiple tenant databases can share one shard server until split gates are reached.

This is the default production shape because it limits blast radius, keeps platform availability independent of tenant load spikes, and matches KMP’s platform/tenant datasource design.

## Tenant-to-server allocation strategy

### Initial allocation

- Place new tenants on the current shard server with the most headroom.
- Keep one logical DB per tenant and one DB role per tenant.
- Keep the platform database on its own server from day one for production.

### Ongoing shard management

- Recompute connection budget whenever web or worker replica counts change.
- Apply split planning at `>=80%` of `T_operational_max` and stop onboarding to that shard at `>=90%` (per capacity model).
- Move highest-impact tenants first when noisy-neighbor gates are exceeded repeatedly.

### When to split platform from tenant workload

If currently co-located, split immediately when any of these apply:

- More than one production tenant.
- Any sustained connection pressure, query latency inflation, or noisy-neighbor behavior.
- Need for independent platform maintenance windows, backup cadence, or restore drills.

Co-location is acceptable only for short-lived dev/test or initial pilot environments with explicit risk acceptance.

## Isolation model

### Data and schema isolation

- **Per-tenant logical database** on a shard server.
- No shared tenant tables across tenants.
- Platform schema/tables exist only in the platform database.

### Identity and permissions

- Create one DB role/user per tenant database with least privilege on that tenant DB only.
- Do not grant tenant roles access to platform DB, `postgres`, or other tenant databases.
- Use separate administrative credentials for migrations/operations and keep them out of tenant runtime credentials.

### Blast-radius boundaries

- Tenant DB corruption/restore actions affect one tenant DB, not all tenants on a shard.
- Shard-level incidents can still affect all tenants on that shard; use capacity split thresholds to keep shard density bounded.
- Platform outage should not imply tenant data corruption; it primarily impacts tenant resolution/provisioning operations.

## Migration and cutover implications

### Provisioning

1. Run `bin/cake platform:migrate` on the platform server.
2. Create tenant DB and tenant DB role on target shard server.
3. Register tenant DB config in platform metadata.
4. Run `bin/cake tenant:migrate --tenant=<slug>`.
5. Validate with `bin/cake tenant:doctor --tenant=<slug>` before activation.

### Re-sharding (tenant move between servers)

1. Put tenant in maintenance mode (`tenant:maintenance <slug>`).
2. Backup/export source tenant DB.
3. Restore/import into destination shard server tenant DB.
4. Update `tenant_database_configs` to destination host/db/credentials.
5. Run `tenant:doctor` and application smoke tests on tenant host.
6. Re-enable tenant and monitor connection/query metrics.

### Operational constraints

- Move tenants one at a time to reduce rollback complexity.
- Keep DNS/host routing unchanged; only platform DB metadata and tenant DB endpoints change.
- Always verify queue workers/jobs are connected to the intended tenant context after cutover.

## Decision matrix

| Topology option | Use when | Avoid when | Trade-offs |
|---|---|---|---|
| **A. Single server, platform + all tenant DBs (co-located)** | Dev/test, short pilot, very small temporary footprint | Production with multiple active tenants | Lowest cost, highest blast radius, weakest noisy-neighbor isolation |
| **B. Dedicated platform server + single tenant shard server (recommended starting production topology)** | Initial production rollout with limited tenant count and clear growth plan | None for early production; this is the default | Good isolation for platform plane, simple ops, moderate cost |
| **C. Dedicated platform server + multiple tenant shard servers (recommended growth topology)** | Capacity gates nearing limits, repeated noisy-neighbor events, predictable growth | Very low tenant count with no growth pressure | Best operational isolation and scaling flexibility, higher operational overhead |
| **D. Dedicated server per tenant (premium isolation)** | Regulated/high-sensitivity tenants, strict custom SLO/maintenance boundaries | Standard tenants without contractual isolation need | Strongest isolation, highest cost and management complexity |

## Recommended production policy

- Default to **Option B** at first production launch.
- Evolve to **Option C** based on documented split triggers from the capacity model.
- Use **Option D** only for tenants with explicit isolation/compliance requirements.
- Do not use **Option A** for steady-state production.

## Cross-reference checklist

- Capacity and split thresholds: [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md)
- Operational split/move procedure: [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md)
- Production TLS/pooling/failover/restore/networking guidance: [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)
- Multi-tenant runtime and commands: [Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- Azure deployment shape reference: [`deploy/azure/README.md`](../../deploy/azure/README.md)
