# NexAlert — Cursor Development Handoff Prompt

Paste this entire prompt at the start of a new Cursor session to onboard the AI
to the NexAlert project and continue development.

---

## Context

You are continuing development of **NexAlert**, a self-hosted mass notification
platform for Nexstar Media Group / NewsNation broadcast operations. This is a
production codebase, not a prototype. Read all referenced files before writing
any code.

**Repo:** `github.com/davidmcferrin-spec/NexAlert`  
**Live URL:** `https://nexalert.area51consulting.com`  
**Admin URL:** `https://nexalert.area51consulting.com/admin`  
**Full spec:** `project_summary.md` — read this first

---

## Working Rules

1. **Read before writing.** Always read the relevant source files before making changes.
2. **Follow existing patterns.** PHP 8.4, custom PSR-4 autoloader, no Composer, no Node.js, no Docker. Frontend: PHP templates + Tailwind CDN + Alpine.js.
3. **One class per file.** Autoloader maps `NexAlert\Foo\Bar` → `api/src/Foo/Bar.php`.
4. **Never run SELECT 1 inside `Database::pdo()`.** Use `Database::ensureConnected()` in workers only.
5. **Backtick `groups`.** MySQL reserved word.
6. **Audit log is append-only.** Use `AuditService::log()` only.
7. **Check SMS consent before every send.** `user_sms_consent.status = 'confirmed'`.
8. **Dreamhost constraints.** Webroot at repo root. MySQL `jobs` queue (no Redis on dev VPS).
9. **Materialized paths** for org tree (`org_nodes.path`).
10. **Complete working code only.** No placeholders or skeleton functions.

---

## Current State (as of 2026-06-27)

### What is working and deployed

**Phase 1 — Admin & identity**
- MySQL schema + migrations through **011**
- Local auth, JWT, system tokens, rate limiter (fails open without Redis)
- Org/user/group/tag CRUD API + admin UI
- User import, roles panel, tag approval requests
- Email templates: password reset, verify, SMS opt-in, alert notification
- Audit log, system tokens UI

**Phase 2 — Alert pipeline** ✅
- Alert create API + composer UI (expression + `target_tree` from Test Send)
- AST target engine: `TargetAstService`, `TargetExpressionService`, Test Send builder
- Dispatch worker: email + SMS, ack escalation, scheduled send, alert TTL expiry
- Poll responses: signed email links, profile/API voting, results in history modal
- Per-recipient delivery drill-down in alert history
- SMS opt-in flow (create/import, profile, Twilio webhook STOP/YES)
- Dashboard stats API
- Site-wide UI tooltips (`web/helpers/ui.php`)
- External trigger: `POST /api/v1/alert` (system token)

**Phase 3 — Web Push** 🔄
- VAPID subscription API + profile UI (`WebPushService`, `/sw.js`)
- `push_web` channel in composer; dispatch via `pywebpush` in worker
- Migration **011**: `push_subscription_id` on deliveries
- `in_app` channel: instant delivery record (profile alerts list)

**Phase 4 — Chat** 🔄
- `chat` / `group_chat` alert types in composer
- `ChatService`: thread messages, visibility rules, close thread
- Profile reply UI; admin history shows thread
- SMS inbound replies routed to open chat thread (after consent keywords)

### What needs to be built next

**Phase 3 remaining**
- Push delivery monitoring / 410 cleanup polish
- Optional: originator push when chat SMS reply arrives

**Phase 4 remaining**
- Real-time updates (WebSocket bridge) — currently poll-on-open
- Originator web reply UI in admin (not just profile)
- SMS outbound notifications to originator on new reply
- `push_fcm` (Phase 7)

**Phase 5+**
- Azure Entra OIDC + LDAP + directory sync
- Alert template library
- Azure production migration

---

## Dispatch Worker

```bash
pip install pymysql twilio pywebpush
python workers/dispatch.py
```

Job types: `dispatch_alert`, `ack_escalate`, `alert_expire`, `sms_optin`

**Deploy migrations:**
```bash
php migrate.php --status
php migrate.php --migrate-only   # through 011
```

**VAPID keys:**
```bash
php scripts/generate_vapid.php
# Add output to .env, restart worker
```

---

## File Locations Quick Reference

```
api/routes.php                      ALL API routes
api/src/Services/AlertService.php   Alert create, deliveries, TTL
api/src/Services/PollService.php    Poll votes + results
api/src/Services/WebPushService.php Push subscription management
api/src/Services/ChatService.php    Chat threads + SMS routing
api/src/Services/TargetAstService.php      Expression AST / DNF
workers/dispatch.py                 Email, SMS, push, jobs
sw.js                               Web Push service worker
web/templates/pages/profile/        User self-service + push + chat
web/templates/pages/alerts/         Composer + history
app.php                             Web frontend routes
db/011_alert_delivery_push.sql      Push delivery FK
scripts/generate_vapid.php          VAPID key generator
CLAUDE.md                           AI session notes
project_summary.md                  Full spec
```

---

## Design Decisions (Do Not Revisit)

- No Composer, Node.js, Docker, or PHP framework
- Materialized paths for org tree
- Multi-row AND/OR targeting via `alert_targets` + AST (`db/007` conj_terms)
- SMS consent separate from contacts
- One Entra tenant, multiple NexAlert orgs (Phase 5)
- Alpine.js `api` helper uses JWT from localStorage / session

---

## Start Here

1. `project_summary.md`
2. `CLAUDE.md`
3. `api/routes.php`
4. `workers/dispatch.py` (if touching delivery)
5. Confirm task scope with David before large changes
