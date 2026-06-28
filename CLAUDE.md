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
- PHP 8.2+ / Apache — no Composer autoload (use manual requires), no Node.js
- Python 3.11 — dispatch worker only (`workers/dispatch.py`)
- No Docker — bare service installs
- MySQL 8.0 — use utf8mb4_unicode_ci everywhere
- Redis — queues and pubsub only (optional; MySQL `jobs` table used on Dreamhost)

## Current Phase
**Phase 2** ✅ complete — email/SMS dispatch, ack escalation, polls, TTL, scheduled send, AST targeting, admin UI  
**Phase 3** 🔄 started — Web Push (VAPID) subscription + dispatch  
**Phase 4** 🔄 started — `chat` / `group_chat` threads, SMS reply routing

## Target Expression Engine
- `TargetAstService` — parse expressions, normalize to DNF, support nested `target_tree` JSON
- `TargetExpressionService` — preview, compile, multi-tag AND via `conj_terms` JSON (`db/007`)
- Test Send UI — nested OR → AND branch builder; handoff to alert composer via sessionStorage

## Alert Pipeline (Phase 2+)
- `AlertService::create()` — resolves targets, builds deliveries, enqueues dispatch
- `workers/dispatch.py` — job types: `dispatch_alert`, `ack_escalate`, `alert_expire`, `sms_optin`
- Poll votes: HMAC signed links (`PollService`), public `/poll/vote`, profile + API voting
- TTL: `expires_at` set when send completes; `alert_expire` job skips queued deliveries
- Scheduled: `send_at` → status `scheduled`; worker releases due alerts

## Web Push (Phase 3)
- `WebPushService` — subscription CRUD; VAPID public key via `GET /api/v1/profile/push/vapid-key`
- `push_subscriptions` table; deliveries use `push_subscription_id` (`db/011`)
- Service worker: `/sw.js`; dispatch via `pywebpush` in worker
- Generate keys: `php scripts/generate_vapid.php`

## Chat (Phase 4)
- `chat_threads` + `chat_messages` per alert (`chat` vs `group_chat` visibility rules in `ChatService`)
- API: `GET/POST /api/v1/alerts/{id}/chat/messages`, `POST .../chat/close`
- Profile UI: reply inline; SMS inbound routed via `WebhookController` → `ChatService::handleInboundSms`

## Migration Naming
`/db/NNN_description.sql` — zero-padded 3 digits, run via `php migrate.php --migrate-only`

## Key Files
- `db/001_initial_schema.sql` — full schema
- `api/routes.php` — all API routes
- `app.php` — web frontend route table
- `workers/dispatch.py` — dispatch + escalation + expire + push
- `web/helpers/ui.php` — tooltip helpers

## Deployment Checklist
1. `php migrate.php --migrate-only` (through **011**)
2. Fill `.env`: DB, SMTP, APP_SECRET, Twilio, VAPID keys
3. `pip install pymysql twilio pywebpush`
4. Run `python workers/dispatch.py` (systemd recommended)
5. Test: health, login, send test alert, enable push on profile
