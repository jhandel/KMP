# KMP Nightly — Azure Deployment

The nightly KMP environment runs on **Azure Container Apps + Jobs**, backed by
**Azure Database for PostgreSQL Flexible Server**, with the Docker image
mirrored nightly from GHCR into an **Azure Container Registry**. Every
resource is defined in [`main.bicep`](./main.bicep); nothing is clicked in the
portal.

Seed data lives in [`seed/nightly-seed.kmpbackup`](./seed/) — an
engine-agnostic, AES-256-GCM-encrypted backup produced by
`seed/bake-seed.sh` from a known-good local dev environment. The in-container
reset job (`docker/reset-and-seed.sh`) restores this file via
`bin/cake backup restore`, so the nightly env state is byte-identical to what
any developer sees after running `reset_dev_database.sh`.

## Architecture

```
 GitHub Actions (nightly.yml)                         ┌────────────────────────┐
 └── builds & pushes ghcr.io/jhandel/kmp:nightly ──┐  │  Azure resource group  │
                                                    │  │  kmp-nightly-rg        │
 GitHub Actions (nightly-deploy-azure.yml)         │  │                        │
  1. OIDC → Azure                                  │  │  ACR <prefix>acr<hash> │
  2. az acr import ghcr→ACR                        └─▶│  └─ kmp:nightly-DATE   │
  3. run migrate job (wait)                           │                        │
  4. containerapp update web image                    │  Key Vault             │
  5. containerapp job update queue/sync images       │  ├─ security-salt      │
  6. smoke /health                                    │  ├─ database-url      │
                                                      │  ├─ postgres-admin-pwd│
                                                      │  ├─ backup-enc-key    │
                                                      │  └─ email-smtp-pwd    │
                                                      │                        │
                                                      │  Postgres Flex (B1ms) │
                                                      │  └─ kmp_nightly db    │
                                                      │                        │
                                                      │  Container Apps env    │
                                                      │  ├─ <prefix>-web       │
                                                      │  ├─ <prefix>-migrate   │
                                                      │  ├─ <prefix>-reset     │
                                                      │  ├─ <prefix>-queue     │
                                                      │  └─ <prefix>-sync      │
                                                      └────────────────────────┘
```

All Container Apps Jobs reuse the exact same image that the web app runs.
They differ only in env vars and arg overrides:
- `<prefix>-migrate` — `entrypoint.prod.sh /bin/true`; entrypoint applies
  migrations and exits
- `<prefix>-reset` — runs `/opt/kmp/reset-and-seed.sh` which drops schema,
  re-applies migrations, and restores the bundled encrypted seed
- `<prefix>-queue` — `bin/cake queue run --max-jobs 25 -q` every 5 minutes
- `<prefix>-sync` — `bin/cake sync_active_window_statuses` nightly at 07:15 UTC

## One-time bootstrap

Everything below is idempotent; rerun safely.

### Prerequisites
- `az` CLI logged in as an account with **Owner** (or Contributor + User
  Access Administrator) on the subscription
- `gh` CLI authenticated (for setting repo secrets)
- You are in the repo root.
- `deploy/azure/seed/nightly-seed.kmpbackup` exists in the repo (bake one
  via `deploy/azure/seed/bake-seed.sh` if this is the first time — see
  [`seed/README.md`](./seed/README.md)).

### 1. Fill in settings

```bash
cp deploy/azure/nightly.env.example deploy/azure/nightly.env
# edit deploy/azure/nightly.env — generate strong secrets with:
#   openssl rand -hex 32                       # for SECURITY_SALT and BACKUP_ENCRYPTION_KEY
#   openssl rand -base64 24 | tr -d '/+='      # for POSTGRES_ADMIN_PASSWORD
```

`BACKUP_ENCRYPTION_KEY` must match the key you used (or will use) when
running `bake-seed.sh`. Save both values in a password manager.

`nightly.env` is gitignored — never commit real values.

### 2. Run bootstrap

```bash
cd deploy/azure
./bootstrap.sh
```

This will:
1. Register required Azure resource providers (already done in your sub).
2. Create the resource group.
3. Provision the ACR and `az acr import` the current `ghcr.io/jhandel/kmp:nightly`.
4. Deploy all infrastructure from `main.bicep`.
5. Create an AAD app `kmp-nightly-github-oidc` with a federated credential for
   GitHub (`jhandel/KMP` on `main`, `feature/workflow-engine`, and environment
   `nightly`).
6. Assign the AAD app **Contributor** on the resource group.
7. Push `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`, `AZURE_SUBSCRIPTION_ID` as repo
   secrets and infrastructure names as repo variables via `gh`.
8. Start the `kmp-migrate` job to apply base migrations.

Skip `gh` integration with `./bootstrap.sh --skip-gh-secrets`.

### 3. Seed / reset the database

Seeding runs **inside the container** via the `kmp-reset` Container Apps Job,
which mirrors `reset_dev_database.sh`:

1. `bin/cake resetDatabase` — drop + recreate schema
2. Load `/opt/kmp/seed.sql` (the dev seed, baked into the image)
3. `bin/cake migrations migrate` + `bin/cake updateDatabase`
4. Reset every member password to `TestPassword`
5. Clear caches

