# KMP Workflow Engine — Developer Documentation

## Overview

The Workflow Engine is a data-driven state machine system built into KMP. It replaces
hardcoded status transitions scattered across controllers and plugins with declarative
JSON definitions stored in the database. Any entity in KMP (recommendations, warrants,
authorizations, officers) can have its lifecycle managed by a workflow.

### Why It Exists

Before the workflow engine, each plugin implemented its own ad-hoc state management:
status columns updated directly, permission checks duplicated across methods, and email
notifications embedded in controller logic. The workflow engine centralizes all of this
into a single, auditable, extensible system.

### Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                        Application Layer                             │
│  Controllers / Services / CLI Commands                               │
│                          │                                           │
│                          ▼                                           │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │              WorkflowEngineInterface                           │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │            DefaultWorkflowEngine                         │  │  │
│  │  │                                                          │  │  │
│  │  │  startWorkflow()   transition()   getAvailableTransitions│  │  │
│  │  │  getInstanceForEntity()   getCurrentState()              │  │  │
│  │  │  processScheduledTransitions()                           │  │  │
│  │  │  registerConditionType()   registerActionType()          │  │  │
│  │  └──────────┬────────────────┬─────────────────┬────────────┘  │  │
│  │             │                │                 │               │  │
│  │             ▼                ▼                 ▼               │  │
│  │  ┌──────────────┐ ┌─────────────────┐ ┌───────────────────┐   │  │
│  │  │  RuleEval    │ │  ActionExecutor │ │ VisibilityEval    │   │  │
│  │  │  (Conditions)│ │  (Actions)      │ │ (Field/Entity     │   │  │
│  │  │  7 built-in  │ │  6 built-in     │ │  visibility)      │   │  │
│  │  │  + plugins   │ │  + plugins      │ │                   │   │  │
│  │  └──────────────┘ └─────────────────┘ └───────────────────┘   │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                          │                                           │
│                          ▼                                           │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │                   Database (8 tables)                          │  │
│  │  workflow_definitions  workflow_states  workflow_transitions    │  │
│  │  workflow_instances  workflow_transition_logs                  │  │
│  │  workflow_visibility_rules  workflow_approval_gates            │  │
│  │  workflow_approvals                                           │  │
│  └────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

### Key Files

| Component | Path |
|-----------|------|
| Engine interface | `src/Services/WorkflowEngine/WorkflowEngineInterface.php` |
| Engine implementation | `src/Services/WorkflowEngine/DefaultWorkflowEngine.php` |
| Rule evaluator | `src/Services/WorkflowEngine/DefaultRuleEvaluator.php` |
| Action executor | `src/Services/WorkflowEngine/DefaultActionExecutor.php` |
| Visibility evaluator | `src/Services/WorkflowEngine/DefaultVisibilityEvaluator.php` |
| Context resolver trait | `src/Services/WorkflowEngine/ContextResolverTrait.php` |
| Conditions | `src/Services/WorkflowEngine/Conditions/*.php` |
| Actions | `src/Services/WorkflowEngine/Actions/*.php` |
| API controller | `src/Controller/Api/WorkflowEditorController.php` |
| Admin controller | `src/Controller/WorkflowDefinitionsController.php` |
| CLI command | `src/Command/WorkflowProcessCommand.php` |
| DB migration | `config/Migrations/20260207010000_CreateWorkflowEngineTables.php` |
| Seed migrations | `config/Migrations/20260207020*.php` |
| JS editor controller | `assets/js/controllers/workflow-editor-controller.js` |
| JS property panel | `assets/js/controllers/workflow-property-panel-controller.js` |

---

## Core Concepts

### Definitions

A **Workflow Definition** is the blueprint for a workflow. It has a `slug` (unique
identifier), an `entity_type` it governs (e.g., `AwardsRecommendations`), a `version`
number, and flags for `is_active` and `is_default`. Each definition owns a set of
states and transitions.

### States

A **State** represents a stage in the entity lifecycle. Each state has:

- `state_type`: one of `initial`, `intermediate`, `approval`, or `terminal`
- `status_category`: grouping label for the visual editor (e.g., "In Progress", "Closed")
- `on_enter_actions`: JSON array of actions to run when entering this state
- `on_exit_actions`: JSON array of actions to run when leaving this state
- `metadata`: JSON object for UI hints (visible fields, disabled fields, required fields)
- `position_x` / `position_y`: canvas coordinates for the visual editor

