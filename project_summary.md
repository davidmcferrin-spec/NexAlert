# NexAlert — Project Summary & Development Handoff
**Last updated:** 2026-06-27  
**Status:** Phase 2 complete; Phase 3–4 in progress; live on Dreamhost VPS

---

## 1. What NexAlert Is

NexAlert is a self-hosted mass notification and alert management platform for
Nexstar Media Group / NewsNation broadcast operations.

**Primary use cases:**
- System alerts (CheckMK/XPression triggers via system token API)
- Staff notices and operational updates
- Safety alerts, warnings, evacuation notices
- Polls, acknowledgement tracking, two-way chat threads

**Live:** `https://nexalert.area51consulting.com/admin`

---

## 2. Core Requirements

### Multi-Channel Delivery
| Channel | Provider | Status |
|---|---|---|
| Email | PHPMailer + SMTP | ✅ Live |
| SMS | Twilio | ✅ Live |
| Web Push | VAPID + service worker | ✅ Live (requires VAPID + pywebpush) |
| In-app | Profile alerts list | ✅ Live |
| FCM Push | Firebase | ⬜ Phase 7 |

### Alert Types
| Type | Behavior | Status |
|---|---|---|
| `simple` | Fire and forget | ✅ |
| `ack_required` | Must ack; escalation on deadline | ✅ |
| `poll` | Vote; email links + profile | ✅ |
| `chat` | Recipients reply to originator only | ✅ |
| `group_chat` | All recipients see all replies | ✅ |

### Severity Levels
`test` → `info` → `notice` → `warning` → `critical` → `evacuation`  
Evacuation/critical override user channel preferences.

---

## 3. Organization & User Model

- Hierarchical org tree with materialized paths (`org_nodes.path`)
- Tags: auto/inherited, manual, exclusive with approval workflow
- Groups: recursive BFS resolution, cycle detection
- Targeting: `(org: AND tag:) OR group:` expressions + visual `target_tree` JSON

---

## 4. Authentication

| Provider | Status |
|---|---|
| Local (bcrypt) | ✅ Live |
| Azure Entra OIDC | Phase 5 |
| LDAP | Phase 5 |

RBAC: `super_admin`, `org_admin`, `group_admin`, `sender`, `recipient` with scoped assignments.

---

## 5. Tech Stack

| Layer | Tech |
|---|---|
| Frontend | PHP 8.4, Tailwind CDN, Alpine.js 3.x |
| API | PHP 8.4, Apache, custom PSR-4 autoloader |
| Workers | Python 3.11 (`workers/dispatch.py`) |
| Queue | MySQL `jobs` table |
| Database | MySQL 8.0, utf8mb4_unicode_ci |
| SMS | Twilio |
| Email | PHPMailer |

**Hard constraints:** No Node.js, Docker, or Composer.

---

## 6. Database Migrations

Run: `php migrate.php --migrate-only`

| Migration | Purpose |
|---|---|
| 007 | `alert_targets.conj_terms` for multi-tag AND |
| 008 | Ack escalation columns |
| 009 | Escalation group support |
| 010 | Org admin token manage permission |
| 011 | Push delivery `push_subscription_id`, nullable `contact_id` |

---

## 7. Key API Endpoints

```
POST   /api/v1/alerts                    JWT + alert.send
POST   /api/v1/alert                     System token (external triggers)
GET    /api/v1/alerts/{id}               Detail + poll results + chat
POST   /api/v1/alerts/{id}/ack|poll|cancel|retry
GET    /api/v1/alerts/{id}/chat/messages
POST   /api/v1/alerts/{id}/chat/messages|close

GET    /api/v1/poll/vote                   Public signed email vote
GET    /api/v1/profile/push/vapid-key
POST   /api/v1/profile/push/subscribe
DELETE /api/v1/profile/push/subscriptions/{id}

POST   /api/v1/targets/preview
GET    /api/v1/dashboard/stats
POST   /api/v1/webhooks/twilio/sms         STOP/YES + chat SMS routing
```

Full CRUD: orgs, nodes, users, groups, tags, tokens — see `api/routes.php`.

---

## 8. Web Frontend

| Path | Purpose |
|---|---|
| `/admin` | Dashboard with live stats |
| `/admin/alerts/new` | Composer (all alert types, channels, TTL, schedule) |
| `/admin/alerts/history` | Delivery drill-down, poll results, chat thread |
| `/admin/test-send` | Target builder |
| `/profile` | Prefs, push subscribe, polls, chat replies |
| `/poll/vote` | Public poll confirmation page |

---

## 9. Dispatch Worker

```bash
pip install pymysql twilio pywebpush
python workers/dispatch.py
```

On send complete: sets `sent_at`, ack deadline, TTL `expires_at`, schedules jobs.

---

## 10. Environment Setup

```bash
cp config/.env.example .env
php migrate.php --migrate-only
php scripts/generate_vapid.php   # optional, for Web Push
```

Required `.env`: `APP_SECRET`, `DB_*`, `SMTP_*`, `TWILIO_*` (SMS), `VAPID_*` (push).

---

## 11. Phase Roadmap

| Phase | Status | Scope |
|---|---|---|
| 1 | ✅ Done | Schema, CRUD, auth, admin UI |
| 2 | ✅ Done | Alert pipeline, email/SMS, ack, poll, TTL, schedule |
| 3 | 🔄 | Web Push — subscription + dispatch done; polish remaining |
| 4 | 🔄 | Chat — threads + SMS inbound done; WebSocket/real-time pending |
| 5 | ⬜ | Entra OIDC + LDAP |
| 6 | ⬜ | Template library, delivery reports |
| 7 | ⬜ | Azure prod, FCM/PWA |

---

## 12. Known Issues & Technical Debt

| Issue | Notes |
|---|---|
| Redis unavailable on Dreamhost | Rate limit fails open; MySQL queue used |
| Real-time chat | Messages load on open; no WebSocket yet |
| `push_fcm` | Schema only; not implemented |
| Tag self-request on profile | ✅ Profile UI + `POST /api/v1/profile/tag-requests` |

---

## 13. Environment

```
Live URL:    https://nexalert.area51consulting.com
Admin:       https://nexalert.area51consulting.com/admin
VPS path:    /home/dh_w9tij7/NexAlert/
PHP:         8.4 / Apache / Ubuntu 24.04
```
