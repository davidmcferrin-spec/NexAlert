# NexAlert — Project Summary & Development Handoff
**Last updated:** 2026-06-28  
**Status:** Phase 1 ~80% complete, live on Dreamhost VPS

---

## 1. What NexAlert Is

NexAlert is a self-hosted mass notification and alert management platform for
Nexstar Media Group / NewsNation broadcast operations. It is purpose-built to
replace commercial solutions like AlertMedia and Everbridge with a system
fully owned and operated by the engineering team.

**Primary use cases:**
- System alerts (video path down, encoder failure, CheckMK/XPression triggers)
- Staff notices (schedule changes, operational updates)
- Safety alerts, warnings, and evacuation notices
- Test messages for compliance verification

**Inbound alert trigger:** External systems POST alerts via secure token REST API.
Operators also compose alerts manually via the admin web UI.

---

## 2. Core Requirements

### Multi-Channel Delivery
| Channel | Provider | Phase |
|---|---|---|
| Email | PHPMailer + SMTP (Dreamhost relay) | 2 |
| SMS | Twilio Programmable SMS | 2 |
| Web Push | VAPID (no app store required) | 3 |
| In-App | WebSocket real-time feed | 4 |
| FCM Push | Firebase (PWA/mobile) | 7 |

### Alert Types
| Type | Behavior |
|---|---|
| `simple` | Fire and forget |
| `ack_required` | Must acknowledge; escalates on TTL expiry |
| `poll` | Vote with custom options; results visible to sender |
| `chat` | Recipients reply to originator only |
| `group_chat` | Full thread; all recipients see all replies |

### Severity Levels
`test` → `info` → `notice` → `warning` → `critical` → `evacuation`
Evacuation always sends all channels regardless of user preferences.

### SMS Compliance
Twilio A2P 10DLC requires explicit opt-in. Full lifecycle:
`pending → invite_sent → opt_in_sent → confirmed | denied | expired | stopped`
Every SMS dispatch must check `user_sms_consent.status = 'confirmed'`.
STOP replies permanently flip to `stopped` until user re-initiates.

---

## 3. Organization & User Model

### Org Tree
Hierarchical tree using **materialized paths** (`/org_id/node_id/.../`) for O(1) subtree queries.

```
Organization
  └── org_nodes: org → region → market → site → department → team
        └── user_org_memberships
```

- Users have a **home org** for branding, admin ownership, and scope
- Users can belong to **multiple org trees simultaneously**
- `users.home_override = 1` prevents Entra sync from overwriting manual placements

### Tag System
Tags are a first-class targeting entity.

- **Auto/Inherited:** When added to a node, user gets system tags for every ancestor in their path
- **Manual:** Explicitly assigned (e.g. `Transmission`, `On-Call`)
- **Exclusive:** Require tag owner or super_admin — users can request but not self-assign
- Approval states: `pending → approved | denied`

### Alert Targeting
`alert_targets` rows: each row ANDs non-null fields; multiple rows ORed at resolve time.

Dimensions: `org:`, `node:` (subtree), `group:` (recursive BFS), `tag:`, `user:`

Example: `(org:NewsNation AND tag:Engineering) OR (group:NOC) OR (user:42)`

### Groups
Independent of org tree, can span orgs. `group_children` enables groups-of-groups.
**Cycle detection enforced at application layer** before any `group_children` insert.

---

## 4. Authentication

| Provider | Status | Notes |
|---|---|---|
| Local (bcrypt) | ✅ Live | Password reset via email token |
| Azure Entra OIDC | Phase 5 | One tenant, multiple NexAlert orgs |
| LDAP | Phase 5 | Secondary/legacy |

RBAC roles: `super_admin`, `org_admin`, `group_admin`, `sender`, `recipient`
Assignments scoped: global, per-org, or per-org-node.

---

## 5. Tech Stack

| Layer | Tech |
|---|---|
| Frontend | PHP 8.4, Tailwind CSS (CDN), Alpine.js 3.x — no build pipeline |
| API | PHP 8.4, Apache 2.4, custom PSR-4 autoloader — no Composer |
| Workers | Python 3.11 asyncio (Phase 2+) |
| Queue | MySQL table (Phase 1/2); Redis when available |
| Database | MySQL 8.0.41, utf8mb4_unicode_ci |
| SMS | Twilio |
| Email | PHPMailer + SMTP |
| Hosting (dev) | Dreamhost VPS, Ubuntu 24.04, PHP-FPM/FastCGI |
| Hosting (prod) | Azure App Service + Azure MySQL Flexible (Phase 7) |

**Hard constraints:** No Node.js. No Docker. No Composer.

