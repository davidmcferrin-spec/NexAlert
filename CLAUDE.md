# CLAUDE.md — NexAlert

## Project Overview
NexAlert is a self-hosted alert and mass notification platform for Nexstar/NewsNation broadcast operations.
Owner: David McFerrin, Director of Engineering, NewsNation.

## Architecture Decisions

### Tag Resolution
Alert targeting uses AND/OR expressions across four dimensions:
- `org:` — users in an org (any node)
- `node:` — users in an org_node subtree (materialized path query)
- `group:` — members of a group, resolved recursively via `group_children`
- `tag:` — users with a tag assignment in `tag_assignments`

Multiple `alert_targets` rows for one alert are unioned (OR). Fields within one row are ANDed.

### SMS Consent
All SMS sends must check `user_sms_consent.status = 'confirmed'` before dispatch.
STOP replies from Twilio set status to `stopped` immediately.
Do NOT retry SMS to `denied`, `stopped`, or `expired` users without explicit re-consent.

### Home Org
`users.home_org_id` is the administrative owner. It controls portal branding and which admin manages the user.
Entra OU→org mapping is in `entra_org_mappings`. If `users.home_override = 1`, sync must not change home_org_id.

### Group of Groups
`group_children` is a DAG. Application layer must detect cycles before insert.
Resolution at alert send time: recursive CTE or iterative BFS in the dispatch worker.

### Audit Log
`audit_log` is append-only. No UPDATE or DELETE ever. Enforce this at the DB user permission level.

## Stack Constraints
- PHP 8.2 / Apache — no Composer autoload (use manual requires), no Node.js
- Python 3.11 asyncio — workers only, no Django/Flask
- No Docker — bare service installs
- MySQL 8.0 — use utf8mb4_unicode_ci everywhere
- Redis — queues and pubsub only

## Current Phase
**Phase 1** — DB schema ✅, org/group/user CRUD 🔄

## Migration Naming
`/db/NNN_description.sql` — zero-padded 3 digits, e.g. `002_add_alert_templates.sql`

## Key Files
- `db/001_initial_schema.sql` — full schema, 32 tables
