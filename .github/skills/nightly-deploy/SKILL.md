---
name: nightly-deploy
description: Deploy KMP to the Azure nightly environment. Use when the user asks to deploy to nightly, push updates to nightly, build + deploy the nightly image, reset the nightly database, check nightly health/status, or get the nightly URL. Triggers on phrases like "deploy to nightly", "push to nightly", "redeploy nightly", "reset nightly db", "nightly status", or "nightly health".
---

# KMP Nightly Azure Deploy

All nightly deploy operations go through the `deploy/azure/nightly-deploy.sh` script, which wraps GitHub Actions workflows. You almost never need to call `az` or `gh workflow run` directly.

## Live environment

- **URL**: https://kmpnightly-web.lemonstone-62ccb06f.centralus.azurecontainerapps.io/
- **Login**: any seeded email + `TestPassword` (example: `admin@amp.ansteorra.org`)
- **Azure RG**: `kmp-nightly-rg` in `centralus`

## Usage

Always invoke from the repo root. The script auto-detects the current git branch.

| Intent | Command |
|---|---|
| Deploy current `:nightly` image (fast path — image already built) | `bash deploy/azure/nightly-deploy.sh` |
| Build a new image from HEAD then auto-deploy | `bash deploy/azure/nightly-deploy.sh build` |
| Deploy and wipe+reseed the database | `bash deploy/azure/nightly-deploy.sh reset` |
| See recent run status | `bash deploy/azure/nightly-deploy.sh status` |
| Tail the currently running deploy | `bash deploy/azure/nightly-deploy.sh watch` |
| Smoke-check `/health` | `bash deploy/azure/nightly-deploy.sh health` |
| Print the URL | `bash deploy/azure/nightly-deploy.sh url` |

Override branch: `NIGHTLY_BRANCH=main bash deploy/azure/nightly-deploy.sh`

## Typical flows

### "I just pushed, deploy it"
```bash
bash deploy/azure/nightly-deploy.sh build
bash deploy/azure/nightly-deploy.sh watch   # wait ~8 min
bash deploy/azure/nightly-deploy.sh health
```

### "Push and deploy didn't happen automatically"
Every push to `feature/workflow-engine` or `main` auto-chains build → deploy. If it didn't, check for failed workflow:
```bash
bash deploy/azure/nightly-deploy.sh status
```

### "Deploy is failing; what broke?"
```bash
bash deploy/azure/nightly-deploy.sh status   # get run IDs
gh run view <id> --log-failed                # failed step logs
```

### "Nightly DB is in a weird state — start over"
```bash
bash deploy/azure/nightly-deploy.sh reset    # destroys all data
```

## Under the hood

- **`build`** → `gh workflow run nightly.yml` — builds & pushes `ghcr.io/jhandel/kmp:nightly`. On success, a `workflow_run` trigger auto-fires the deploy workflow.
- **`deploy`** → `gh workflow run nightly-deploy-azure.yml` — `az acr import` → migrate job → web container update → update queue/sync/reset jobs → `/health` smoke check.
- **`reset`** → same as deploy but passes `full_reset=true`, which runs `kmpnightly-reset` job (drops schema, re-runs migrations, restores encrypted seed backup, sets every member's password to `TestPassword`).

Source of truth: `.github/workflows/nightly.yml` and `.github/workflows/nightly-deploy-azure.yml`.

## Important one-time caveat

Both workflow files (`nightly.yml` and `nightly-deploy-azure.yml`) must
exist on the repo's **default branch** (`main`) before GitHub Actions
will register them for `workflow_dispatch` / `workflow_run`. If the
script says *"workflow not yet registered — it must exist on the default
branch"*, cherry-pick / merge `.github/workflows/nightly-deploy-azure.yml`
to `main` once. After that, every future push to the feature branch
auto-chains build → deploy without any further action.

Until that happens, only `bash deploy/azure/nightly-deploy.sh build` is
directly callable — deploys have to be driven by hand (see checkpoint
notes in `deploy/azure/README.md`).

## When NOT to use this skill

- For local dev resets, use `bash reset_dev_database.sh` (MySQL) — not nightly.
- For production releases, use the release workflow (`.github/workflows/release.yml`) — not nightly.
- For infra changes (bicep), `deploy/azure/bootstrap.sh` is the tool — not this skill.