### Transitions

A **Transition** connects two states (from → to). Each transition has:

- `conditions`: JSON condition DSL that must evaluate to `true` for the transition
- `actions`: JSON array of actions to execute during the transition
- `trigger_type`: `manual` (user-initiated), `automatic` (condition-met),
  `scheduled` (cron-checked), or `event` (domain event)
- `priority`: lower number = higher priority; only one auto-transition fires per
  cron run
- `trigger_config`: JSON configuration for automatic/scheduled triggers

### Instances

A **Workflow Instance** is a running workflow tied to a specific entity. It tracks
`current_state_id`, `previous_state_id`, a `context` JSON bag for accumulated data,
and `completed_at` (null while active).

The `entity_type` + `entity_id` pair is unique — one active workflow per entity.

### Conditions

Conditions are JSON objects evaluated by the `DefaultRuleEvaluator`. They control
whether a transition is available to a user. See the [Condition DSL](#condition-dsl)
section below.

### Actions

Actions are JSON objects executed by the `DefaultActionExecutor` during transitions
and on state entry/exit. They produce side effects: updating fields, sending emails,
calling webhooks. See the [Action DSL](#action-dsl) section below.

### Visibility Rules

Stored in `workflow_visibility_rules`, these control which users can view or edit
entities in a given state. Rule types include:

| `rule_type` | Purpose |
|-------------|---------|
| `can_view_entity` | Whether the entity is visible in listings |
| `can_edit_entity` | Whether the entity is editable |
| `can_view_field` | Whether a specific field is visible |
| `can_edit_field` | Whether a specific field is editable |
| `require_permission` | Require a permission to see entities in this state |

The `target` column specifies which field the rule applies to (`*` for entity-level).
The `condition` column contains a condition DSL expression. Rules are priority-ordered;
first match wins.

### Approval Gates

Stored in `workflow_approval_gates`, these require multiple approvals before a
transition can proceed. Configuration:

| Field | Description |
|-------|-------------|
| `approval_type` | `threshold`, `unanimous`, `any_one`, or `chain` |
| `required_count` | Number of approvals needed |
| `approver_rule` | JSON condition defining who can approve |
| `timeout_hours` | Optional automatic timeout |
| `timeout_transition_id` | Transition to fire on timeout |
| `allow_delegation` | Whether approvers can delegate |

**Approval types:**

- **threshold**: N approvals required (any qualified approver)
- **unanimous**: All required approvers must approve; any denial blocks
- **any_one**: Single approval from any qualified approver suffices
- **chain**: Sequential multi-level approval (e.g., authorization chains)

---

## Data Model

### 1. `workflow_definitions`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `name` | varchar(255) | Human-readable name |
| `slug` | varchar(255) | URL-safe identifier (unique per version) |
| `description` | text | Optional description |
| `entity_type` | varchar(255) | CakePHP table name (e.g., `AwardsRecommendations`) |
| `plugin_name` | varchar(255) | Owning plugin (nullable) |
| `version` | int | Incremented on publish |
| `is_active` | bool | Whether this version is in use |
| `is_default` | bool | Default workflow for the entity type |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

### 2. `workflow_states`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_definition_id` | int (FK) | Parent definition |
| `name` | varchar(255) | Internal name |
| `slug` | varchar(255) | Unique within definition |
| `label` | varchar(255) | Display label |
| `description` | text | Optional |
| `state_type` | varchar(50) | `initial`, `intermediate`, `approval`, `terminal` |
| `status_category` | varchar(255) | Visual grouping |
| `metadata` | text (JSON) | UI hints: visible/disabled/required fields |
| `position_x` / `position_y` | int | Visual editor canvas position |
| `on_enter_actions` | text (JSON) | Actions on entering state |
| `on_exit_actions` | text (JSON) | Actions on leaving state |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

### 3. `workflow_transitions`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_definition_id` | int (FK) | Parent definition |
| `from_state_id` | int (FK) | Source state |
| `to_state_id` | int (FK) | Target state |
| `name` | varchar(255) | Internal name |
| `slug` | varchar(255) | Unique within definition |
| `label` | varchar(255) | Button/link label |
| `description` | text | Optional |
| `priority` | int | Order for auto-transition evaluation |
| `conditions` | text (JSON) | Condition DSL |
| `actions` | text (JSON) | Action DSL array |
| `is_automatic` | bool | Whether the transition fires automatically |
| `trigger_type` | varchar(50) | `manual`, `automatic`, `scheduled`, `event` |
| `trigger_config` | text (JSON) | Trigger-specific config |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

### 4. `workflow_instances`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_definition_id` | int (FK) | Which workflow definition |
| `entity_type` | varchar(255) | Entity table name |
| `entity_id` | int | Entity primary key |
| `current_state_id` | int (FK) | Current state |
| `previous_state_id` | int (FK) | Previous state (nullable) |
| `context` | text (JSON) | Accumulated workflow data |
| `started_at` | datetime | When the workflow started |
| `completed_at` | datetime | Null until terminal state |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

**Unique constraint**: `(entity_type, entity_id)` — one active instance per entity.

### 5. `workflow_transition_logs`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_instance_id` | int (FK) | Instance this log belongs to |
| `from_state_id` | int (FK) | Previous state (null for initial) |
| `to_state_id` | int (FK) | New state |
| `transition_id` | int (FK) | Transition used (nullable) |
| `triggered_by` | int | User ID who triggered (null for system) |
| `trigger_type` | varchar(50) | How the transition was triggered |
| `context_snapshot` | text (JSON) | Instance context at time of transition |
| `notes` | text | Optional notes |
| `created` | datetime | Timestamp |

### 6. `workflow_visibility_rules`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_state_id` | int (FK) | State this rule applies to |
| `rule_type` | varchar(50) | Type of visibility rule |
| `target` | varchar(255) | `*` for entity-level or field name |
| `condition` | text (JSON) | Condition DSL |
| `priority` | int | Higher priority evaluated first |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

### 7. `workflow_approval_gates`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_state_id` | int (FK) | State this gate guards |
| `approval_type` | varchar(50) | `threshold`, `unanimous`, `any_one`, `chain` |
| `required_count` | int | Number of approvals needed |
| `approver_rule` | text (JSON) | Who can approve |
| `timeout_hours` | int | Auto-timeout (nullable) |
| `timeout_transition_id` | int (FK) | Transition on timeout (nullable) |
| `allow_delegation` | bool | Whether delegation is allowed |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

### 8. `workflow_approvals`

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `workflow_instance_id` | int (FK) | Instance being approved |
| `approval_gate_id` | int (FK) | Which gate |
| `approver_id` | int | Who approved (nullable until decided) |
| `decision` | varchar(50) | `approved`, `denied`, or null (pending) |
| `notes` | text | Approver notes |
| `token` | varchar(255) | Unique token for email-based approval |
| `requested_at` | datetime | When approval was requested |
| `responded_at` | datetime | When decision was made |
| `created` / `modified` | datetime | Timestamps |
| `created_by` / `modified_by` | int | Audit trail |

---

## Condition DSL

Conditions are JSON objects stored in `workflow_transitions.conditions`,
`workflow_visibility_rules.condition`, and `workflow_approval_gates.approver_rule`.

The `DefaultRuleEvaluator` (`src/Services/WorkflowEngine/DefaultRuleEvaluator.php`)
parses and evaluates them against a runtime context array.

### Condition Types

#### `permission` — Check user permission

```json
{"type": "permission", "permission": "canUpdateStates"}
```

Shorthand (type auto-detected):
```json
{"permission": "canUpdateStates"}
```

**Implementation**: `src/Services/WorkflowEngine/Conditions/PermissionCondition.php`
Checks `context['user_permissions']` array for an exact match.

#### `role` — Check user role

```json
{"type": "role", "role": "Crown"}
```

Shorthand:
```json
{"role": "Crown"}
```

**Implementation**: `src/Services/WorkflowEngine/Conditions/RoleCondition.php`
Checks `context['user_roles']` array.

#### `field` — Compare entity field value

```json
{
    "type": "field",
    "field": "entity.expires_on",
    "operator": "<",
    "value": "{{now}}"
}
```

Shorthand:
```json
{"field": "status", "operator": "eq", "value": "Active"}
```

**Operators**: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `is_set`,
`is_empty`, `contains`, `starts_with`, `ends_with`

**Implementation**: `src/Services/WorkflowEngine/Conditions/FieldCondition.php`
Resolves dot-notation paths from context root, falling back to `entity.*` prefix.

#### `ownership` — Check user-entity relationship

```json
{"type": "ownership", "ownership": "requester"}
```

Shorthand:
```json
{"ownership": "requester"}
```

**Ownership types**:
- `requester` — user is `entity.requester_id` or `entity.created_by`
- `recipient` — user is `entity.member_id`
- `parent_of_minor` — user manages the entity's member (guardian relationship)
- `any` — matches any of the above

**Implementation**: `src/Services/WorkflowEngine/Conditions/OwnershipCondition.php`

#### `approval_gate` — Check approval gate status

```json
{"type": "approval_gate", "approval_gate": "gate_id", "status": "met"}
```

Shorthand:
```json
{"approval_gate": "gate_id", "status": "met"}
```

**Status values**: `met` or `not_met`

**Implementation**: `src/Services/WorkflowEngine/Conditions/ApprovalGateCondition.php`
Reads from `context['approval_gates']` which is populated automatically for approval-type states.

#### `time` — Time-based conditions

**State duration** — how long the instance has been in the current state:
```json
{
    "type": "time",
    "time": "state_duration",
    "operator": "gt",
    "value": 48,
    "unit": "hours"
}
```

**Field date comparison** — compare an entity date field to now or a fixed date:
```json
{
    "type": "time",
    "time": "field_date",
    "field": "expires_on",
    "operator": "lt",
    "value": "now"
}
```

**Units**: `seconds`, `minutes`, `hours`, `days`

**Implementation**: `src/Services/WorkflowEngine/Conditions/TimeCondition.php`

#### `workflow_context` — Check instance context data

```json
{
    "type": "workflow_context",
    "workflow_context": "approval_count",
    "operator": "gte",
    "value": 3
}
```

Shorthand:
```json
{"workflow_context": "approval_count", "operator": "gte", "value": 3}
```

**Operators**: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`

**Implementation**: `src/Services/WorkflowEngine/Conditions/WorkflowContextCondition.php`
Reads from `context['instance']['context']`.

### Boolean Combinators

#### `all` — AND (all conditions must be true)

```json
{
    "all": [
        {"permission": "canUpdateStates"},
        {"field": "status", "operator": "eq", "value": "Active"}
    ]
}
```

#### `any` — OR (at least one condition must be true)

```json
{
    "any": [
        {"role": "Crown"},
        {"role": "Steward"},
        {"permission": "canUpdateStates"}
    ]
}
```

#### `not` — Negation

```json
{
    "not": {"ownership": "requester"}
}
```

#### Nesting

Combinators nest arbitrarily:

```json
{
    "all": [
        {"permission": "canApproveLevel"},
        {
            "not": {"ownership": "requester"}
        },
        {
            "any": [
                {"role": "Crown"},
                {"role": "Steward"}
            ]
        }
    ]
}
```

### Type Detection

If no `"type"` key is present, the evaluator auto-detects based on the first
recognized key: `permission`, `role`, `field`, `ownership`, `approval_gate`, `time`,
`workflow_context`. Unrecognized conditions evaluate to `false` (fail-closed).

---

## Action DSL

Actions are JSON arrays stored in `workflow_transitions.actions`,
`workflow_states.on_enter_actions`, and `workflow_states.on_exit_actions`.

Each action object requires a `type` key. An optional `"optional": true` flag means
the action's failure won't block the transition.

The `DefaultActionExecutor` (`src/Services/WorkflowEngine/DefaultActionExecutor.php`)
processes them sequentially, stopping on the first non-optional failure.

### Action Types

#### `set_field` — Set an entity field value

```json
{
    "type": "set_field",
    "field": "close_reason",
    "value": "Given"
}
```

With template variable:
```json
{
    "type": "set_field",
    "field": "approved_date",
    "value": "{{now}}"
}
```

**Implementation**: `src/Services/WorkflowEngine/Actions/SetFieldAction.php`
Resolves template variables, then saves the entity field via the ORM.

#### `send_email` — Queue an email notification

```json
{
    "type": "send_email",
    "mailer": "Officers.Officers",
    "method": "notifyOfHire",
    "to": "{{entity.member.email_address}}",
    "vars": {
        "memberScaName": "{{entity.member.sca_name}}",
        "officeName": "{{entity.office.name}}",
        "branchName": "{{entity.branch.name}}"
    }
}
```

**Parameters**:
- `mailer` (required): CakePHP mailer class path
- `method` (required): Mailer method to invoke
- `to` (optional): Recipient address or context path
- `vars` (optional): Extra template variables passed to the mailer

**Implementation**: `src/Services/WorkflowEngine/Actions/SendEmailAction.php`

#### `set_context` — Store data in instance context

```json
{
    "type": "set_context",
    "key": "approved_by",
    "value": "{{user_id}}"
}
```

**Special values**:
- `{{now}}` — current datetime (stored as ISO-8601 string)
- `{{increment}}` — increment current numeric value by 1

```json
{
    "type": "set_context",
    "key": "approval_count",
    "value": "{{increment}}"
}
```

**Implementation**: `src/Services/WorkflowEngine/Actions/SetContextAction.php`
Context updates are merged into the instance's `context` JSON after the transition.

#### `webhook` — Fire-and-forget HTTP request

```json
{
    "type": "webhook",
    "url": "https://example.com/api/notify",
    "method": "POST",
    "payload": {
        "entity_id": "{{entity_id}}",
        "state": "{{to_state.name}}",
        "triggered_by": "{{user_id}}"
    }
}
```

**Parameters**:
- `url` (required): Webhook URL (supports templates)
- `method` (optional): `GET`, `POST`, `PUT` (default: `POST`)
- `payload` (optional): JSON payload with template variables

**Implementation**: `src/Services/WorkflowEngine/Actions/WebhookAction.php`
Uses CakePHP's `Cake\Http\Client` with 10-second timeout. Failures are logged
but can be marked `"optional": true` to avoid blocking the transition.

#### `activate_warrant` — Activate a warrant entity

```json
{
    "type": "activate_warrant",
    "params": {}
}
```

Sets status to "Current", adjusts `approved_date` and `start_on` fields.

**Implementation**: `src/Services/WorkflowEngine/Actions/ActivateWarrantAction.php`

#### `cancel_warrant` — Cancel/deactivate a warrant

```json
{
    "type": "cancel_warrant",
    "params": {
        "reason": "{{context.reason}}"
    }
}
```

Sets `revoked_reason`, `revoker_id`, snaps `expires_on` to now.

**Implementation**: `src/Services/WorkflowEngine/Actions/CancelWarrantAction.php`

### Optional Actions

Add `"optional": true` to prevent action failures from blocking the transition:

```json
{
    "type": "webhook",
    "url": "https://external-system.example.com/notify",
    "optional": true
}
```

---

## Template Variables

Template variables use `{{path}}` syntax and are resolved by the
`ContextResolverTrait` (`src/Services/WorkflowEngine/ContextResolverTrait.php`).

### Available Variables

| Variable | Resolves To |
|----------|-------------|
| `{{now}}` | Current `DateTime` object |
| `{{increment}}` | Special: increment counter by 1 (set_context only) |
| `{{user_id}}` | ID of the user who triggered the transition |
| `{{entity_id}}` | Entity primary key |
| `{{entity_type}}` | Entity table name |
| `{{entity.field}}` | Dot-notation path into entity data |
| `{{entity.member.email_address}}` | Nested entity relation |
| `{{instance.context.key}}` | Workflow instance context data |
| `{{state.name}}` | Current state name |
| `{{transition.slug}}` | Current transition slug |
| `{{from_state.name}}` | From state (during transitions) |
| `{{to_state.name}}` | To state (during transitions) |
| `{{setting:Key.Name}}` | App setting via `StaticHelpers::getAppSetting()` |

### Inline Interpolation

When a template appears inside a larger string, the resolved value is cast to string:

```json
"Warrant for {{entity.member.sca_name}} has been {{to_state.label}}"
```

### Full Replacement

When the entire value is a template (`{{path}}`), the resolved value preserves its
original type (DateTime, int, array, etc.).

---

## Plugin Extension

Plugins register custom condition and action types through the workflow engine's
extension API.

### Registering Custom Conditions

In your plugin's bootstrap or service registration:

```php
// In Plugin.php bootstrap() or a service provider
$workflowEngine = $container->get(WorkflowEngineInterface::class);
$workflowEngine->registerConditionType('my_custom_check', new MyCustomCondition());
```

Your condition must implement `ConditionInterface`:

```php
namespace MyPlugin\Services\WorkflowEngine\Conditions;

use App\Services\WorkflowEngine\Conditions\ConditionInterface;

class MyCustomCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        // Your logic here
        return true;
    }

    public function getName(): string
    {
        return 'my_custom_check';
    }

    public function getDescription(): string
    {
        return 'Checks something specific to my plugin';
    }

    public function getParameterSchema(): array
    {
        return [
            'my_param' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Description for visual editor',
            ],
        ];
    }
}
```

### Registering Custom Actions

```php
$workflowEngine->registerActionType('grant_activity_role', new GrantActivityRoleAction());
```

Your action must implement `ActionInterface`:

```php
namespace MyPlugin\Services\WorkflowEngine\Actions;

