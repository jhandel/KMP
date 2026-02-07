# Workflow Engine

## Overview

KMP includes a centralized workflow engine built on top of `symfony/workflow` v7.4. It manages state machines for entity lifecycle workflows (authorizations, recommendations, warrants, etc.) with configurable states, transitions, approval gates, conditions, and actions.

## Architecture

### Core Components (in `app/src/Services/WorkflowEngine/`)

| Component | Purpose |
|-----------|---------|
| `DefaultWorkflowEngine` | Main engine — starts workflows, applies transitions, manages instances |
| `WorkflowBridge` | Adapter between KMP's DB-driven definitions and symfony/workflow |
| `CakeOrmMarkingStore` | Stores current state in CakePHP entities |
| `ApprovalGateService` | Multi-approver gate logic (chain, parallel, voting) |
| `DefaultRuleEvaluator` | Evaluates guard conditions on transitions |
| `DefaultActionExecutor` | Executes on-enter/on-exit actions for states |

### Core Action Classes (in `app/src/Services/WorkflowEngine/Actions/`)

These are **generic, domain-agnostic** actions available to all workflows:

- `SetFieldAction` — Sets a field on the subject entity
- `SetContextAction` — Updates the workflow context
- `SendEmailAction` — Queues email notifications
- `WebhookAction` — HTTP callback to external URLs
- `RequestApprovalAction` — Creates approval records

### Database Tables

All prefixed with `workflow_`:
- `workflow_definitions` — Workflow metadata (name, slug, entity_type, plugin_name)
- `workflow_states` — States with type, category, metadata, on_enter/exit actions
- `workflow_transitions` — Edges between states with conditions and triggers
- `workflow_instances` — Active/completed workflow instances per entity
- `workflow_approval_gates` — Multi-approver gate configuration per state
- `workflow_approval_gate_approvals` — Individual approval records
- `workflow_conditions` — Guard conditions on transitions
- `workflow_condition_rules` — Rule definitions within conditions

## Plugin Integration

### Plugin-Specific Code Belongs in the Plugin

Each plugin owns its workflow-related code:

| What | Where |
|------|-------|
| Workflow seed migrations | `plugins/PluginName/config/Migrations/` |
| Workflow templates (JSON) | `plugins/PluginName/config/WorkflowTemplates/` |
| Custom action classes | `plugins/PluginName/src/Services/WorkflowEngine/Actions/` |
| Action/condition registration | `plugins/PluginName/src/PluginNamePlugin.php::services()` |

**Do NOT** place plugin-specific workflow seeds or actions in the core `app/config/Migrations/` or `app/src/Services/WorkflowEngine/Actions/` directories.

### Registering Plugin Actions

Plugins register custom workflow actions via the DI container in their `services()` method:

```php
// In PluginNamePlugin.php::services()
$container->extend(WorkflowEngineInterface::class)
    ->addMethodCall('registerActionType', [
        'my_custom_action',
        function (array $params, array $context) use ($action) {
            return $action->execute($params, $context);
        },
    ]);
```

### Example: Activities Plugin Structure

```
plugins/Activities/
├── config/
│   ├── Migrations/
│   │   ├── 20260207050400_SeedActivityAuthorizationWorkflow.php
│   │   └── 20260207060000_SeedActivityAuthorizationApprovalGates.php
│   └── WorkflowTemplates/
│       └── activity-authorization.json
└── src/
    └── Services/
        └── WorkflowEngine/
            └── Actions/
                ├── GrantActivityRoleAction.php
                ├── RevokeActivityRoleAction.php
                ├── SendAuthorizationNotificationAction.php
                └── SendApprovalTokenAction.php
```

### Migration Ordering

CakePHP runs migrations in order: **core first**, then plugins by `migrationOrder` from `config/plugins.php`. Since the core `CreateWorkflowEngineTables` migration creates the schema, plugin seed migrations that INSERT into workflow tables will always run after the tables exist.

## Approval Gates

Approval gates enable multi-approver workflows with configurable thresholds:

- **Chain**: Sequential unique approvers (e.g., 2 marshals must approve in sequence)
- **Parallel**: Concurrent approvals (any N of M)
- **Voting**: Majority/unanimous decision

### Dynamic Thresholds

The `threshold_config` supports conditional resolution from entity fields:

```json
{
    "type": "conditional_entity_field",
    "condition_field": "is_renewal",
    "when_true": {"field": "activity.num_required_renewers"},
    "when_false": {"field": "activity.num_required_authorizors"},
    "default": 2
}
```

This is fully generic — the engine resolves field values via dot-notation without knowing anything about the domain entity.

## Querying Gate Status

Use the domain service's `getApprovalGateStatus()` method (not raw entity fields) to determine approval progress for UI rendering:

```php
$status = $authManager->getApprovalGateStatus($authorizationId);
// Returns: {has_gate, approved_count, required_count, has_more_approvals, satisfied}
```

## Testing

All workflow engine tests are in `tests/TestCase/Services/WorkflowEngine/`:

```bash
vendor/bin/phpunit tests/TestCase/Services/WorkflowEngine/
# 230 tests, 503 assertions
```
