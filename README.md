# NexAlert

A self-hosted mass notification and alert management platform for broadcast operations.
Modeled after AlertMedia/Everbridge, purpose-built for multi-site, multi-org media environments.

**Live URL (dev):** https://nexalert.area51consulting.com

## Features

- Multi-channel delivery: Email, SMS (Twilio), Web Push (VAPID), In-App
- Flexible alert types: Simple, Ack Required, Poll, Chat, Group Chat
- Tag-based targeting with org-tree inheritance and approval workflows
- SMS opt-in consent management (Twilio A2P 10DLC compliant)
- Azure Entra ID OIDC + LDAP + local auth
- Multi-org, multi-site user model with home org concept
- Groups of groups for nested distribution lists
- Role-based access control (RBAC) scoped per org/node
- REST API for external system integration (CheckMK, XPression, etc.)
- Full audit log

## Stack

| Layer | Tech |
|---|---|
| Frontend | PHP 8.2, Tailwind CSS, Alpine.js |
| API | PHP 8.2, Apache, JWT |
| Workers | Python 3.11 asyncio (dispatch, chat bridge) |
| Queue | Redis |
| Database | MySQL 8.0 |
| SMS | Twilio |
| Email | PHPMailer + SMTP |
| Push | web-push-php (VAPID) |
| Auth | Azure Entra OIDC, LDAP, local |

## Project Structure

```
nexalert/
├── api/           # PHP REST API
├── web/           # PHP frontend (admin + user portal)
├── workers/       # Python asyncio workers (dispatch, chat)
├── db/            # SQL migrations (numbered)
├── config/        # .env.example, Apache vhosts
├── tests/         # PHPUnit + pytest
├── docs/          # Architecture, API reference
├── README.md
└── CLAUDE.md
```

## Setup

See `docs/setup.md` (coming Phase 1).

## Build Phases

| Phase | Status | Scope |
|---|---|---|
| 1 | 🔄 In Progress | DB schema, org/group/user CRUD, local auth, user portal |
| 2 | ⬜ | Alert API (inbound token), simple + ack_required, email + SMS |
| 3 | ⬜ | Web Push (VAPID), poll alert type |
| 4 | ⬜ | Chat + group chat (Python WS bridge, SMS inbound webhook) |
| 5 | ⬜ | Azure Entra OIDC + LDAP auth |
| 6 | ⬜ | Admin dashboard, delivery reports, audit log UI |
| 7 | ⬜ | Azure production hardening, FCM/PWA |