use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\ServiceResult;

class GrantActivityRoleAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        // Your side-effect logic here
        return new ServiceResult(true, null, ['details' => '...']);
    }

    public function getName(): string
    {
        return 'grant_activity_role';
    }

    public function getDescription(): string
    {
        return 'Grants or revokes an activity role for the member';
    }

    public function getParameterSchema(): array
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['grant', 'revoke'],
            ],
        ];
    }
}
```

### Using ContextResolverTrait

If your action needs to resolve `{{template}}` variables from context, use the trait:

```php
use App\Services\WorkflowEngine\ContextResolverTrait;

class MyAction implements ActionInterface
{
    use ContextResolverTrait;

    public function execute(array $params, array $context): ServiceResult
    {
        $recipientEmail = $this->resolveValue($params['to'], $context);
        // ...
    }
}
```

---

## Service Registration

The workflow engine is registered in `src/Application.php` via CakePHP's DI container:

```php
// src/Application.php — services() method

$container->add(RuleEvaluatorInterface::class, DefaultRuleEvaluator::class);
$container->add(ActionExecutorInterface::class, DefaultActionExecutor::class);
$container->add(VisibilityEvaluatorInterface::class, DefaultVisibilityEvaluator::class);
$container->add(WorkflowEngineInterface::class, DefaultWorkflowEngine::class)
    ->addArgument(RuleEvaluatorInterface::class)
    ->addArgument(ActionExecutorInterface::class)
    ->addArgument(VisibilityEvaluatorInterface::class);
