# Workflow Engine — Ported Workflow Definitions

This document describes the four workflow definitions that were ported from KMP's
legacy hardcoded state management into the workflow engine. Each section includes
the state diagram, states, transitions, conditions, actions, visibility rules,
and approval gates.

**Seed migrations**: `config/Migrations/20260207020*.php`

---

## 1. Award Recommendations

**Definition slug**: `award-recommendations`
**Entity type**: `AwardsRecommendations`
**Plugin**: `Awards`
**Migration**: `config/Migrations/20260207020000_SeedRecommendationWorkflow.php`

### State Diagram

```
                     ┌─────────────────────────────────────────────────────┐
                     │              "In Progress" States                    │
                     │  (freely move between any pair)                     │
                     │                                                     │
                     │  ┌──────────┐  ┌────────────────┐  ┌────────────┐  │
                     │  │Submitted │  │In Consideration│  │ Awaiting   │  │
                     │  │(initial) │  │                │  │ Feedback   │  │
                     │  └──────────┘  └────────────────┘  └────────────┘  │
                     │  ┌──────────┐  ┌────────────────┐  ┌────────────┐  │
                     │  │Deferred  │  │ King Approved  │  │  Queen     │  │
                     │  │till Later│  │  [gate: 1]     │  │  Approved  │  │
                     │  └──────────┘  └────────────────┘  │  [gate: 1] │  │
                     │                                     └────────────┘  │
                     └────────────────────┬────────────────────────────────┘
                                          │
                                          ▼
                                ┌──────────────────┐
                                │ Need to Schedule  │
                                └────────┬─────────┘
                                  ▲      │
                                  │      ▼
                                ┌──────────────────┐     ┌──────────────────┐
                                │    Scheduled     │◄───►│Announced Not     │
                                └──────┬───────────┘     │   Given          │
                                       │                 └────────┬─────────┘
                                       │                          │
                                       ▼                          ▼
                                ┌──────────────────┐     ┌──────────────────┐
                                │     Given        │     │    No Action     │
                                │   (terminal)     │     │   (terminal)     │
                                └──────────────────┘     └──────────────────┘
```

### States

| State | Slug | Type | Category | Description |
|-------|------|------|----------|-------------|
| Submitted | `submitted` | initial | In Progress | New recommendation entry point |
| In Consideration | `in-consideration` | intermediate | In Progress | Being actively reviewed |
| Awaiting Feedback | `awaiting-feedback` | intermediate | In Progress | Waiting for additional information |
| Deferred till Later | `deferred-till-later` | intermediate | In Progress | Postponed for future consideration |
| King Approved | `king-approved` | intermediate | In Progress | Approved by the King |
| Queen Approved | `queen-approved` | intermediate | In Progress | Approved by the Queen |
| Need to Schedule | `need-to-schedule` | intermediate | Scheduling | Ready to be scheduled for giving |
| Scheduled | `scheduled` | intermediate | To Give | Scheduled for a specific event |
| Announced Not Given | `announced-not-given` | intermediate | To Give | Called into court but not given |
| Given | `given` | terminal | Closed | Award successfully given |
| No Action | `no-action` | terminal | Closed | Closed without giving the award |

### Transitions

**In Progress group**: All 6 states can transition freely to any other state within
the group (30 transitions). Each requires `canUpdateStates` permission.

**Forward flow**:

| From | To | Condition | Actions |
|------|----|-----------|---------|
| Any In Progress state | Need to Schedule | `{"permission": "canUpdateStates"}` | Log state |
| Need to Schedule | Scheduled | `{"permission": "canUpdateStates"}` | Log state |
| Scheduled | Announced Not Given | `{"permission": "canUpdateStates"}` | Log state |
| Announced Not Given | Scheduled | `{"permission": "canUpdateStates"}` | Log state (reschedule) |
| Need to Schedule | Any In Progress state | `{"permission": "canUpdateStates"}` | Log state (return) |

**Closing transitions**:

| From | To | Condition | Actions |
|------|----|-----------|---------|
| Scheduled | Given | `{"permission": "canUpdateStates"}` | Set `close_reason` = "Given" |
| Announced Not Given | Given | `{"permission": "canUpdateStates"}` | Set `close_reason` = "Given" |
| Any non-closed state | No Action | `{"permission": "canUpdateStates"}` | Log state |

