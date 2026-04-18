---
name: nightly-deploy
description: Deploy KMP to the Azure nightly environment. Use when the user asks to deploy to nightly, push updates to nightly, build + deploy the nightly image, reset the nightly database, check nightly health/status, tail nightly logs, or get the nightly URL. Triggers on phrases like "deploy to nightly", "push to nightly", "redeploy nightly", "reset nightly db", "nightly status", "nightly logs", or "nightly health".
---

# KMP Nightly Azure Deploy

All nightly deploy operations go through `deploy/azure/nightly-deploy.sh`.
The script talks directly to Azure via `az` CLI (no dependency on the
`nightly-deploy-azure.yml` GitHub Actions workflow, which isn't registered
on `main`). It reuses `gh` only for the *build* step, which hits the
well-registered `nightly.yml` workflow.

## Prerequisites

- `az login --tenant 77070ec3-247c-40ce-9a4f-df875ffe914f` (once per codespace)
- `gh auth login` (for build/status subcommands)

## Live environment

- **URL**: https://kmpnightly-web.lemonstone-62ccb06f.centralus.azurecontainerapps.io/
- **Login**: any seeded email + `TestPassword` (example: `admin@amp.ansteorra.org`)
- **Azure RG**: `kmp-nightly-rg` / ACR: `kmpnightlyacrd346d2`

## Usage

Always from the repo root.

| Intent | Command |
|---|---|
| Deploy whatever `:nightly` currently is in GHCR | `bash deploy/azure/nightly-deploy.sh deploy` |
| Rebuild image from HEAD then deploy | `bash deploy/azure/nightly-deploy.sh build` |
| Deploy + wipe & reseed DB (all passwords → TestPassword) | `bash deploy/azure/nightly-deploy.sh reset` |
| Recent GHCR build run status | `bash deploy/azure/nightly-deploy.sh status` |
| Tail latest running build | `bash deploy/azure/nightly-deploy.sh watch` |
| Show Container App revisions | `bash deploy/azure/nightly-deploy.sh revisions` |
| Tail web container logs | `bash deploy/azure/nightly-deploy.sh logs [--tail N]` |
| Curl `/health` | `bash deploy/azure/nightly-deploy.sh health` |
| Print the URL | `bash deploy/azure/nightly-deploy.sh url` |
| Show help | `bash deploy/azure/nightly-deploy.sh help` |

Overrides via env: `IMAGE_TAG`, `NIGHTLY_BRANCH`, `GH_REPO`,
`AZURE_SUBSCRIPTION_ID`, `AZURE_RESOURCE_GROUP`, `AZURE_ACR_NAME`,
`AZURE_WEB_APP_NAME`, `AZURE_{MIGRATE,QUEUE,SYNC,RESET}_JOB_NAME`.

## What `deploy` does

1. `az acr import ghcr.io/jhandel/kmp:nightly → kmp:nightly-YYYY-MM-DD-HHMMSS`
2. `az containerapp job update + start` for the migrate job, polls until Succeeded
3. (if `--reset`) same for the reset job (drops schema, reseeds from encrypted backup, sets passwords)
4. `az containerapp update --image …` on `kmpnightly-web` — forces a new revision
5. `az containerapp job update --image …` on queue / sync / reset jobs so their next scheduled run uses the new image
6. Polls `https://…/health` for up to 10 min until 200 OK

## Typical flows

### "I just pushed — deploy it"
```bash
bash deploy/azure/nightly-deploy.sh build
```
(Script auto-chains into `deploy` after the GH build finishes.)

### "GHCR already has the image I want"
```bash
bash deploy/azure/nightly-deploy.sh deploy
```

### "DB is wedged — start over"
```bash
bash deploy/azure/nightly-deploy.sh reset
```

### "Something is broken — what's the pod saying?"
```bash
bash deploy/azure/nightly-deploy.sh revisions
bash deploy/azure/nightly-deploy.sh logs --tail 500
```

## When NOT to use this skill

- For local dev resets, use `bash reset_dev_database.sh` (MySQL) — not nightly.
- For production releases, use the release workflow (`.github/workflows/release.yml`).
- For infra / bicep changes, use `deploy/azure/bootstrap.sh`.
