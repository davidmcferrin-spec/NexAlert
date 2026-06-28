#!/usr/bin/env python3
"""
NexAlert dispatch worker — polls MySQL jobs queue and sends alert deliveries.

Usage:
  python workers/dispatch.py

Requires: pip install pymysql twilio pywebpush (twilio/pywebpush optional if disabled)
Reads .env from repo root (parent of workers/).
"""

from __future__ import annotations

import json
import logging
import os
import smtplib
import sys
import time
from datetime import datetime
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from html import escape
from pathlib import Path
import hashlib
import hmac
from urllib.parse import urlencode

try:
    import pymysql
except ImportError:
    print("Install pymysql: pip install pymysql", file=sys.stderr)
    sys.exit(1)

ROOT = Path(__file__).resolve().parent.parent
POLL_SECONDS = 2
MAX_ATTEMPTS = 3

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [dispatch] %(levelname)s %(message)s",
)
log = logging.getLogger("dispatch")


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    if not path.exists():
        return env
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        env[key.strip()] = val.strip().strip('"').strip("'")
    return env


ENV = load_env(ROOT / ".env")
if not ENV:
    ENV = load_env(ROOT / "config" / ".env.example")


def env(key: str, default: str = "") -> str:
    return os.environ.get(key) or ENV.get(key, default)


