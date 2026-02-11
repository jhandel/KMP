# Activities Workflow — Phase 4+5 (Seed Migration & E2E Testing)

**Date:** 2026-02-11
**Requested by:** Josh Handel

## Who Worked

### Kaylee (Backend)
- Created seed migration for Activities Authorization approval workflow.
- Slug: `activities-authorization`, `is_active=0` (disabled by default, admin opt-in).
- Added `getActivitiesAuthorizationDefinition()` to `SeedWorkflowDefinitions.php`.
- 9-node graph matching architecture spec: trigger-auth → action-create → approval-gate → (approved: action-activate → action-notify-approved → end-approved) / (rejected: action-deny → action-notify-denied → end-denied).

### Jayne (Testing)
- Ran E2E Playwright tests against Activities authorization approval workflow.
- **Results:** 4/4 test areas pass. 16 screenshots saved.
- **Finding (medium severity):** Auth queue (`/activities/authorization-approvals/my-queue`) only accessible to super-user admin. MoAS officers and Armored marshals get redirected to unauthorized. May be by design — needs confirmation whether approvals should route through `/workflows/approvals` instead.

## Decisions

- Activities workflow scope: submit-to-approval only. Revoked/Expired are out-of-band.
- Seed migration uses `is_active=0` — administrators explicitly enable.
- Auth queue permission gating question raised — deferred for confirmation.