### Visibility Rules

| State | Rule Type | Target | Condition |
|-------|-----------|--------|-----------|
| No Action | `require_permission` | `*` | `{"permission": "canViewHidden"}` |

Entities in "No Action" state are hidden from users without `canViewHidden` permission.

### Approval Gates

| State | Type | Required Count | Approver Rule |
|-------|------|----------------|---------------|
| King Approved | threshold | 1 | `{"type": "permission", "permission": "canApproveLevel"}` |
| Queen Approved | threshold | 1 | `{"type": "permission", "permission": "canApproveLevel"}` |

### State Metadata (UI Hints)

| State | Visible | Disabled | Required |
|-------|---------|----------|----------|
| Need to Schedule | planToGiveBlockTarget | domain, award, specialty, scaMember, branch | — |
| Scheduled | planToGiveBlockTarget | domain, award, specialty, scaMember, branch | planToGiveEventTarget |
| Given | planToGiveBlock, givenBlock | domain, award, specialty, scaMember, branch | planToGiveEvent, givenDate |
| No Action | closeReasonBlock, closeReason | domain, award, specialty, scaMember, branch, courtAvailability, callIntoCourt | closeReason |

### Legacy Mapping

The recommendation workflow was ported from the `Awards` plugin's
`RecommendationStatuses` and `RecommendationStateRules` configuration. The legacy
system used a `status` field with string values and permission checks scattered
across `RecommendationsController` methods.

---

## 2. Warrants (Two Workflows)

### 2a. Warrant Roster Approval

**Definition slug**: `warrant-roster`
**Entity type**: `WarrantRosters`
**Plugin**: None (core)
**Migration**: `config/Migrations/20260207020100_SeedWarrantWorkflow.php`

#### State Diagram

```
┌──────────────┐     approve     ┌──────────────┐
│   Pending    │────────────────►│   Approved   │
│   (start)    │                 │   (end)      │
│  [gate: 2]   │                 └──────────────┘
└──────┬───────┘
       │ decline
       ▼
┌──────────────┐
│   Declined   │
│   (end)      │
└──────────────┘
```

#### States

| State | Slug | Type | Description |
|-------|------|------|-------------|
| Pending | `pending` | start | Awaiting required approvals |
| Approved | `approved` | end | All approvals obtained; warrants ready |
| Declined | `declined` | end | At least one signer declined |

#### Transitions

| From | To | Slug | Condition | Actions |
|------|----|------|-----------|---------|
| Pending | Approved | `approve-roster` | `{"permission": "warrant.rosters.approve"}` | `activate_warrant` |
| Pending | Declined | `decline-roster` | `{"permission": "warrant.rosters.decline"}` | `cancel_warrant` (reason: "Warrant Roster Declined") |

#### Approval Gate

| State | Type | Count | Rule | Timeout |
|-------|------|-------|------|---------|
| Pending | threshold | 2 | `{"type": "setting", "key": "Warrant.RosterApprovalsRequired", "default": 2, "permission": "warrant.rosters.approve"}` | None |

The required approval count is read from the `Warrant.RosterApprovalsRequired`
app setting, defaulting to 2. This allows each kingdom to configure their own
approval threshold.

---

### 2b. Warrant Lifecycle

**Definition slug**: `warrant`
**Entity type**: `Warrants`
**Plugin**: None (core)
**Migration**: `config/Migrations/20260207020100_SeedWarrantWorkflow.php`

#### State Diagram

```
                    ┌──────────────┐
                    │   Upcoming   │
              ┌────►│              │
              │     └──────┬───────┘
              │            │ start_on <= now
              │            ▼
┌─────────────┐    ┌──────────────┐    ┌──────────────┐
│   Pending   │───►│   Current    │───►│   Expired    │
│   (start)   │    │              │    │   (end)      │
└──────┬──────┘    └──┬───┬───┬───┘    └──────────────┘
       │              │   │   │
       │              │   │   │ deactivate  ┌──────────────┐
       │              │   │   └────────────►│ Deactivated  │
       │              │   │                 │   (end)      │
       │              │   │                 └──────────────┘
       │              │   │ release
       │              │   │         ┌──────────────┐
       │              │   └────────►│  Released    │
       │              │             │   (end)      │
       │              │             └──────────────┘
       │              │ replace
       │              │         ┌──────────────┐
       │              └────────►│  Replaced    │
       │                        │   (end)      │
       │                        └──────────────┘
       │ cancel (roster declined)
       │            ┌──────────────┐
       ├───────────►│  Cancelled   │
       │            │   (end)      │
       │            └──────────────┘
       │ decline
       │            ┌──────────────┐
       └───────────►│  Declined    │
                    │   (end)      │
                    └──────────────┘
```