def db_connect():
    conn = pymysql.connect(
        host=env("DB_HOST", "127.0.0.1"),
        port=int(env("DB_PORT", "3306")),
        user=env("DB_USER"),
        password=env("DB_PASS"),
        database=env("DB_NAME"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )
    with conn.cursor() as cur:
        cur.execute("SET SESSION time_zone = '+00:00'")
    conn.commit()
    return conn


def claim_job(conn) -> dict | None:
    with conn.cursor() as cur:
        conn.begin()
        cur.execute(
            """
            SELECT id, payload, attempts, max_attempts
            FROM jobs
            WHERE queue = 'dispatch' AND status = 'pending' AND available_at <= NOW()
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
            """
        )
        row = cur.fetchone()
        if not row:
            conn.commit()
            return None
        cur.execute(
            "UPDATE jobs SET status = 'processing', attempts = attempts + 1 WHERE id = %s",
            (row["id"],),
        )
        conn.commit()
        row["payload"] = json.loads(row["payload"]) if isinstance(row["payload"], str) else row["payload"]
        return row


def finish_job(conn, job_id: int, ok: bool, error: str | None = None):
    with conn.cursor() as cur:
        if ok:
            cur.execute(
                "UPDATE jobs SET status = 'done', processed_at = NOW(), error = NULL WHERE id = %s",
                (job_id,),
            )
        else:
            cur.execute(
                """
                UPDATE jobs SET status = IF(attempts >= max_attempts, 'failed', 'pending'),
                       failed_at = IF(attempts >= max_attempts, NOW(), NULL),
                       available_at = IF(attempts >= max_attempts, available_at, DATE_ADD(NOW(), INTERVAL 30 SECOND)),
                       error = %s
                WHERE id = %s
                """,
                (error, job_id),
            )
        conn.commit()


def send_email(to_addr: str, subject: str, html: str, text: str):
    host = env("MAIL_HOST")
    port = int(env("MAIL_PORT", "587"))
    user = env("MAIL_USERNAME")
    password = env("MAIL_PASSWORD")
    from_addr = env("MAIL_FROM_ADDRESS", user)
    from_name = env("MAIL_FROM_NAME", "NexAlert")

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = f"{from_name} <{from_addr}>"
    msg["To"] = to_addr
    msg.attach(MIMEText(text, "plain", "utf-8"))
    msg.attach(MIMEText(html, "html", "utf-8"))

    use_tls = env("MAIL_ENCRYPTION", "tls").lower() != "ssl"
    if env("MAIL_ENCRYPTION", "tls").lower() == "ssl":
        server = smtplib.SMTP_SSL(host, port, timeout=30)
    else:
        server = smtplib.SMTP(host, port, timeout=30)
        if use_tls:
            server.starttls()
    server.login(user, password)
    server.sendmail(from_addr, [to_addr], msg.as_string())
    server.quit()


def send_sms(to_number: str, body: str) -> str:
    sid = env("TWILIO_ACCOUNT_SID")
    token = env("TWILIO_AUTH_TOKEN")
    from_num = env("TWILIO_FROM_NUMBER")
    if not sid or not token or not from_num:
        raise RuntimeError("Twilio not configured")

    from twilio.rest import Client  # type: ignore

    client = Client(sid, token)
    message = client.messages.create(body=body[:1600], from_=from_num, to=to_number)
    return message.sid


def sign_vote(alert_id: int, user_id: int, option: str) -> str:
    secret = env("APP_SECRET", "")
    msg = f"{alert_id}:{user_id}:{option}"
    return hmac.new(secret.encode(), msg.encode(), hashlib.sha256).hexdigest()[:32]


def build_vote_url(alert_id: int, user_id: int, option: str) -> str:
    base = env("APP_URL", "").rstrip("/")
    qs = urlencode({
        "alert_id": alert_id,
        "user_id": user_id,
        "option": option,
        "sig": sign_vote(alert_id, user_id, option),
    })
    return f"{base}/poll/vote?{qs}"


def build_alert_email(
    alert: dict, ack_url: str | None, vote_urls: list[tuple[str, str]] | None = None
) -> tuple[str, str, str]:
    severity = (alert.get("severity") or "info").upper()
    subject = f"[NexAlert {severity}] {alert.get('subject', 'Alert')}"
    body_text = alert.get("body") or ""
    sev = escape(severity)
    subj = escape(alert.get("subject") or "")
    body_html = escape(body_text).replace("\n", "<br>\n")
    app_url = env("APP_URL", "").rstrip("/")

    ack_html = ""
    if ack_url:
        ack_html = (
            f'<p style="margin-top:24px;"><a href="{escape(ack_url)}" '
            'style="background:#e51c1c;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;">'
            "Acknowledge Alert</a></p>"
        )

    poll_html = ""
    poll_text = ""
    if vote_urls:
        poll_q = escape(alert.get("poll_question") or "Please respond:")
        buttons = "".join(
            f'<a href="{escape(url)}" style="display:inline-block;margin:4px 8px 4px 0;'
            f'background:#2563eb;color:#fff;padding:10px 18px;text-decoration:none;border-radius:8px;">'
            f'{escape(label)}</a>'
            for label, url in vote_urls
        )
        poll_html = f'<p style="font-size:14px;font-weight:600;margin-top:20px;">{poll_q}</p><p>{buttons}</p>'
        poll_text = f"\n{alert.get('poll_question') or 'Poll'}:\n"
        for label, url in vote_urls:
            poll_text += f"  {label}: {url}\n"

    html = f"""<!DOCTYPE html><html><body style="font-family:sans-serif;color:#374151;max-width:600px;">
    <h1 style="font-size:20px;color:#111827;">{subj}</h1>
    <p style="font-size:12px;color:#6b7280;">Severity: <strong>{sev}</strong></p>
    <div style="font-size:15px;line-height:1.6;">{body_html}</div>
    {poll_html}
    {ack_html}
    <p style="font-size:12px;color:#9ca3af;margin-top:24px;">
      <a href="{escape(app_url)}/profile">Manage contact preferences</a>
    </p></body></html>"""

    text = f"{alert.get('subject', 'Alert')}\n\n{body_text}\n{poll_text}"
    if ack_url:
        text += f"\nAcknowledge: {ack_url}\n"
    return subject, html, text


def build_sms_body(alert: dict) -> str:
    severity = (alert.get("severity") or "info").upper()
    subject = alert.get("subject") or "Alert"
    body = (alert.get("body") or "")[:400]
    msg = f"NexAlert [{severity}] {subject}: {body}"
    if alert.get("alert_type") in ("chat", "group_chat"):
        msg += " — Reply by texting back."
    return msg


def send_web_push(delivery: dict, alert: dict, alert_id: int, conn) -> None:
    """Send Web Push notification via pywebpush (optional dependency)."""
    try:
        from pywebpush import WebPushException, webpush  # type: ignore
    except ImportError as exc:
        raise RuntimeError("Install pywebpush: pip install pywebpush") from exc

    private_key = env("VAPID_PRIVATE_KEY", "")
    subject = env("VAPID_SUBJECT", "mailto:nexalert@example.com")
    if not private_key:
        raise RuntimeError("VAPID_PRIVATE_KEY not configured")

    app_url = env("APP_URL", "").rstrip("/")
    payload = json.dumps({
        "title": f"NexAlert: {alert.get('subject', 'Alert')}",
        "body": (alert.get("body") or "")[:180],
        "url": f"{app_url}/profile?alert={alert_id}",
    })

    subscription = {
        "endpoint": delivery["endpoint"],
        "keys": {
            "p256dh": delivery["p256dh"],
            "auth": delivery["auth_key"],
        },
    }

    try:
        webpush(
            subscription_info=subscription,
            data=payload,
            vapid_private_key=private_key,
            vapid_claims={"sub": subject},
        )
    except WebPushException as exc:
        status = getattr(getattr(exc, "response", None), "status_code", None)
        if status == 410:
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE push_subscriptions SET is_active = 0, failed_count = failed_count + 1 WHERE id = %s",
                    (delivery.get("push_subscription_id"),),
                )
                conn.commit()
        raise


