# Workflow Engine — Per-Installation Customization Guide

This guide is for KMP administrators who need to customize workflows for their
kingdom's specific needs. No PHP coding is required — all customization is done
through the visual editor and JSON configuration.

---

## Table of Contents

1. [Accessing the Visual Editor](#accessing-the-visual-editor)
2. [Understanding the Editor Interface](#understanding-the-editor-interface)
3. [Modifying State Definitions](#modifying-state-definitions)
4. [Editing Conditions](#editing-conditions)
5. [Editing Actions](#editing-actions)
6. [Adding New States and Transitions](#adding-new-states-and-transitions)
7. [Configuring Visibility Rules](#configuring-visibility-rules)
8. [Configuring Approval Gates](#configuring-approval-gates)
9. [Testing Changes Before Publishing](#testing-changes-before-publishing)
10. [Publishing a New Version](#publishing-a-new-version)
11. [Importing and Exporting Definitions](#importing-and-exporting-definitions)
12. [Rollback Procedures](#rollback-procedures)
13. [Common Customization Scenarios](#common-customization-scenarios)

---

## Accessing the Visual Editor

1. Log in as an administrator with workflow management permissions.
2. Navigate to **Admin → Workflow Engine** (or go to `/workflow-engine`).
3. You'll see a list of all workflow definitions with their status:

   | Name | Entity Type | Version | Active |
   |------|-------------|---------|--------|
   | Award Recommendations | AwardsRecommendations | 3 | ✓ |
   | Warrant Lifecycle | Warrants | 1 | ✓ |
   | Activity Authorization | Authorizations | 2 | ✓ |
   | Officer Assignment | Officers.Officers | 1 | ✓ |

4. Click the **Editor** button next to any definition to open the visual editor.

**Direct URL**: `/workflow-engine/editor/{definition_id}`

---

## Understanding the Editor Interface

The visual editor has two main areas:

### Canvas (Left)

- **State nodes** are draggable boxes showing the state name, type, and category
- **Transition arrows** connect states, labeled with the transition name
- State colors indicate type:
  - 🟢 Green border = Initial state
  - ⚪ Gray border = Intermediate state
  - 🟡 Yellow border = Approval state
  - 🔴 Red border = Terminal state
- Drag states to rearrange the layout; positions are saved automatically

### Property Panel (Right)

Click any state or transition to edit its properties:

- **State properties**: name, slug, label, type, category, metadata, enter/exit actions
- **Transition properties**: name, slug, label, conditions, actions, trigger type, priority
- **Visibility rules**: accessible from state properties
- **Approval gates**: accessible from approval-type state properties

---

## Modifying State Definitions

### Renaming a State

1. Click the state on the canvas.
2. In the property panel, change the **Label** field (displayed to users).
3. Leave the **Slug** unchanged (used by code and transition references).
4. The **Name** field is the internal identifier.

### Changing State Type

1. Click the state.
2. In the property panel, change **State Type**:
   - `initial` — starting point of the workflow (typically one per workflow)
   - `intermediate` — normal processing state
   - `approval` — state that requires approvals before proceeding
   - `terminal` — final state (workflow completes when reached)

⚠️ **Warning**: Changing a state from `terminal` to another type will reactivate
completed instances in that state.

### Editing State Metadata

The `metadata` JSON field provides UI hints to the entity form. Common keys:

```json
{
    "visible": ["planToGiveBlockTarget", "givenBlockTarget"],
    "disabled": ["domainTarget", "awardTarget"],
    "required": ["planToGiveEventTarget", "givenDateTarget"],
    "set": {"close_reason": "Given"}
}
```

- `visible` — form sections to show in this state
- `disabled` — form fields to disable (read-only)
- `required` — form fields that must be filled
- `set` — field values to auto-set on entry

### Editing On-Enter / On-Exit Actions

States can run actions when entered or exited. Edit the JSON array:

```json
[
    {
        "type": "set_field",
        "field": "approved_date",
        "value": "{{now}}"
    },
    {
        "type": "send_email",
        "mailer": "Officers.Officers",
        "method": "notifyOfHire",
        "to": "{{entity.member.email_address}}",
        "vars": {
            "memberScaName": "{{entity.member.sca_name}}"
        }
    }
]
```

See `docs/workflow-engine.md` for the full Action DSL reference.

---

## Editing Conditions

Conditions control who can trigger a transition. Edit the `conditions` field
in the transition's property panel.

### Simple Permission Check

```json
{"permission": "canUpdateStates"}
```

### Combining Multiple Conditions

```json
{
    "all": [
        {"permission": "canApproveLevel"},
        {"not": {"ownership": "requester"}}
    ]
}
```

### Time-Based Conditions

```json
{
    "time": "state_duration",
    "operator": "gt",
    "value": 72,
    "unit": "hours"
}
```

### Common Condition Patterns

**Only the entity owner can trigger**:
```json
{"ownership": "requester"}
```

**Only users with a specific role**:
```json
{"role": "Crown"}
```

**Only when a field has a specific value**:
```json
{"field": "entity.status", "operator": "eq", "value": "Active"}
```

**Multiple roles allowed (OR)**:
```json
{
    "any": [
        {"role": "Crown"},
        {"role": "Steward"},
        {"permission": "canOverride"}
    ]
}
```

See `docs/workflow-engine.md` for the complete Condition DSL reference.

---

## Editing Actions

Actions are side-effects that execute during transitions or on state entry/exit.

### Modifying Email Recipients

Find the `send_email` action and change the `to` field:

```json
{
    "type": "send_email",
    "mailer": "Awards.Awards",
    "method": "notifyRequester",
    "to": "{{entity.member.email_address}}",
    "vars": {
        "status": "Approved"
    }
}
```

### Adding a Context Update

Track data across transitions:

```json
{
    "type": "set_context",
    "key": "last_reviewed_by",
    "value": "{{user_id}}"
}
```

### Adding a Webhook Notification

```json
{
    "type": "webhook",
    "url": "https://your-kingdom.example.com/api/workflow-event",
    "method": "POST",
    "payload": {
        "workflow": "award-recommendations",
        "entity_id": "{{entity_id}}",
        "new_state": "{{to_state.label}}"
    },
    "optional": true
}
```

Mark webhooks as `"optional": true` so that external service failures don't block
workflow transitions.

---

## Adding New States and Transitions

### Adding a New State

1. In the visual editor, click **Add State** (or use the API directly).
2. Fill in the properties:
   - **Name**: Internal identifier (e.g., "Under Review")
   - **Slug**: URL-safe identifier (e.g., "under-review") — must be unique
   - **Label**: What users see
   - **State Type**: Usually `intermediate`
   - **Status Category**: Grouping (e.g., "In Progress")
3. Click **Save**.

### Adding a New Transition

1. Click **Add Transition** or draw a line between two states.
2. Fill in:
   - **From State**: Source state
   - **To State**: Destination state
   - **Name/Slug/Label**: Identifiers
   - **Conditions**: Who can trigger this (JSON)
   - **Actions**: What happens (JSON array)
   - **Trigger Type**: `manual` for user-initiated
   - **Priority**: Lower = evaluated first for automatic transitions
3. Click **Save**.

### Example: Adding a "Needs Revision" Loop

To add a state where recommendations can be sent back for revision:

1. **Create state**: `Needs Revision` (slug: `needs-revision`, type: `intermediate`)
2. **Create transition**: `In Consideration → Needs Revision`
   - Condition: `{"permission": "canUpdateStates"}`
   - Trigger: `manual`
3. **Create transition**: `Needs Revision → In Consideration`
   - Condition: `{"ownership": "requester"}`
   - Trigger: `manual`

---

## Configuring Visibility Rules

Visibility rules control who can see entities in certain states.

### Making a State Visible Only to Admins

1. Click the state on the canvas.
2. In the property panel, go to **Visibility Rules**.
3. Add a rule:
   - **Rule Type**: `require_permission`
   - **Target**: `*` (whole entity)
   - **Condition**: `{"permission": "canViewHidden"}`
   - **Priority**: `10`

### Making Specific Fields Read-Only

1. Add a visibility rule:
   - **Rule Type**: `can_edit_field`
   - **Target**: `award_name` (the specific field)
   - **Condition**: `{"permission": "canEditAwards"}`
   - **Priority**: `10`

Users without `canEditAwards` permission won't be able to edit the `award_name`
field while the entity is in this state.

---

## Configuring Approval Gates

### Setting Up a Multi-Approver Gate

1. Click the approval-type state on the canvas.
2. In the property panel, go to **Approval Gates**.
3. Add a gate:
   - **Approval Type**: `threshold`
   - **Required Count**: `2` (how many approvals needed)
   - **Approver Rule**: `{"permission": "canApproveLevel"}`
   - **Timeout Hours**: `72` (optional, auto-escalate after 3 days)
   - **Allow Delegation**: `false`

### Approval Types

| Type | Behavior |
|------|----------|
| `threshold` | N approvals from any qualified approver |
| `unanimous` | All required approvers must approve; any denial blocks |
| `any_one` | Single approval from any qualified approver |
| `chain` | Sequential multi-level approval |

### Setting Required Count from App Settings

Use the `setting:` prefix in the approver rule to read the required count from
your kingdom's app settings:

```json
{
    "type": "setting",
    "key": "Warrant.RosterApprovalsRequired",
    "default": 2,
    "permission": "warrant.rosters.approve"
}
```

This lets you change the approval count in **Admin → Settings** without editing
the workflow.

---

## Testing Changes Before Publishing

### Step 1: Validate

Click the **Validate** button in the editor (or call the API):

```
POST /api/workflow-editor/definition/{id}/validate
```

The validator checks for:
- ✅ At least one initial state
- ✅ At least one terminal state
- ✅ All states reachable from initial state
- ⚠️ States with no incoming transitions
- ⚠️ Non-terminal states with no outgoing transitions

Fix all **errors** before publishing. **Warnings** are informational.

### Step 2: Export and Review

Export the definition to review offline:

```
GET /api/workflow-editor/definition/{id}/export
```

This returns the complete definition as JSON, which you can save as a backup.

### Step 3: Test in a Non-Production Environment

If possible, import the workflow definition into a staging environment first:

```
POST /api/workflow-editor/import
```

Test all transitions with the affected entity types before publishing to production.

### Step 4: Check Active Instances

Before publishing, check if there are active workflow instances. Existing instances
continue using the definition version they started with. New instances will use the
published version.

---

## Publishing a New Version

1. In the visual editor, click **Publish**.
2. This increments the version number and sets `is_active = true`.
3. New workflow instances will use the new version.
4. Existing active instances continue on their current version.

**Via API**:
```
POST /api/workflow-editor/definition/{id}/publish
```

Response:
```json
{
    "success": true,
    "definition": {
        "id": 1,
        "version": 3,
        "is_active": true
    }
}
```

---

## Importing and Exporting Definitions

### Exporting

```
GET /api/workflow-editor/definition/{id}/export
```

Returns the full workflow definition as JSON, including all states, transitions,
visibility rules, and approval gates. IDs and timestamps are stripped.

Save this JSON file as your backup or for sharing between kingdoms.

### Importing

```
POST /api/workflow-editor/import
Content-Type: application/json

{
    "name": "Award Recommendations",
    "slug": "award-recommendations-v2",
    "description": "...",
    "entity_type": "AwardsRecommendations",
    "workflow_states": [...],
    "workflow_transitions": [...]
}
```

The import:
1. Creates a new definition (version 1, `is_active = false`)
2. Creates all states with new IDs
3. Remaps transition `from_state_id` / `to_state_id` references
4. Does **not** automatically activate — you must review and publish

### Sharing Between Kingdoms

1. Export from Kingdom A
2. Modify the `slug` to avoid conflicts (e.g., `award-recommendations-kingdom-b`)
3. Import into Kingdom B
4. Review and customize conditions/actions for Kingdom B's permission structure
5. Validate and publish

---

## Rollback Procedures

### Scenario 1: Bad Publish — No Active Instances Yet

If you published a new version and no entities have started workflows on it:

1. Open the workflow definition in the editor.
2. The previous version is still in the database (same slug, lower version).
3. Set the current version's `is_active = false` via the API or database.
4. Set the previous version's `is_active = true`.

### Scenario 2: Active Instances on Bad Version

If entities have already started workflows on the problematic version:

1. **Do not** deactivate the current version (instances reference it).
2. Fix the issues in the current version via the editor.
3. Publish the fix as a new version.
4. Active instances remain on their version; only new instances get the fix.

### Scenario 3: Full Rollback via Export

If you exported the definition before making changes:

1. Import the exported JSON as a new definition.
2. Deactivate the problematic version.
3. Activate the imported version.

### Emergency: Direct Database Fix

In extreme cases, fix state/transition data directly:

```sql
-- Find the problematic definition
SELECT id, name, version, is_active
FROM workflow_definitions
WHERE slug = 'award-recommendations'
ORDER BY version DESC;

-- Deactivate the bad version
UPDATE workflow_definitions
SET is_active = 0
WHERE id = {bad_version_id};

-- Reactivate the previous good version
UPDATE workflow_definitions
SET is_active = 1
WHERE id = {good_version_id};
```

⚠️ **Warning**: Direct database changes bypass validation. Always validate
after manual fixes.

---

## Common Customization Scenarios

### Adding a New Permission Check to a Transition

1. Open the transition in the editor.
2. Change the condition from:
   ```json
   {"permission": "canUpdateStates"}
   ```
   To:
   ```json
   {
       "any": [
           {"permission": "canUpdateStates"},
           {"permission": "myKingdom.canManageRecommendations"}
       ]
   }
   ```
3. Save and publish.

### Requiring Approval Before a Transition

1. Change the destination state type to `approval`.
2. Add an approval gate to that state:
   - Type: `threshold`, Count: `1`
   - Approver rule: `{"permission": "canApproveLevel"}`
3. Add a transition from the approval state to the next state:
   - Condition: `{"approval_gate": "gate_id", "status": "met"}`

### Adding Email Notifications to a Transition

1. Open the transition in the editor.
2. Add to the `actions` array:
   ```json
   {
       "type": "send_email",
       "mailer": "Awards.Awards",
       "method": "notifyStateChange",
       "to": "{{entity.member.email_address}}",
       "vars": {
           "name": "{{entity.member.sca_name}}",
           "newState": "{{to_state.label}}"
       }
   }
   ```

### Changing the Number of Required Approvals

1. Open the state with the approval gate.
2. In the **Approval Gates** section, change `required_count`.
3. Or, if using a setting-based count, change the value in **Admin → Settings**
   (e.g., `Warrant.RosterApprovalsRequired`).

### Adding an Automatic Expiration Transition

1. Create a transition from the active state to an "Expired" terminal state.
2. Set:
   - **Trigger Type**: `scheduled`
   - **Is Automatic**: `true`
   - **Condition**:
     ```json
     {"field": "expires_on", "operator": "lt", "value": "{{now}}"}
     ```
   - **Trigger Config**:
     ```json
     {"check_field": "expires_on", "interval": "daily"}
     ```
3. Ensure the `bin/cake workflow process` cron job is running.