```

To use the workflow engine in a controller or service, inject the interface:

```php
class MyController extends AppController
{
    protected WorkflowEngineInterface $workflowEngine;

    public function initialize(): void
    {
        parent::initialize();
        // DI container injects automatically, or resolve manually:
        // $this->workflowEngine = $container->get(WorkflowEngineInterface::class);
    }
}
```

---

## Visual Editor

### Accessing the Editor

Navigate to **Admin → Workflow Engine** or directly:

- **List all definitions**: `/workflow-engine`
- **Create new definition**: `/workflow-engine/create`
- **Open editor**: `/workflow-engine/editor/{id}`

The editor is a canvas-based Stimulus.js application with two controllers:

- `workflow-editor-controller.js` — Main canvas with drag-and-drop states
  and transition arrows
- `workflow-property-panel-controller.js` — Side panel for editing selected
  state/transition properties, conditions, actions, visibility rules, and
  approval gates

### Editor Features

- Drag states on the canvas to rearrange
- Click a state to edit its properties in the side panel
- Draw transitions by connecting states
- Edit condition DSL as structured JSON
- Edit actions as structured JSON arrays
- Validate the workflow (checks for initial/terminal states, reachability, dead ends)
- Publish a new version
- Export/import workflow definitions as JSON

---

## CLI Command

### `bin/cake workflow process`

Processes scheduled and automatic workflow transitions for all active instances.
Should be run on a regular cron schedule.

```bash
# Process all scheduled transitions
bin/cake workflow process

