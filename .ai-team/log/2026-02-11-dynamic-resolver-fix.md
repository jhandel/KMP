# Session Log: Dynamic Resolver Fix

**Date:** 2026-02-11
**Requested by:** Josh Handel

## Summary

Josh noticed the approval gate config panel doesn't display `$.trigger.activityId` for dynamic resolver workflows. Investigation revealed a two-part bug.

## What Was Done

### Bug 1 — Seed definition used wrong config structure (Kaylee)
The seed migration's approval node config used flat keys instead of nested `approverConfig`. This caused the dynamic resolver to be completely broken — the engine silently dropped `service`, `method`, and custom keys like `activity_id`.

**Fix:** Updated seed definition to use nested `approverConfig` with correct keys. Added engine flat config fallback so `resolverService`→`service`, `resolverMethod`→`method`, plus custom key passthrough for `dynamic` type.

### Bug 2 — Frontend config panel had no UI for resolver service fields (Wash)
The config panel only showed a bare "Context Path" input for dynamic approvers, hiding resolver service/method and custom config fields entirely.

**Fix:** Config panel now shows resolver service/method as read-only fields. Custom config keys get `renderValuePicker()` with context path support so users can bind them to trigger data (e.g., `activity_id: "$.trigger.activityId"`).

## Decisions Made

- New approval nodes MUST use nested `approverConfig` for dynamic resolver definitions
- Engine flat config fallback supports dynamic resolvers for backward compatibility
- Resolver service/method fields are read-only in the designer (set by workflow definition)
- Custom config keys get value picker with context path support
- Removed duplicate `action-create` node from Activities Authorization workflow (was causing dual-write)

## Verification

- 261 workflow tests pass
- Assets recompiled
