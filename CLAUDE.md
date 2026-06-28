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
**Phase 2** — Alert send pipeline ✅, dispatch worker ✅, ack escalation ✅, AST targeting ✅, admin UI 🔄

## Target Expression Engine (Phase 2)
- `TargetAstService` — parse expressions, normalize to DNF, support nested `target_tree` JSON
- `TargetExpressionService` — preview, compile, multi-tag AND via `conj_terms` JSON (`db/007`)
- Test Send UI — nested OR → AND branch → OR subgroup builder
- Alert composer accepts `targets` (expression) and/or `target_tree` from sessionStorage handoff

## Ack Escalation (Phase 2)
- `ack_deadline_at`, `escalated_at` on alerts (`db/008`)
- Dispatch worker schedules `ack_escalate` job when alert fully sent with deadline + escalation user
- Escalation email lists unacked recipients to `escalation_user_id`

## Migration Naming
`/db/NNN_description.sql` — zero-padded 3 digits, e.g. `002_add_alert_templates.sql`

## Key Files
- `db/001_initial_schema.sql` — full schema, 32 tables

## Bootstrap Files Added (Phase 1 Session 2)

### Entry Points
- `public/index.php` — API entry point (all /api/* requests)
- `public/app.php`   — Web frontend entry point (placeholder, Phase 1 next)

### Core Infrastructure
- `api/autoload.php`              — PSR-4 autoloader, no Composer needed
- `api/src/Config/Env.php`        — .env parser with typed getters
- `api/src/Config/Database.php`   — PDO singleton with retry, transaction helper, inClause()
- `api/src/Config/Logger.php`     — Structured JSON logger
- `api/src/Api/Router.php`        — Regex router with middleware chain, group support
- `api/src/Api/RequestResponse.php` — Request value object + Response helper

### Auth Layer
- `api/src/Services/JwtService.php`          — HS256 JWT, access + refresh tokens
- `api/src/Middleware/AuthMiddleware.php`     — JWT validation, permission check
- `api/src/Middleware/SystemTokenMiddleware.php` — External system token (CheckMK, XPression)
- `api/src/Middleware/RateLimitMiddleware.php`   — Redis sliding window rate limiter
- `api/src/Controllers/AuthController.php`   — login, logout, refresh, password reset

### Services
- `api/src/Services/AuditService.php` — append-only audit_log writer
- `api/src/Services/MailService.php`  — PHPMailer wrapper (password reset, verify, SMS notice)

### Config
- `config/.env.example` — all required vars documented
- `.htaccess`           — Dreamhost rewrite rules, security headers, SSL redirect
- `.user.ini`           — PHP-FPM overrides for Dreamhost
- `api/routes.php`      — all route definitions

## Deployment Checklist (First Deploy)
1. SSH to VPS, `git clone` repo to `/home/dh_w9tij7/nexalert.area51consulting.com/`
2. `cp config/.env.example .env` → fill in DB, SMTP, APP_SECRET
3. Create MySQL DB in Dreamhost panel, note host/user/pass
4. `mysql -h <host> -u <user> -p <db> < db/001_initial_schema.sql`
5. Create log dir: `mkdir -p ~/logs/nexalert`
6. Enable Let's Encrypt in Dreamhost panel for `nexalert.area51consulting.com`
7. Test: `curl https://nexalert.area51consulting.com/api/v1/health`
8. Create first super_admin user (SQL INSERT - admin tool coming next session)