def process_dispatch_alert(conn, alert_id: int):
    with conn.cursor() as cur:
        cur.execute("SELECT * FROM alerts WHERE id = %s", (alert_id,))
        alert = cur.fetchone()
        if not alert:
            raise RuntimeError(f"Alert {alert_id} not found")

        if alert.get("status") == "scheduled":
            cur.execute(
                "UPDATE alerts SET status = 'sending' WHERE id = %s AND status = 'scheduled'",
                (alert_id,),
            )
            conn.commit()
            alert["status"] = "sending"

        if alert.get("status") == "cancelled":
            log.info("Alert %s cancelled — skipping dispatch", alert_id)
            return

        if alert.get("poll_options") and isinstance(alert["poll_options"], str):
            try:
                alert["poll_options"] = json.loads(alert["poll_options"])
            except json.JSONDecodeError:
                pass

        cur.execute(
            """
            SELECT ad.id, ad.user_id, ad.contact_id, ad.channel, ad.status,
                   ad.push_subscription_id,
                   uc.contact_value, uc.channel AS contact_channel,
                   ps.endpoint, ps.p256dh, ps.auth_key
            FROM alert_deliveries ad
            LEFT JOIN user_contacts uc ON uc.id = ad.contact_id
            LEFT JOIN push_subscriptions ps ON ps.id = ad.push_subscription_id
            WHERE ad.alert_id = %s AND ad.status = 'queued'
            """,
            (alert_id,),
        )
        deliveries = cur.fetchall()

    app_url = env("APP_URL", "").rstrip("/")
    ack_required = int(alert.get("ack_required") or 0) == 1 or alert.get("alert_type") == "ack_required"
    is_poll = alert.get("alert_type") == "poll"
    poll_options: list[str] = []
    if is_poll:
        raw_opts = alert.get("poll_options") or []
        if isinstance(raw_opts, list):
            poll_options = [str(o) for o in raw_opts if str(o).strip()]

    for d in deliveries:
        delivery_id = d["id"]
        try:
            if d["channel"] == "email":
                ack_url = f"{app_url}/profile?ack_alert={alert_id}" if ack_required else None
                vote_urls = None
                if is_poll and poll_options:
                    vote_urls = [(opt, build_vote_url(alert_id, int(d["user_id"]), opt)) for opt in poll_options]
                subject, html, text = build_alert_email(alert, ack_url, vote_urls)
                send_email(d["contact_value"], subject, html, text)
                provider_id = None
            elif d["channel"] == "sms":
                provider_id = send_sms(d["contact_value"], build_sms_body(alert))
            elif d["channel"] == "push_web":
                if not d.get("endpoint"):
                    raise RuntimeError("Missing push subscription for delivery")
                send_web_push(d, alert, alert_id, conn)
                provider_id = None
                with conn.cursor() as cur:
                    cur.execute(
                        "UPDATE push_subscriptions SET last_used_at = NOW(), failed_count = 0 WHERE id = %s",
                        (d.get("push_subscription_id"),),
                    )
                    conn.commit()
            elif d["channel"] == "in_app":
                provider_id = None
            else:
                raise RuntimeError(f"Channel not implemented: {d['channel']}")

            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE alert_deliveries
                    SET status = 'sent', sent_at = NOW(), provider_message_id = %s
                    WHERE id = %s
                    """,
                    (provider_id, delivery_id),
                )
                conn.commit()
            log.info("Sent %s delivery %s to user %s", d["channel"], delivery_id, d["user_id"])
        except Exception as exc:
            log.exception("Delivery %s failed: %s", delivery_id, exc)
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE alert_deliveries
                    SET status = 'failed', failed_at = NOW(),
                        provider_response = %s, retry_count = retry_count + 1
                    WHERE id = %s
                    """,
                    (json.dumps({"error": str(exc)}), delivery_id),
                )
                conn.commit()

    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) AS c FROM alert_deliveries WHERE alert_id = %s AND status = 'queued'",
            (alert_id,),
        )
        row = cur.fetchone()
        if row and int(row["c"]) == 0:
            cur.execute(
                "UPDATE alerts SET status = 'sent', sent_at = COALESCE(sent_at, NOW()) WHERE id = %s",
                (alert_id,),
            )
            # Set ack deadline and schedule escalation when alert fully sent
            cur.execute(
                """
                SELECT ack_required, ack_deadline_minutes, escalation_user_id, escalation_group_id,
                       escalated_at, status
                FROM alerts WHERE id = %s
                """,
                (alert_id,),
            )
            alert_meta = cur.fetchone()
            if alert_meta and int(alert_meta.get("ack_required") or 0) == 1:
                deadline_mins = alert_meta.get("ack_deadline_minutes")
                esc_user = alert_meta.get("escalation_user_id")
                esc_group = alert_meta.get("escalation_group_id")
                if deadline_mins and int(deadline_mins) > 0:
                    cur.execute(
                        """
                        UPDATE alerts
                        SET ack_deadline_at = DATE_ADD(COALESCE(sent_at, NOW()), INTERVAL %s MINUTE)
                        WHERE id = %s AND ack_deadline_at IS NULL
                        """,
                        (int(deadline_mins), alert_id),
                    )
                    if (esc_user or esc_group) and not alert_meta.get("escalated_at"):
                        delay_secs = int(deadline_mins) * 60
                        cur.execute(
                            """
                            INSERT INTO jobs (queue, payload, status, available_at)
                            VALUES ('dispatch', %s, 'pending',
                                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL %s SECOND))
                            """,
                            (
                                json.dumps({"type": "ack_escalate", "data": {"alert_id": alert_id}}),
                                delay_secs,
                            ),
                        )
                        target = f"user {esc_user}" if esc_user else f"group {esc_group}"
                        log.info(
                            "Scheduled ack escalation for alert %s in %s minutes (%s)",
                            alert_id,
                            deadline_mins,
                            target,
                        )

            # TTL: set expires_at from sent_at + ttl_minutes and schedule expire job
            cur.execute(
                "SELECT ttl_minutes, sent_at, expires_at FROM alerts WHERE id = %s",
                (alert_id,),
            )
            ttl_row = cur.fetchone()
            ttl_mins = int(ttl_row.get("ttl_minutes") or 0) if ttl_row else 0
            if ttl_row and ttl_mins > 0 and not ttl_row.get("expires_at"):
                cur.execute(
                    """
                    UPDATE alerts
                    SET expires_at = DATE_ADD(COALESCE(sent_at, UTC_TIMESTAMP()), INTERVAL %s MINUTE)
                    WHERE id = %s AND expires_at IS NULL
                    """,
                    (ttl_mins, alert_id),
                )
                cur.execute(
                    """
                    SELECT expires_at FROM alerts WHERE id = %s
                    """,
                    (alert_id,),
                )
                exp_row = cur.fetchone()
                if exp_row and exp_row.get("expires_at"):
                    expires_at = exp_row["expires_at"]
                    cur.execute(
                        """
                        SELECT COUNT(*) AS c FROM jobs
                        WHERE queue = 'dispatch' AND status IN ('pending', 'processing')
                          AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'alert_expire'
                          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.alert_id')) AS UNSIGNED) = %s
                        """,
                        (alert_id,),
                    )
                    pending_exp = cur.fetchone()
                    if not pending_exp or int(pending_exp["c"] or 0) == 0:
                        cur.execute(
                            """
                            INSERT INTO jobs (queue, payload, status, available_at)
                            VALUES ('dispatch', %s, 'pending', %s)
                            """,
                            (
                                json.dumps({"type": "alert_expire", "data": {"alert_id": alert_id}}),
                                expires_at,
                            ),
                        )
                        log.info(
                            "Scheduled alert %s expiry at %s (%s min TTL)",
                            alert_id,
                            expires_at,
                            ttl_mins,
                        )
        conn.commit()


