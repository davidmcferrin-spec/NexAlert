-- Migration 011: Web push deliveries reference push_subscriptions (contact_id optional)
-- Run after 010_token_manage_permission.sql

ALTER TABLE alert_deliveries
    MODIFY contact_id INT UNSIGNED NULL
        COMMENT 'user_contacts.id for email/sms; NULL for push_web',

    ADD COLUMN push_subscription_id INT UNSIGNED NULL
        COMMENT 'push_subscriptions.id when channel = push_web'
        AFTER contact_id,

    ADD CONSTRAINT fk_ad_push_sub
        FOREIGN KEY (push_subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE;
