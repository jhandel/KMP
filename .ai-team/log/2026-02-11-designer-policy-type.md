# Session Log: Designer Policy Type

**Date:** 2026-02-11
**Requested by:** Josh Handel

## Who Worked

- **Wash** — implemented the fix in the workflow designer config panel

## What Happened

- Josh noticed the workflow designer UI was missing the "policy" approver type.
- Investigation confirmed the seeded workflow definition is correct (already contains policy configuration), but the designer UI only exposed 4 approver types: permission, role, member, and dynamic.
- Wash added a "By Policy" option to the designer with 5 conditional fields: `policyClass`, `policyAction`, `entityTable`, `entityIdKey`, `permission`.

## Decisions

- Single permission covering approve+decline is the established pattern — no change needed.

## Outcome

- One commit: `feat(workflow) adding policy approver type to designer config panel`
- 273 tests still passing.