#### States

| State | Slug | Type | Category | On-Enter Actions |
|-------|------|------|----------|------------------|
| Pending | `pending` | start | Pending | — |
| Current | `current` | intermediate | Current | `set_field(approved_date, {{now}})` |
| Upcoming | `upcoming` | intermediate | Upcoming | — |
| Expired | `expired` | end | Expired | — |
| Deactivated | `deactivated` | end | Deactivated | — |
| Cancelled | `cancelled` | end | Cancelled | — |
| Declined | `declined` | end | Declined | — |
| Replaced | `replaced` | end | Replaced | — |
| Released | `released` | end | Released | — |

#### Transitions

| From | To | Slug | Trigger | Condition | Actions |
|------|----|------|---------|-----------|---------|
| Pending | Current | `activate` | automatic | `field(start_on <= now) AND roster_approved` | `activate_warrant` |
| Pending | Upcoming | `schedule` | automatic | `field(start_on > now) AND roster_approved` | — |
| Upcoming | Current | `start` | scheduled | `field(start_on <= now)` | `activate_warrant` |
| Current | Expired | `expire` | scheduled | `field(expires_on < now)` | — |
| Current | Deactivated | `deactivate` | manual | `permission(warrant.warrants.deactivate)` | `cancel_warrant(reason)` |
| Current | Released | `release` | manual | — (any user) | `cancel_warrant("Voluntarily released")` |
| Pending | Cancelled | `cancel` | automatic | `roster_declined` | `cancel_warrant("Warrant Roster Declined")` |
| Pending | Declined | `decline` | manual | `permission(warrant.warrants.declineWarrantInRoster)` | `cancel_warrant(reason)` |
| Current | Replaced | `replace` | automatic | `new_warrant_approved` | — |

#### Legacy Mapping

The warrant workflow was ported from `DefaultWarrantManager` and
`WarrantManagerInterface`. The legacy system managed status transitions
programmatically in the service layer with hardcoded state machine logic.

---

## 3. Activity Authorization

**Definition slug**: `activity-authorization`
**Entity type**: `Authorizations`
**Plugin**: `Activities`
**Migration**: `config/Migrations/20260207020200_SeedAuthorizationWorkflow.php`

### State Diagram

```
                                    expire (30 days)
                            ┌──────────────────────────────┐
                            │                              │
┌──────────────┐   approve  │  ┌──────────────┐           │
│   Pending    │───────────┼─►│   Approved   │           │
│   (initial)  │            │  │              │           │
│  [gate:chain]│            │  └──┬───┬───────┘           │
└──┬───┬───────┘            │     │   │                   │
   │   │                    │     │   │ revoke            │
   │   │                    │     │   ▼                   ▼
   │   │ deny               │     │  ┌──────────────┐  ┌──────────────┐
   │   └────────────────────┼─────┼─►│   Revoked    │  │   Expired    │
   │                        │     │  │  (terminal)  │  │  (terminal)  │
   │ retract                │     │  └──────────────┘  └──────────────┘
   │                        │     │
   ▼                        │     │ expire (expires_on)
┌──────────────┐            │     │
│  Retracted   │            │     └──────────────────────►(Expired)
│  (terminal)  │            │
└──────────────┘            │
                            │
                            └──────────────────────────────►(Expired)
```

### States

| State | Slug | Type | Category | On-Enter Actions |
|-------|------|------|----------|------------------|
| Pending | `pending` | initial | Pending | — |
| Approved | `approved` | intermediate | Approved | `grant_activity_role(grant)`, `send_email(notifyRequester, "Approved")` |
| Denied | `denied` | terminal | Denied | `grant_activity_role(revoke, "Denied")`, `send_email(notifyRequester, "Denied")` |
| Revoked | `revoked` | terminal | Revoked | `grant_activity_role(revoke, "Revoked")`, `send_email(notifyRequester, "Revoked")` |
| Expired | `expired` | terminal | Expired | — |
| Retracted | `retracted` | terminal | Retracted | — |

