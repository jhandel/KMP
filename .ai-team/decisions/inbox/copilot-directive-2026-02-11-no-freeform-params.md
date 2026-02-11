### 2026-02-11: User directive — no freeform params in dynamic resolver section
**By:** Josh Handel (via Copilot)
**What:** Remove the "Add Param" freeform UI from the dynamic (registered) resolver section. When a resolver is selected from the registry dropdown, only its declared configSchema params should appear — no ability to add arbitrary extra params.
**Why:** User request — the registry's configSchema is the source of truth for what params a resolver needs. Freeform params add unnecessary complexity.
