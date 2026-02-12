### Approval Node — Third Output Port UI

**By:** Wash
**Date:** 2026-02-12
**What:** Approval nodes in the workflow designer now have 3 output ports: Approved, Rejected, and Each Step (engine name: `on_each_approval`).

**UI details:**
- Port label in designer: "Each Step" (human-readable)
- Port name in edge data: `on_each_approval` (engine-facing, must match backend)
- Visual style: Blue badge (`.wf-port-label-mid`) — distinct from green (approved) and red (rejected)

**Backward compatibility:** Existing saved workflows with 2-port approval nodes render correctly. Drawflow auto-creates the third output port on import. Workflows without edges to port 3 are unaffected — empty target list means no-op.

**Depends on:** Mal's backend engine change to traverse `on_each_approval` edges during intermediate approval steps.
