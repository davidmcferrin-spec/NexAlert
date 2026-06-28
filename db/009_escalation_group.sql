-- Migration 009: Escalation contact may be a user or a group (mutually exclusive)
-- Run after 008_alert_escalation.sql

ALTER TABLE alerts
    ADD COLUMN escalation_group_id INT UNSIGNED NULL
        COMMENT 'Group members notified if ack deadline is missed (alternative to escalation_user_id)'
        AFTER escalation_user_id,
    ADD CONSTRAINT fk_alert_escalation_group
        FOREIGN KEY (escalation_group_id) REFERENCES `groups`(id) ON DELETE SET NULL;