### Transitions

| From | To | Slug | Trigger | Condition |
|------|----|------|---------|-----------|
| Pending | Approved | `approve` | manual | `approval_gate_met` |
| Pending | Denied | `deny` | manual | `permission(canApproveActivityAuthorization)` |
| Pending | Retracted | `retract` | manual | `ownership(member_id)` — self-service |
| Pending | Expired | `expire-pending` | scheduled | `time: created > 30 days ago` |
| Approved | Expired | `expire-approved` | scheduled | `field(expires_on < now)` |
| Approved | Revoked | `revoke` | manual | `permission(canRevoke)` |

### Approval Gate

| State | Type | Count | Rule | Delegation |
|-------|------|-------|------|------------|
| Pending | chain | 1 | `{"type": "activity_config", "new_field": "num_required_authorizors", "renewal_field": "num_required_renewers", "permission": "canApproveActivityAuthorization"}` | Yes |

The chain-type gate supports sequential multi-level approval. The required count
is read from the activity type's configuration (`num_required_authorizors` for new
requests, `num_required_renewers` for renewals).

### Visibility Rules

| State | Rule Type | Condition |
|-------|-----------|-----------|
| Denied | `require_permission` | `{"permission": "canViewClosedAuthorizations"}` |
| Revoked | `require_permission` | `{"permission": "canViewClosedAuthorizations"}` |
| Expired | `require_permission` | `{"permission": "canViewClosedAuthorizations"}` |
| Retracted | `require_permission` | `{"permission": "canViewClosedAuthorizations"}` |

All terminal states are hidden from normal users; only those with
`canViewClosedAuthorizations` can see them in listings.

### Legacy Mapping

The authorization workflow was ported from the `Activities` plugin's controller-level
state management. The legacy system used `status` field updates in
`AuthorizationsController::approve()`, `deny()`, `revoke()` methods with inline
permission checks and email triggers.

---

## 4. Officer Assignment

**Definition slug**: `officer-assignment`
**Entity type**: `Officers.Officers`
**Plugin**: `Officers`
**Migration**: `config/Migrations/20260207020300_SeedOfficerWorkflow.php`

### State Diagram

```
┌──────────────┐   start_on <= now   ┌──────────────┐
│   Upcoming   │────────────────────►│   Current    │
│   (initial)  │                     │              │
└──────┬───────┘                     └──┬──┬──┬─────┘
       │                                │  │  │
       │ cancel (admin)                 │  │  │ expires_on <= now
       │                                │  │  │
       │                                │  │  ▼
       │                                │  │ ┌──────────────┐
       │                                │  │ │   Expired    │
       │                                │  │ │  (terminal)  │
       │                                │  │ └──────────────┘
       │                                │  │
       │                                │  │ release (admin)
       │                                │  │
       │                                │  ▼
       │                                │ ┌──────────────┐
       └───────────────────────────────►│ │  Released    │
                                        │ │  (terminal)  │
                                        │ └──────────────┘
                                        │
                                        │ replace (new officer assigned)
                                        │
                                        ▼
                                       ┌──────────────┐
                                       │  Replaced    │
                                       │  (terminal)  │
                                       └──────────────┘
```

### States

| State | Slug | Type | Category | On-Enter Actions |
|-------|------|------|----------|------------------|
| Upcoming | `upcoming` | initial | Upcoming | — |
| Current | `current` | intermediate | Current | `assign_officer_role(grant)`, `request_warrant` (if required), `send_email(notifyOfHire)` |
| Expired | `expired` | terminal | Expired | `assign_officer_role(revoke)`, `send_email(notifyOfRelease, "Term expired")` |
| Released | `released` | terminal | Released | `assign_officer_role(revoke)`, `send_email(notifyOfRelease, reason)` |
| Replaced | `replaced` | terminal | Replaced | `assign_officer_role(revoke)`, `send_email(notifyOfRelease, "Replaced by new officer")` |

### Transitions

| From | To | Slug | Trigger | Condition |
|------|----|------|---------|-----------|
| Upcoming | Current | `activate` | scheduled | `time(entity.start_on <= now)` |
| Current | Expired | `expire` | scheduled | `time(entity.expires_on <= now) AND field(entity.expires_on != null)` |
| Current | Released | `release` | manual | `permission(Officers.Officer.canRelease)` |
| Current | Replaced | `replace` | event | `field(entity.office.only_one_per_branch == true)` |
| Upcoming | Released | `cancel` | manual | `permission(Officers.Officer.canRelease)` |

