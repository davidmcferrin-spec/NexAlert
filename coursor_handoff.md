# NexAlert — Cursor Development Handoff Prompt

Paste this entire prompt at the start of a new Cursor session to onboard the AI
to the NexAlert project and continue development from where Phase 1 left off.

---

## Context

You are continuing development of **NexAlert**, a self-hosted mass notification
platform for Nexstar Media Group / NewsNation broadcast operations. This is a
production codebase, not a prototype. Read all referenced files before writing
any code.

**Repo:** `github.com/davidmcferrin-spec/NexAlert`  
**Live URL:** `https://nexalert.area51consulting.com`  
**Admin URL:** `https://nexalert.area51consulting.com/admin`  
**Full spec:** `docs/PROJECT_SUMMARY.md` — read this first, it is authoritative

---

## Working Rules

1. **Read before writing.** Always read the relevant source files before making
   changes. Never guess at existing function signatures, table names, or class
   locations.

2. **Follow existing patterns.** This codebase uses PHP 8.4 with a custom PSR-4
   autoloader (`api/autoload.php`), no Composer, no Node.js, no Docker.
   Frontend is PHP templates + Tailwind CSS (CDN) + Alpine.js. Do not introduce
   any new dependency without explicit approval.

3. **One class per file.** The autoloader maps `NexAlert\Foo\Bar` to
   `api/src/Foo/Bar.php` exactly. Two classes in one file will silently break
   autoloading.

4. **Never run SELECT 1 inside `Database::pdo()`.** The current implementation
   correctly omits a ping query — adding one back resets `lastInsertId()` to 0,
   breaking all insert operations. Use `Database::ensureConnected()` in
   long-running workers only.

5. **Backtick `groups`.** It is a MySQL reserved word. Every query touching the
   `groups` table must use `` `groups` ``.

6. **Audit log is append-only.** Never UPDATE or DELETE from `audit_log`.
   Use `AuditService::log()` for all writes.

7. **Check SMS consent before every send.** `user_sms_consent.status` must be
   `confirmed` before any Twilio send. Check at dispatch time, not import time.

8. **Dreamhost constraints.** Webroot is `/home/dh_w9tij7/NexAlert/` (no
   `public/` subdirectory). Authorization header requires the `.htaccess`
   passthrough rule already in place. PHP ini overrides go in `.user.ini`.
   No Redis available on the dev VPS — use MySQL queue table fallback.

9. **Materialized paths.** `org_nodes.path` stores `/org_id/node_id/.../`.
   Subtree queries use `path LIKE '/1/3/%'`. When moving a node, update the
   path for all descendants too (see `NodeController::move()`).

10. **Complete working code only.** No placeholders, no TODO comments, no
    skeleton functions. Every function must be fully implemented.

---

## Current State (as of 2026-06-27)

### What is working and deployed
- MySQL schema + migrations through `008_alert_escalation.sql`
- Local auth, JWT, system tokens, rate limiter (fails open without Redis)
- Full org/user/group/tag CRUD API + admin UI
- Alert create API, dispatch worker (`workers/dispatch.py`), ack escalation jobs
- AST target engine: `TargetAstService`, `TargetExpressionService`, Test Send UI
- Alert composer with target_tree handoff from Test Send
- Dashboard stats API (`GET /api/v1/dashboard/stats`)
- Site-wide UI tooltips via `web/helpers/ui.php`
- Admin web UI: all major sections live including audit log and tokens

### What needs to be built next (Phase 2 completion)

**Priority 1 — Phase 2 remaining:**

1. **Email templates** (`api/src/Templates/mail/`)
   - `password_reset.php`, `email_verify.php`, `sms_optin_notice.php`

2. **Poll alert delivery** — inbound response handling and results UI

3. **Scheduled alerts** — `send_at` future scheduling in dispatch worker

4. **Delivery detail view** — per-alert recipient/delivery status drill-down

**Previously completed (Phase 1–2):**
- Group management API + UI ✅
- Tag management UI ✅
- Alert composer UI ✅ (with target_tree wiring)
- Test Send target builder ✅
- Ack escalation worker ✅
- Dashboard live stats ✅

### Phase 2 dispatch worker notes

- `workers/dispatch.py` polls MySQL `jobs` queue (`queue = 'dispatch'`)
- Job types: `dispatch_alert`, `ack_escalate`, `sms_optin`
- After all deliveries sent, schedules `ack_escalate` if ack deadline + escalation user set
- Requires migration **008** for `ack_deadline_at` / `escalated_at` columns

