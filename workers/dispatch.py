#!/usr/bin/env python3
"""
NexAlert dispatch worker — polls MySQL jobs queue and sends alert deliveries.

Usage:
  python workers/dispatch.py

Requires: pip install pymysql twilio (twilio optional if SMS disabled)
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


def build_alert_email(alert: dict, ack_url: str | None) -> tuple[str, str, str]:
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

    html = f"""<!DOCTYPE html><html><body style="font-family:sans-serif;color:#374151;max-width:600px;">
    <h1 style="font-size:20px;color:#111827;">{subj}</h1>
    <p style="font-size:12px;color:#6b7280;">Severity: <strong>{sev}</strong></p>
    <div style="font-size:15px;line-height:1.6;">{body_html}</div>
    {ack_html}
    <p style="font-size:12px;color:#9ca3af;margin-top:24px;">
      <a href="{escape(app_url)}/profile">Manage contact preferences</a>
    </p></body></html>"""

    text = f"{alert.get('subject', 'Alert')}\n\n{body_text}\n"
    if ack_url:
        text += f"\nAcknowledge: {ack_url}\n"
    return subject, html, text


def build_sms_body(alert: dict) -> str:
    severity = (alert.get("severity") or "info").upper()
    subject = alert.get("subject") or "Alert"
    body = (alert.get("body") or "")[:400]
    return f"NexAlert [{severity}] {subject}: {body}"


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
                   uc.contact_value, uc.channel AS contact_channel
            FROM alert_deliveries ad
            JOIN user_contacts uc ON uc.id = ad.contact_id
            WHERE ad.alert_id = %s AND ad.status = 'queued'
            """,
            (alert_id,),
        )
        deliveries = cur.fetchall()

    app_url = env("APP_URL", "").rstrip("/")
    ack_required = int(alert.get("ack_required") or 0) == 1 or alert.get("alert_type") == "ack_required"

    for d in deliveries:
        delivery_id = d["id"]
        try:
            if d["channel"] == "email":
                ack_url = f"{app_url}/profile?ack_alert={alert_id}" if ack_required else None
                subject, html, text = build_alert_email(alert, ack_url)
                send_email(d["contact_value"], subject, html, text)
                provider_id = None
            elif d["channel"] == "sms":
                provider_id = send_sms(d["contact_value"], build_sms_body(alert))
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
                SELECT ack_required, ack_deadline_minutes, escalation_user_id, escalated_at, status
                FROM alerts WHERE id = %s
                """,
                (alert_id,),
            )
            alert_meta = cur.fetchone()
            if alert_meta and int(alert_meta.get("ack_required") or 0) == 1:
                deadline_mins = alert_meta.get("ack_deadline_minutes")
                esc_user = alert_meta.get("escalation_user_id")
                if deadline_mins and int(deadline_mins) > 0:
                    cur.execute(
                        """
                        UPDATE alerts
                        SET ack_deadline_at = DATE_ADD(COALESCE(sent_at, NOW()), INTERVAL %s MINUTE)
                        WHERE id = %s AND ack_deadline_at IS NULL
                        """,
                        (int(deadline_mins), alert_id),
                    )
                    if esc_user and not alert_meta.get("escalated_at"):
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
                        log.info(
                            "Scheduled ack escalation for alert %s in %s minutes (user %s)",
                            alert_id,
                            deadline_mins,
                            esc_user,
                        )
        conn.commit()


def process_ack_escalate(conn, alert_id: int):
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, subject, body, severity, status, ack_required, ack_deadline_at,
                   escalation_user_id, escalated_at, sent_at
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
        if not esc_user_id:
            log.info("Alert %s has no escalation user — skipping", alert_id)
            return

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

        cur.execute(
            """
            SELECT u.id, u.display_name,
                   (SELECT uc.contact_value FROM user_contacts uc
                    WHERE uc.user_id = u.id AND uc.channel = 'email' AND uc.is_primary = 1
                    LIMIT 1) AS email
            FROM users u
            WHERE u.id = %s AND u.is_active = 1
            """,
            (esc_user_id,),
        )
        esc_user = cur.fetchone()
        if not esc_user or not esc_user.get("email"):
            raise RuntimeError(f"Escalation user {esc_user_id} not found or has no email")

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

    send_email(esc_user["email"], email_subject, html, text)
    log.info(
        "Escalation sent for alert %s to user %s (%s unacked)",
        alert_id,
        esc_user_id,
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
            conn.close()
        except Exception as exc:
            log.exception("Worker loop error: %s", exc)
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
