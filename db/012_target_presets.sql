-- Migration 012: Saved target presets for Target Builder and API
-- Run after 011_alert_delivery_push.sql

CREATE TABLE target_presets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NULL COMMENT 'NULL = global preset (super_admin); else scoped to org',
    slug            VARCHAR(64) NOT NULL,
    name            VARCHAR(128) NOT NULL,
    description     TEXT NULL,
    expression      TEXT NOT NULL COMMENT 'Canonical target expression string',
    target_tree     JSON NULL COMMENT 'Visual builder tree for round-trip editing',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_target_preset_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_target_preset_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_target_preset_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_org_active (org_id, is_active),
    INDEX idx_slug (slug),
    INDEX idx_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