def resolve_group_members(conn, group_id: int, max_depth: int = 10) -> list[int]:
    """Recursively resolve user IDs in a group (including nested groups). BFS with cycle detection."""
    visited: set[int] = set()
    queue = [group_id]
    user_ids: list[int] = []
    depth = 0

    with conn.cursor() as cur:
        while queue and depth <= max_depth:
            current_batch = queue
            queue = []
            depth += 1

            for gid in current_batch:
                if gid in visited:
                    continue
                visited.add(gid)

                cur.execute(
                    "SELECT user_id FROM group_memberships WHERE group_id = %s AND is_active = 1",
                    (gid,),
                )
                for row in cur.fetchall():
                    user_ids.append(int(row["user_id"]))

                cur.execute(
                    "SELECT child_group_id FROM group_children WHERE parent_group_id = %s",
                    (gid,),
                )
                for row in cur.fetchall():
                    queue.append(int(row["child_group_id"]))

    return list(dict.fromkeys(user_ids))


def fetch_escalation_recipients(conn, user_ids: list[int]) -> list[dict]:
    if not user_ids:
        return []

    placeholders = ",".join(["%s"] * len(user_ids))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT u.id, u.display_name,
                   (SELECT uc.contact_value FROM user_contacts uc
                    WHERE uc.user_id = u.id AND uc.channel = 'email' AND uc.is_primary = 1
                    LIMIT 1) AS email
            FROM users u
            WHERE u.id IN ({placeholders}) AND u.is_active = 1
            """,
            user_ids,
        )
        rows = cur.fetchall()

    return [r for r in rows if r.get("email")]


def process_ack_escalate(conn, alert_id: int):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, subject, body, severity, status, ack_required, ack_deadline_at,
                   escalation_user_id, escalation_group_id, escalated_at, sent_at
            FROM alerts WHERE id = %s
            """,
            (alert_id,),
        )
        alert = cur.fetchone()
        if not alert:
            raise RuntimeError(f"Alert {alert_id} not found")

        if alert.get("escalated_at"):
            log.info("Alert %s already escalated — skipping", alert_id)
            return

        if alert.get("status") in ("cancelled", "expired", "draft"):
            log.info("Alert %s status %s — skipping escalation", alert_id, alert.get("status"))
            return

        if int(alert.get("ack_required") or 0) != 1:
            log.info("Alert %s does not require ack — skipping escalation", alert_id)
            return

        esc_user_id = alert.get("escalation_user_id")
        esc_group_id = alert.get("escalation_group_id")
        if not esc_user_id and not esc_group_id:
            log.info("Alert %s has no escalation contact — skipping", alert_id)
            return

        if esc_user_id and esc_group_id:
            log.warning("Alert %s has both escalation user and group — using user only", alert_id)
            esc_group_id = None

        cur.execute(
            """
            SELECT COUNT(DISTINCT user_id) AS c
            FROM alert_deliveries
            WHERE alert_id = %s AND status IN ('sent', 'delivered')
            """,
            (alert_id,),
        )
        recipient_row = cur.fetchone()
        total_recipients = int(recipient_row["c"] or 0) if recipient_row else 0

        cur.execute(
            "SELECT COUNT(DISTINCT user_id) AS c FROM alert_acks WHERE alert_id = %s",
            (alert_id,),
        )
        ack_row = cur.fetchone()
        acked = int(ack_row["c"] or 0) if ack_row else 0
        unacked = max(0, total_recipients - acked)

        if unacked == 0:
            log.info("Alert %s fully acknowledged — no escalation needed", alert_id)
            cur.execute(
                "UPDATE alerts SET escalated_at = NOW() WHERE id = %s AND escalated_at IS NULL",
                (alert_id,),
            )
            conn.commit()
            return

        if esc_user_id:
            esc_target_ids = [int(esc_user_id)]
        else:
            esc_target_ids = resolve_group_members(conn, int(esc_group_id))
            if not esc_target_ids:
                log.warning(
                    "Alert %s escalation group %s has no members — skipping",
                    alert_id,
                    esc_group_id,
                )
                return

        esc_recipients = fetch_escalation_recipients(conn, esc_target_ids)
        if not esc_recipients:
            target = f"user {esc_user_id}" if esc_user_id else f"group {esc_group_id}"
            raise RuntimeError(f"Escalation contact {target} has no reachable email recipients")

        cur.execute(
            """
            SELECT DISTINCT u.display_name,
                   (SELECT uc.contact_value FROM user_contacts uc
                    WHERE uc.user_id = u.id AND uc.channel = 'email' AND uc.is_primary = 1
                    LIMIT 1) AS email
            FROM alert_deliveries ad
            JOIN users u ON u.id = ad.user_id
            LEFT JOIN alert_acks aa ON aa.alert_id = ad.alert_id AND aa.user_id = ad.user_id
            WHERE ad.alert_id = %s
              AND ad.status IN ('sent', 'delivered')
              AND aa.id IS NULL
            ORDER BY u.display_name
            LIMIT 50
            """,
            (alert_id,),
        )
        unacked_users = cur.fetchall()

    severity = (alert.get("severity") or "info").upper()
    subject = alert.get("subject") or "Alert"
    deadline = alert.get("ack_deadline_at")
    deadline_str = deadline.strftime("%Y-%m-%d %H:%M UTC") if hasattr(deadline, "strftime") else str(deadline or "")

    names_html = "".join(
        f"<li>{escape(u.get('display_name') or u.get('email') or '?')}</li>"
        for u in unacked_users
    )
    if unacked > len(unacked_users):
        names_html += f"<li>…and {unacked - len(unacked_users)} more</li>"

    app_url = env("APP_URL", "").rstrip("/")
    history_url = f"{app_url}/admin/alerts/history"

    email_subject = f"[NexAlert ESCALATION] {unacked} unacked — {subject}"
    html = f"""<!DOCTYPE html><html><body style="font-family:sans-serif;color:#374151;max-width:600px;">
    <h1 style="font-size:18px;color:#c11414;">Acknowledgement deadline missed</h1>
    <p><strong>{escape(subject)}</strong> ({escape(severity)}) still has
       <strong>{unacked}</strong> of {total_recipients} recipients who have not acknowledged.</p>
    <p style="font-size:13px;color:#6b7280;">Ack deadline: {escape(deadline_str)}</p>
    <div style="font-size:14px;line-height:1.5;">{escape(alert.get('body') or '')[:500]}</div>
    <p style="margin-top:16px;font-size:13px;">Unacknowledged recipients:</p>
    <ul style="font-size:13px;">{names_html}</ul>
    <p style="margin-top:24px;">
      <a href="{escape(history_url)}" style="background:#e51c1c;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;">
        View alert history
      </a>
    </p></body></html>"""

    text = (
        f"NexAlert escalation: {unacked} of {total_recipients} have not acked alert #{alert_id}\n"
        f"Subject: {subject}\nDeadline: {deadline_str}\n\n"
        f"View: {history_url}\n"
    )

    for recipient in esc_recipients:
        send_email(recipient["email"], email_subject, html, text)

    if esc_user_id:
        target_label = f"user {esc_user_id}"
    else:
        target_label = f"group {esc_group_id} ({len(esc_recipients)} recipients)"
    log.info(
        "Escalation sent for alert %s to %s (%s unacked)",
        alert_id,
        target_label,
        unacked,
    )

    with conn.cursor() as cur:
        cur.execute(
            "UPDATE alerts SET escalated_at = NOW() WHERE id = %s",
            (alert_id,),
        )
        conn.commit()