`bootstrap.sh` kicks this job automatically at the end. To re-run it any time:

```bash
RG="$AZURE_RESOURCE_GROUP"
az containerapp job start -g "$RG" -n "${AZURE_NAME_PREFIX}-reset"

# watch progress
az containerapp logs show -g "$RG" -n "${AZURE_NAME_PREFIX}-reset" --container reset --tail 200 --follow
```

The reset job can also be triggered from GitHub Actions by running
**Nightly / Deploy to Azure** via `workflow_dispatch` with
`full_reset = true`.

### 4. Verify

```bash
WEB=$(az containerapp show -g kmp-nightly-rg -n kmpnightly-web \
      --query properties.configuration.ingress.fqdn -o tsv)
curl -sv "https://$WEB/health"
```

## Nightly re-deploys

Every successful run of `nightly.yml` triggers `nightly-deploy-azure.yml`
via `workflow_run`. That workflow:

1. Logs in to Azure via OIDC — **no long-lived secrets**
2. `az acr import` the new nightly image (dual-tags as `nightly` and
   `nightly-YYYY-MM-DD`)
3. Runs `kmp-migrate` and waits for it to succeed (fails the deploy on
   migration error — web is left on the previous revision)
4. `az containerapp update` the web app and each job to the new image
5. Polls `/health` until 200

You can also trigger it manually from the **Actions** tab → "Nightly / Deploy
to Azure" → **Run workflow**, optionally overriding the image tag.

## Common operations

| Task | Command |
|------|---------|
| Open site | `az containerapp show -g $RG -n kmpnightly-web --query properties.configuration.ingress.fqdn -o tsv` |
| Tail web logs | `az containerapp logs show -g $RG -n kmpnightly-web --tail 200 --follow` |
| Run migrations on-demand | `az containerapp job start -g $RG -n kmpnightly-migrate` |
| See recent job executions | `az containerapp job execution list -g $RG -n kmpnightly-queue -o table` |
| Rotate a secret | `az keyvault secret set --vault-name <kv> --name security-salt --value <new>` then `az containerapp revision restart` on the web app |
| Nuke and redeploy | `az group delete -n kmp-nightly-rg --yes --no-wait` then rerun `bootstrap.sh` |

## Cost expectations (US central)

| Resource | SKU | ~ Monthly |
|----------|-----|-----------|
| Postgres Flex | B1ms, 32 GB | ~$15 |
| Container Apps (web) | Consumption, 1 always-on | ~$8–15 |
| Container Apps Jobs | Consumption, ~300 min/mo | <$2 |
| ACR | Basic | $5 |
| Log Analytics | first 5 GB free | $0–3 |
| Key Vault | standard | <$1 |
| **Total** | | **~$30–40 / month** |

## Security notes

- **Public ingress, HTTPS-only.** All traffic enters through the Container Apps
  auto-issued TLS cert.
- **Postgres public access with TLS required.** Firewall rule
  `AllowAzureServices` lets Container Apps in; everything else is rejected.
  Secrets never hit GitHub — they live in Key Vault and are referenced via
  user-assigned managed identity.
- **Encrypted seed payload.** `deploy/azure/seed/nightly-seed.kmpbackup` is
  AES-256-GCM encrypted; even if the repo leaks, the committed blob is
  unreadable without the key stored in Key Vault.
- **GitHub → Azure auth is OIDC.** No client secret exists. If the repo is
  deleted/transferred, revoke by deleting the federated credential on the
  AAD app.
- **Blast radius.** The AAD app is scoped **Contributor on the resource
  group only** — it cannot touch anything outside `kmp-nightly-rg`.

## File map

- `main.bicep` — full resource graph (ACR, UAMI, KV, Postgres Flex, ACA env,
  web + 4 jobs, role assignments)
- `bootstrap.sh` — one-time provisioning + GitHub secrets wiring
- `seed/` — encrypted seed backup + bake helper; see `seed/README.md`
- `nightly.env.example` — settings template (copy to `nightly.env`)
- `../../docker/reset-and-seed.sh` — in-container reset script invoked by
  the reset job (engine-agnostic, restores from `seed/nightly-seed.kmpbackup`)
- `../../.github/workflows/nightly-deploy-azure.yml` — automated re-deploy
  on every green nightly image

## Known limitations / future work

- Nightly builds from `feature/workflow-engine`: `nightly.yml` currently
  builds on `schedule` (always default branch = `main`) and `push` to `main`.
  If you want nightly builds of `feature/workflow-engine` while that branch
  is active, add another trigger or a dispatch with `ref` to nightly.yml.
  The deploy workflow already accepts runs from either branch.
- Custom domain: the Container App has the default
  `*.azurecontainerapps.io` FQDN. To add `nightly.ansteorra.org`, attach a
  managed certificate + CNAME — one day of additional work.
- Document storage is local filesystem inside the web container (no
  persistence across restarts). If you upload files during testing, wire up
  `DOCUMENT_STORAGE_ADAPTER=azure` + a storage account — commented stubs
  exist in `nightly.env.example`.