**Deploy checklist for new VPS features:**
```bash
mysql ... < db/007_alert_target_conj_terms.sql   # if not done
mysql ... < db/008_alert_escalation.sql
python workers/dispatch.py   # or systemd service
```

---

## Original Phase 1 backlog (archived — mostly done)

<details>
<summary>Click to expand historical Phase 1 tasks</summary>

1. **Email templates** — still needed
2. **Group management** — ✅ done
3. **Tag admin UI** — ✅ done
4. **User import UI** — ✅ done
5. **MySQL jobs queue** — ✅ `db/004_jobs_queue.sql`
6. **AlertService + dispatch** — ✅ done
7. **Target preview API** — ✅ done
8. **Alert composer UI** — ✅ done with target_tree
9. **Twilio SMS opt-in flow** — partial (webhook + worker sms_optin)

</details>

---

## File Locations Quick Reference

```
api/autoload.php                    PSR-4 autoloader
api/routes.php                      ALL API routes — add new routes here
api/src/Api/Request.php             HTTP request value object
api/src/Api/Response.php            JSON response helpers (success/error/etc.)
api/src/Api/Router.php              Lightweight regex router
api/src/Config/Database.php         PDO singleton — DO NOT add ping to pdo()
api/src/Config/Env.php              .env loader
api/src/Config/Logger.php           Structured JSON logger
api/src/Controllers/               One file per controller
api/src/Middleware/                 Auth, rate limit, system token
api/src/Services/AuditService.php   Append-only audit log writer
api/src/Services/JwtService.php     HS256 JWT issue/decode
api/src/Services/MailService.php    PHPMailer wrapper — needs Templates/
api/src/Services/TagService.php     Tag inheritance, target resolution, group BFS
api/src/Services/TargetAstService.php      Expression parser, DNF, target_tree
api/src/Services/TargetExpressionService.php  Preview + compile pipeline
api/src/Services/JobQueueService.php    MySQL job queue push helpers
api/src/Controllers/DashboardController.php  Dashboard stats endpoint
web/helpers/ui.php                      Tooltip helpers (tip_attr, tip_label)
workers/dispatch.py                   Dispatch + ack escalation worker
db/007_alert_target_conj_terms.sql    Multi-tag AND conj_terms column
db/008_alert_escalation.sql           ack_deadline_at, escalated_at
web/templates/layouts/admin.php     Sidebar layout, Alpine app shell, dark/light
web/templates/pages/                One directory per section
app.php                             Web frontend entry point + route table
index.php                           API entry point
.htaccess                           Routing + Authorization header passthrough
db/001_initial_schema.sql           Full 32-table schema
docs/PROJECT_SUMMARY.md             Full spec (authoritative)
CLAUDE.md                           AI session notes
```

---

## Design Decisions Already Made (Do Not Revisit)

- No Composer, no Node.js, no Docker, no framework
- Materialized paths for org tree (not nested sets, not adjacency list with CTEs)
- Multi-row AND/OR targeting in `alert_targets`, compiled from expression AST or `target_tree` JSON
- `TargetAstService` normalizes expressions to DNF; `conj_terms` JSON for multi-tag AND (`db/007`)
- SMS consent is a separate table from contacts
- `groups` is backticked everywhere
- One Entra tenant, multiple NexAlert orgs (not per-org Entra tenants)
- Scoped tags resolved via AND of dimensions (tag + org membership = NewsNation:Engineering)
- No ping in `Database::pdo()` — workers use `ensureConnected()` instead
- Alpine.js `api` helper in layout uses `localStorage` for JWT token storage
- Dark/light theme persisted in `localStorage` under key `nexalert_dark`

---

## Start Here

Read these files in order before writing any code:

1. `docs/PROJECT_SUMMARY.md`
2. `CLAUDE.md`
3. `db/001_initial_schema.sql` (skim table names and key columns)
4. `api/routes.php` (understand what's wired)
5. `api/src/Controllers/UserController.php` (pattern to follow for new controllers)
6. `web/templates/layouts/admin.php` (Alpine app shell, `api` helper, toast/modal system)
7. `web/templates/pages/users/index.php` (Alpine page pattern to follow)

Then ask: **"What should I build first?"** and David will direct you to the
specific task. Do not start coding until you have read the above files and
confirmed the task scope.