def process_sms_optin(conn, contact_id: int, user_id: int):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT c.contact_value, sc.id AS consent_id, sc.status, sc.opt_in_sent_at
            FROM user_contacts c
            JOIN user_sms_consent sc ON sc.contact_id = c.id
            WHERE c.id = %s AND c.user_id = %s AND c.channel = 'sms'
            """,
            (contact_id, user_id),
        )
        row = cur.fetchone()
        if not row:
            return
        if row["status"] == "confirmed":
            return
        if row["status"] == "opt_in_sent" and row.get("opt_in_sent_at"):
            cur.execute(
                "SELECT TIMESTAMPDIFF(MINUTE, %s, UTC_TIMESTAMP()) AS mins",
                (row["opt_in_sent_at"],),
            )
            age = cur.fetchone()
            if age and int(age.get("mins") or 999) < 5:
                log.info("SMS opt-in for contact %s sent recently — skipping duplicate", contact_id)
                return

    msg = (
        f"{env('APP_NAME', 'NexAlert')}: Reply YES to receive emergency SMS alerts. "
        "Msg&data rates may apply. Reply STOP to cancel."
    )
    sid = send_sms(row["contact_value"], msg)

    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE user_sms_consent
            SET status = 'opt_in_sent', opt_in_sent_at = NOW(), twilio_message_sid = %s
            WHERE id = %s
            """,
            (sid, row["consent_id"]),
        )
        conn.commit()
    log.info("SMS opt-in sent to contact %s", contact_id)


