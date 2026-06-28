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

## Current State (as of 2026-06-28)

### What is working and deployed
- MySQL schema (32 tables, `db/001_initial_schema.sql`)
- Local auth: login, logout, token refresh, password reset request
- JWT middleware, system token middleware, rate limiter (fails open without Redis)
- Org CRUD API + org tree node CRUD API (including move with path recalculation)
- User CRUD API + CSV import + membership management + tag assignment
- TagService: auto-inherit tags from org tree, resolve alert targets, BFS group expansion
- AuditService, MailService stub (needs email templates), JwtService
- Admin web UI: login, dashboard, orgs page with tree builder, users list and form
- `.htaccess`, `.user.ini`, `api/autoload.php`, `api/routes.php`

### What needs to be built next (Phase 1 completion)

**Priority 1 — Complete Phase 1:**

1. **Email templates** (`api/src/Templates/mail/`)
   - `password_reset.php`
   - `email_verify.php`
   - `sms_optin_notice.php`
   - Simple responsive HTML email layout. Use inline CSS only (no external sheets).
   - Variables injected via PHP `extract()` — see `MailService::renderTemplate()`.

2. **Group management** — API controller + UI
   - `GroupController.php` covering: list, create, update, delete, add member,
     remove member, add child group, remove child group
   - Routes in `api/routes.php` under `/api/v1/groups`
   - Web pages: `/admin/groups` (list + member management)
   - Cycle detection before any `group_children` insert (BFS check)

3. **Tag management** — standalone admin page
   - `/admin/tags` — list all tags, create tag, edit tag, toggle exclusive flag,
     set tag admin, view pending approval requests, approve/deny requests
   - API: `GET/POST /api/v1/tags`, `GET/PUT/DELETE /api/v1/tags/{id}`,
     `GET /api/v1/tags/{id}/requests`, `POST /api/v1/tags/{id}/requests/{rid}/approve|deny`

4. **CSV Import UI**
   - `/admin/users/import` page — file upload form, org selector, column mapping preview
   - Posts to `POST /api/v1/users/import` (already implemented)
   - Show result summary (created/skipped/errors) after import

5. **User self-service portal** (separate from admin)
   - `/profile` — view/edit own contact info
   - `/profile/verify-email?token=...` — email verification flow
   - `/profile/contacts` — add/remove email and phone contacts
   - `/profile/sms-optin` — trigger SMS opt-in request
   - `/profile/notifications` — per-severity channel preferences

**Priority 2 — Phase 2 (Alert Engine):**

6. **Inbound alert API** — `POST /api/v1/alert`
   - Accepts system token auth (already implemented in `SystemTokenMiddleware`)
   - Validates severity against token's `allowed_severity` SET
   - Resolves `alert_targets` to user list via `TagService::resolveTargets()`
   - Creates `alerts` record + `alert_deliveries` records
   - Pushes job to dispatch queue (MySQL `jobs` table — Redis not available)

7. **Dispatch worker** (`workers/dispatch.py`)
   - Python 3.11 asyncio
   - Polls MySQL `jobs` table every 2 seconds
   - For each delivery: check SMS consent, check user prefs, send via appropriate channel
   - Email: call PHP script or use `smtplib` directly
   - SMS: Twilio REST API (`pip install twilio`)
   - Updates `alert_deliveries.status` + `sent_at`
   - Writes audit log entry per delivery

8. **MySQL jobs queue table** (new migration `db/002_jobs_queue.sql`)
   ```sql
   CREATE TABLE jobs (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     queue VARCHAR(50) NOT NULL DEFAULT 'default',
     payload JSON NOT NULL,
     attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
     status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
     available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     processed_at DATETIME NULL,
     failed_at DATETIME NULL,
     error TEXT NULL,
     INDEX idx_queue_status (queue, status, available_at)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```

9. **Alert composer UI** (`/admin/alerts/new`)
   - Target builder: add rows combining org/node/group/tag/user dimensions
   - Channel selector, severity selector, alert type selector
   - Preview recipient count before send
   - For `ack_required`: set deadline + escalation user
   - For `poll`: define options

10. **Twilio SMS opt-in flow**
    - On user creation/import with a phone: queue an opt-in invite email
    - Dispatch worker sends Twilio opt-in SMS after email is confirmed sent
    - Inbound webhook: `POST /api/v1/webhooks/twilio/sms`
      - Parse YES/NO/STOP replies
      - Update `user_sms_consent.status`
      - Route chat replies to correct thread (Phase 4)

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
api/src/Templates/mail/             Email templates (DOES NOT EXIST YET)
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
- Multi-row AND/OR targeting model in `alert_targets` (not a DSL/expression parser)
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