### Dreamhost-Specific
- Webroot: `/home/dh_w9tij7/NexAlert/` (NOT a `public/` subdirectory)
- All routing via `.htaccess` mod_rewrite
- Authorization header requires: `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
- SSL via Dreamhost panel (Let's Encrypt)
- PHP ini overrides in `.user.ini` at webroot
- `Database::pdo()` must NOT ping — pinging resets `lastInsertId()` to 0

---

## 6. Database

32 tables in `db/001_initial_schema.sql`. Key notes:

- `org_nodes.path` is materialized path for subtree queries
- `` `groups` `` is a MySQL reserved word — always backtick it
- `audit_log` is **append-only** — never UPDATE or DELETE
- `user_sms_consent` is separate from `user_contacts` for independent audit trail
- `alert_targets` uses multi-row AND/OR targeting model
- `Database::pdo()` does NOT ping — use `ensureConnected()` in workers only

---

## 7. Implemented API Endpoints

```
POST   /api/v1/auth/login|logout|refresh|forgot-password|reset-password
GET    /api/v1/health
GET    /api/v1/health/deep          (auth required)

GET    /api/v1/orgs
POST   /api/v1/orgs
GET    /api/v1/orgs/{id}
PUT    /api/v1/orgs/{id}
DELETE /api/v1/orgs/{id}

GET    /api/v1/orgs/{org_id}/nodes
POST   /api/v1/orgs/{org_id}/nodes
GET    /api/v1/orgs/{org_id}/nodes/{id}
PUT    /api/v1/orgs/{org_id}/nodes/{id}
DELETE /api/v1/orgs/{org_id}/nodes/{id}
PUT    /api/v1/orgs/{org_id}/nodes/{id}/move

GET    /api/v1/users
POST   /api/v1/users
POST   /api/v1/users/import         (CSV multipart)
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}
GET    /api/v1/users/{id}/memberships
POST   /api/v1/users/{id}/memberships
DELETE /api/v1/users/{id}/memberships/{mid}
GET    /api/v1/users/{id}/tags
POST   /api/v1/users/{id}/tags
DELETE /api/v1/users/{id}/tags/{tag_id}
```

---

## 8. Web Frontend

URL: `https://nexalert.area51consulting.com/admin`

**Live pages:**
- `/admin/login` — login + dark/light toggle
- `/admin` — dashboard (stats, quick actions, system health)
- `/admin/orgs` — org list + inline tree builder
- `/admin/orgs/new`, `/admin/orgs/edit?id=N` — org CRUD
- `/admin/users` — list, search, filter, paginate
- `/admin/users/new`, `/admin/users/edit?id=N` — user CRUD, memberships, tags

**Stub pages (scaffold only):**
- `/admin/tokens`, `/admin/audit`, `/admin/alerts`, `/admin/users/import`

---

## 9. Known Issues & Technical Debt

| Issue | Fix |
|---|---|
| Redis unavailable on Dreamhost | Rate limiter fails open (safe). Build MySQL queue table for Phase 2 dispatch worker. |
| Email templates missing | `MailService` needs `api/src/Templates/mail/{password_reset,email_verify,sms_optin_notice}.php` |
| `RequestResponse.php` stale | `api/src/Api/RequestResponse.php` unused — delete it |
| `public/` directory stale | May still exist on VPS — safe to delete |
| Group CRUD missing | Schema exists, no controller or UI yet |
| Tag management UI missing | Assignment works but no standalone tag admin page |
| Import UI stub | `POST /api/v1/users/import` API is complete; web UI is stub only |

---

## 10. Phase Roadmap

| Phase | Status | Scope |
|---|---|---|
| 1 | 🔄 80% | DB schema, org/node/user CRUD, auth, admin UI skeleton |
| 2 | ⬜ | Alert inbound API, simple+ack_required send, email+SMS, dispatch worker |
| 3 | ⬜ | Web Push (VAPID), poll alert type |
| 4 | ⬜ | chat + group_chat, Python WS bridge, Twilio inbound SMS |
| 5 | ⬜ | Azure Entra OIDC + LDAP, Entra directory import |
| 6 | ⬜ | Alert composer UI, delivery reports, audit log UI, tag + group management |
| 7 | ⬜ | Azure production migration, FCM/PWA, hardening |

---

## 11. Environment

```
Live URL:    https://nexalert.area51consulting.com
Admin:       https://nexalert.area51consulting.com/admin
VPS path:    /home/dh_w9tij7/NexAlert/
DB host:     mysql.area51consulting.com / DB: nexalert
PHP:         8.4 / Apache 2.4 / FastCGI PHP-FPM / Ubuntu 24.04
```