# Dry run — show what would be processed
bin/cake workflow process --dry-run
```

**What it does**:

1. Finds all active (non-completed) workflow instances
2. For each instance, checks for `automatic` and `scheduled` transitions
   from the current state
3. Evaluates conditions; fires the first matching transition (priority order)
4. Processes approval gate timeouts (gates with `timeout_hours` set)
5. Reports count of processed transitions and any errors

**Recommended cron schedule**:

```cron
# Run every 15 minutes
*/15 * * * * cd /path/to/app && bin/cake workflow process >> /var/log/kmp/workflow.log 2>&1
```

---

## API Endpoints

All endpoints are JSON and require authentication. Routes are defined in
`config/routes.php` under the `/api/workflow-editor` scope.

### Definition Endpoints

| Method | Path | Action | Description |
|--------|------|--------|-------------|
| `GET` | `/api/workflow-editor/definition/{id}` | `getDefinition` | Fetch definition with all states, transitions, rules, gates |
| `PUT` | `/api/workflow-editor/definition/{id}` | `saveDefinition` | Update definition metadata |
| `POST` | `/api/workflow-editor/definition/{id}/publish` | `publishDefinition` | Publish (increment version, set active) |
| `POST` | `/api/workflow-editor/definition/{id}/validate` | `validateDefinition` | Validate workflow structure |
| `GET` | `/api/workflow-editor/definition/{id}/export` | `exportDefinition` | Export as JSON |
| `POST` | `/api/workflow-editor/import` | `importDefinition` | Import from JSON |

### State Endpoints

| Method | Path | Action | Description |
|--------|------|--------|-------------|
| `POST` | `/api/workflow-editor/states` | `createState` | Create a new state |
| `PUT` | `/api/workflow-editor/states/{id}` | `updateState` | Update state properties |
| `DELETE` | `/api/workflow-editor/states/{id}` | `deleteState` | Delete state (must have no transitions) |

### Transition Endpoints

| Method | Path | Action | Description |
|--------|------|--------|-------------|
| `POST` | `/api/workflow-editor/transitions` | `createTransition` | Create a new transition |
| `PUT` | `/api/workflow-editor/transitions/{id}` | `updateTransition` | Update transition properties |
| `DELETE` | `/api/workflow-editor/transitions/{id}` | `deleteTransition` | Delete a transition |

### Visibility Rule Endpoints

| Method | Path | Action | Description |
|--------|------|--------|-------------|
| `POST` | `/api/workflow-editor/visibility-rules` | `saveVisibilityRules` | Create or update a visibility rule |

### Approval Gate Endpoints

| Method | Path | Action | Description |
|--------|------|--------|-------------|
| `POST` | `/api/workflow-editor/approval-gates` | `saveApprovalGate` | Create or update an approval gate |
| `DELETE` | `/api/workflow-editor/approval-gates/{id}` | `deleteApprovalGate` | Delete an approval gate |

### Validation Response

The `validateDefinition` endpoint returns:

```json
{
    "isValid": true,
    "errors": [],
    "warnings": ["State 'Deferred' has no incoming transitions."]
}
```

**Validation checks**:
- At least one `initial` state exists
- At least one `terminal` state exists
- All states are reachable from initial states
- No orphan states (non-initial states with no incoming transitions)
- No dead-end states (non-terminal states with no outgoing transitions)

---

## Runtime Context

When conditions and actions are evaluated, the engine builds a context array
(see `DefaultWorkflowEngine::buildContext()`):

```php
[
    'user_id' => 42,
    'user_permissions' => ['canUpdateStates', 'canApproveLevel', ...],
    'user_roles' => ['Crown', 'Admin', ...],
    'entity_type' => 'AwardsRecommendations',
    'entity_id' => 123,
    'entity' => [...],          // Entity as array
    'entity_object' => $entity, // Entity object (for ORM saves)
    'entity_table' => $table,   // Table object (for ORM saves)
    'instance' => [...],        // Workflow instance as array
    'instance_context' => [...], // Parsed instance context JSON
    'state' => [...],           // Current state as array
    'state_entered_at' => DateTime,
    'approval_gates' => [...],  // Gate status (approval states only)
    'transition' => [...],      // Current transition (during transitions)
    'from_state' => [...],      // (during transition actions)
    'to_state' => [...],        // (during transition actions)
]
```

---

## Error Handling

- **Fail-closed conditions**: Unknown condition types return `false`
- **Transaction safety**: Transitions run inside a database transaction; failures roll back
- **Optional actions**: Actions marked `"optional": true` log failures without blocking
- **Logging**: All transition actions and errors are logged via `Cake\Log\Log`
- **Audit trail**: Every transition is recorded in `workflow_transition_logs`