def release_due_scheduled_alerts(conn) -> int:
    """Safety net: enqueue dispatch for scheduled alerts past send_at."""
    released = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id FROM alerts
            WHERE status = 'scheduled'
              AND send_at IS NOT NULL
              AND send_at <= UTC_TIMESTAMP()
            ORDER BY send_at ASC
            LIMIT 5
            """
        )
        rows = cur.fetchall()
        for row in rows:
            alert_id = int(row["id"])
            cur.execute(
                """
                SELECT COUNT(*) AS c FROM jobs
                WHERE queue = 'dispatch' AND status IN ('pending', 'processing')
                  AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'dispatch_alert'
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.alert_id')) AS UNSIGNED) = %s
                """,
                (alert_id,),
            )
            pending = cur.fetchone()
            if pending and int(pending["c"] or 0) > 0:
                continue
            cur.execute(
                "UPDATE alerts SET status = 'sending' WHERE id = %s AND status = 'scheduled'",
                (alert_id,),
            )
            cur.execute(
                """
                INSERT INTO jobs (queue, payload, status, available_at)
                VALUES ('dispatch', %s, 'pending', UTC_TIMESTAMP())
                """,
                (json.dumps({"type": "dispatch_alert", "data": {"alert_id": alert_id}}),),
            )
            released += 1
        conn.commit()
    if released:
        log.info("Released %s due scheduled alert(s) for dispatch", released)
    return released


def process_alert_expire(conn, alert_id: int):
    """Mark alert expired — skip queued deliveries."""
    with conn.cursor() as cur:
        cur.execute("SELECT id, status, expires_at FROM alerts WHERE id = %s", (alert_id,))
        alert = cur.fetchone()
        if not alert:
            raise RuntimeError(f"Alert {alert_id} not found")

        if alert.get("status") in ("expired", "cancelled", "draft"):
            log.info("Alert %s status %s — skipping expire", alert_id, alert.get("status"))
            return

        cur.execute(
            """
            UPDATE alert_deliveries
            SET status = 'skipped', skip_reason = 'alert_expired'
            WHERE alert_id = %s AND status = 'queued'
            """,
            (alert_id,),
        )
        cur.execute(
            """
            UPDATE jobs SET status = 'cancelled'
            WHERE queue = 'dispatch' AND status = 'pending'
              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) IN ('dispatch_alert', 'ack_escalate')
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.alert_id')) AS UNSIGNED) = %s
            """,
            (alert_id,),
        )
        cur.execute(
            "UPDATE alerts SET status = 'expired' WHERE id = %s AND status NOT IN ('cancelled', 'expired')",
            (alert_id,),
        )
        conn.commit()
    log.info("Alert %s marked expired", alert_id)


def expire_due_alerts(conn) -> int:
    """Safety net: expire alerts past expires_at."""
    expired = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id FROM alerts
            WHERE status IN ('sending', 'sent', 'scheduled')
              AND expires_at IS NOT NULL
              AND expires_at <= UTC_TIMESTAMP()
            ORDER BY expires_at ASC
            LIMIT 5
            """
        )
        rows = cur.fetchall()
        for row in rows:
            alert_id = int(row["id"])
            cur.execute(
                """
                SELECT COUNT(*) AS c FROM jobs
                WHERE queue = 'dispatch' AND status IN ('pending', 'processing')
                  AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'alert_expire'
                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.alert_id')) AS UNSIGNED) = %s
                """,
                (alert_id,),
            )
            pending = cur.fetchone()
            if pending and int(pending["c"] or 0) > 0:
                continue
            cur.execute(
                """
                INSERT INTO jobs (queue, payload, status, available_at)
                VALUES ('dispatch', %s, 'pending', UTC_TIMESTAMP())
                """,
                (json.dumps({"type": "alert_expire", "data": {"alert_id": alert_id}}),),
            )
            expired += 1
        conn.commit()
    if expired:
        log.info("Enqueued %s due alert expiry job(s)", expired)
    return expired


def process_job(conn, job: dict):
    payload = job.get("payload") or {}
    job_type = payload.get("type")
    data = payload.get("data") or {}

    if job_type == "dispatch_alert":
        process_dispatch_alert(conn, int(data["alert_id"]))
    elif job_type == "ack_escalate":
        process_ack_escalate(conn, int(data["alert_id"]))
    elif job_type == "sms_optin":
        process_sms_optin(conn, int(data["contact_id"]), int(data["user_id"]))
    elif job_type == "alert_expire":
        process_alert_expire(conn, int(data["alert_id"]))
    else:
        raise RuntimeError(f"Unknown job type: {job_type}")


def main():
    log.info("NexAlert dispatch worker starting (poll=%ss)", POLL_SECONDS)
    while True:
        try:
            conn = db_connect()
            job = claim_job(conn)
            if job:
                try:
                    process_job(conn, job)
                    finish_job(conn, job["id"], True)
                except Exception as exc:
                    log.exception("Job %s failed", job["id"])
                    finish_job(conn, job["id"], False, str(exc))
            else:
                release_due_scheduled_alerts(conn)
                expire_due_alerts(conn)
            conn.close()
        except Exception as exc:
            log.exception("Worker loop error: %s", exc)
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
