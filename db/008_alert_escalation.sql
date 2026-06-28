-- Migration 008: Ack escalation tracking columns
-- Run after 007_alert_target_conj_terms.sql

ALTER TABLE alerts
    ADD COLUMN ack_deadline_at DATETIME NULL
        COMMENT 'Computed when alert finishes sending (sent_at + ack_deadline_minutes)'
        AFTER ack_deadline_minutes,
    ADD COLUMN escalated_at DATETIME NULL
        COMMENT 'When escalation notification was sent to escalation_user_id'
        AFTER escalation_user_id;

CREATE INDEX idx_alerts_ack_deadline ON alerts (ack_deadline_at);
CREATE INDEX idx_alerts_escalated ON alerts (escalated_at);
