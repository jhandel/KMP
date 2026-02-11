# Session Log: Resolver Config Editable

**Date:** 2026-02-11
**Requested by:** Josh Handel

## Who Worked

- **Wash** — frontend implementation (workflow designer)

## What Was Done

Josh requested that the dynamic resolver config fields (service, method, custom params) be fully editable in the workflow designer, not read-only — so other plugins can configure their own resolvers.

Wash implemented the following changes:
- Made resolver service and method editable inputs (previously read-only)
- Added add/remove custom param UI with value picker support
- Added `approverConfig.*` handling in `updateNodeConfig`
- Added `addResolverParam` and `removeResolverParam` methods

## Files Changed

- `workflow-config-panel.js`
- `workflow-designer-controller.js`
- Compiled assets

## Decisions Made

- Dynamic resolver config fields must be fully editable, not read-only
- This supersedes the prior decision that resolver service/method were read-only

## Key Outcomes

- Other plugins can now configure their own dynamic resolvers through the workflow designer UI