### On-Enter Actions Detail

#### Current State Entry

When an officer enters the "Current" state, three actions fire:

1. **Grant role**: Assigns the office's role to the member at the branch level
   ```json
   {
       "type": "assign_officer_role",
       "params": {
           "operation": "grant",
           "officer_id": "{{entity.id}}",
           "member_id": "{{entity.member_id}}",
           "role_id": "{{entity.office.grants_role_id}}",
           "branch_id": "{{entity.branch_id}}"
       }
   }
   ```

2. **Request warrant** (conditional): Only if the office requires a warrant
   ```json
   {
       "type": "request_warrant",
       "condition": {"field": "entity.office.requires_warrant", "operator": "==", "value": true},
       "params": {
           "officer_id": "{{entity.id}}",
           "member_id": "{{entity.member_id}}",
           "office_id": "{{entity.office_id}}",
           "branch_id": "{{entity.branch_id}}"
       }
   }
   ```

3. **Send hire notification**:
   ```json
   {
       "type": "send_email",
       "params": {
           "mailer": "Officers.Officers",
           "method": "notifyOfHire",
           "to": "{{entity.member.email_address}}",
           "vars": {
               "memberScaName": "{{entity.member.sca_name}}",
               "officeName": "{{entity.office.name}}",
               "branchName": "{{entity.branch.name}}",
               "hireDate": "{{entity.start_on}}",
               "endDate": "{{entity.expires_on}}"
           }
       }
   }
   ```

#### Terminal State Entry (Expired/Released/Replaced)

All terminal states revoke the officer's role and send a release notification:

```json
[
    {
        "type": "assign_officer_role",
        "params": {
            "operation": "revoke",
            "officer_id": "{{entity.id}}",
            "member_id": "{{entity.member_id}}",
            "role_id": "{{entity.office.grants_role_id}}",
            "branch_id": "{{entity.branch_id}}"
        }
    },
    {
        "type": "send_email",
        "params": {
            "mailer": "Officers.Officers",
            "method": "notifyOfRelease",
            "to": "{{entity.member.email_address}}",
            "vars": {
                "memberScaName": "{{entity.member.sca_name}}",
                "officeName": "{{entity.office.name}}",
                "branchName": "{{entity.branch.name}}",
                "reason": "Term expired",
                "releaseDate": "{{entity.expires_on}}"
            }
        }
    }
]
```

The `reason` and `releaseDate` vars differ by terminal state:
- **Expired**: reason = "Term expired", date = `entity.expires_on`
- **Released**: reason = `transition.reason`, date = `transition.revoked_on`
- **Replaced**: reason = "Replaced by new officer", date = `transition.revoked_on`

### Legacy Mapping

The officer workflow was ported from the `Officers` plugin's
`OfficersController::add()`, `release()`, and `expire()` methods, plus the
`ActiveWindowBaseEntity` status constants (`Upcoming`, `Current`, `Expired`,
`Released`, `Replaced`). The legacy system computed status from date fields
and managed role grants/revocations inline in controller methods.

---

## Cross-Workflow Relationships

### Warrants ↔ Officers

When an officer enters the "Current" state and the office requires a warrant:

1. The `request_warrant` action creates a new warrant entity
2. The warrant starts in the `warrant` workflow at "Pending"
3. The warrant is added to a warrant roster
4. The `warrant-roster` workflow manages multi-signer approval
5. On roster approval, the warrant's `activate` automatic transition fires

### Recommendations → Awards

When a recommendation reaches "Given":

1. The `set_field` action sets `close_reason = "Given"`
2. The entity is marked as awarded
3. The recommendation workflow completes (terminal state)

### Scheduled Processing

All time-based transitions (expiration, activation) depend on the cron command:

```bash
bin/cake workflow process
```

This must run regularly (recommended: every 15 minutes) to process:
- Warrant expiration (`expires_on < now`)
- Upcoming warrant activation (`start_on <= now`)
- Officer activation (`start_on <= now`)
- Officer expiration (`expires_on <= now`)
- Authorization expiration (30-day pending timeout, `expires_on` for approved)
- Approval gate timeouts
