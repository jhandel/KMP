# Decision: WorkflowApproverResolverRegistry

**Author:** Kaylee (Backend Dev)
**Date:** 2026-02-12
**Status:** Implemented
**Requested by:** Josh Handel

## Context

Dynamic approver resolvers were referenced by raw FQCN in workflow configs (e.g., `Activities\Services\AuthorizationApproverResolver`). The designer had no way to discover available resolvers — users had to type class names manually.

## Decision

Created `WorkflowApproverResolverRegistry` following the exact same static registry pattern as `WorkflowActionRegistry` and `WorkflowTriggerRegistry`. Key decisions:

1. **Registry key as service reference**: `approver_config.service` now accepts a registry key (e.g., `Activities.AuthorizationApproverResolver`) which is resolved to a `serviceClass` at runtime.
2. **Backward compatibility**: If the key isn't found in the registry, it's treated as a direct FQCN — no migration needed for existing workflow instances.
3. **Designer integration**: `getForDesigner()` strips `serviceClass` (security) and exposes `configSchema` so the UI can render config fields per resolver.
4. **Seed updated**: The activities-authorization seed now uses the registry key instead of the raw class name.

## Files Changed

- `app/src/Services/WorkflowRegistry/WorkflowApproverResolverRegistry.php` (new)
- `app/plugins/Activities/src/Services/ActivitiesWorkflowProvider.php` (modified)
- `app/src/Controller/WorkflowsController.php` (modified)
- `app/src/Services/WorkflowEngine/DefaultWorkflowApprovalManager.php` (modified)
- `app/config/Migrations/20260209170000_SeedWorkflowDefinitions.php` (modified)
