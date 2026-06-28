-- =============================================================================
-- NexAlert - Initial Schema Migration
-- Version: 001
-- Description: Full initial schema for NexAlert alert and communications platform
-- Target: MySQL 8.0+ / Azure MySQL Flexible Server
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- =============================================================================
-- SECTION 1: SCHEMA VERSION TRACKING
-- =============================================================================

CREATE TABLE schema_migrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version         VARCHAR(20) NOT NULL UNIQUE,
    description     VARCHAR(255) NOT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum        VARCHAR(64) NULL COMMENT 'SHA256 of migration file for integrity check'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, description) VALUES ('001', 'Initial schema');

-- =============================================================================
-- SECTION 2: ORGANIZATIONS & ORG TREE
-- Each organization is a root node. The org tree (org_nodes) represents the
-- hierarchy: Org → Region → Site → Department, etc. Depth is unlimited.
-- =============================================================================

CREATE TABLE organizations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(80) NOT NULL UNIQUE COMMENT 'URL-safe identifier, e.g. nexstar, newsnation',
    display_name    VARCHAR(200) NOT NULL,
    logo_url        VARCHAR(500) NULL,
    primary_color   CHAR(7) NULL COMMENT 'Hex color for org branding e.g. #1a2b3c',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adjacency list tree for org hierarchy. Each node belongs to one org.
-- A node can be an Org root, Region, City/Market, Site, or Department.
CREATE TABLE org_nodes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id          INT UNSIGNED NOT NULL,
    parent_id       INT UNSIGNED NULL COMMENT 'NULL = root node for the org',
    node_type       ENUM('org','region','market','site','department','team') NOT NULL,
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(80) NOT NULL COMMENT 'Unique within org scope',
    path            VARCHAR(1000) NOT NULL COMMENT 'Materialized path: /1/4/12/ for fast subtree queries',
    depth           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orgnode_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orgnode_parent FOREIGN KEY (parent_id) REFERENCES org_nodes(id) ON DELETE RESTRICT,
    INDEX idx_org_id (org_id),
    INDEX idx_parent_id (parent_id),
    INDEX idx_path (path(255)),
    INDEX idx_node_type (node_type),
    UNIQUE KEY uq_org_slug (org_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 3: AUTHENTICATION PROVIDERS
-- Tracks external identity providers (Entra tenants, LDAP servers).
-- NexAlert is multi-org but typically single-tenant Entra with OU mapping.
-- =============================================================================

CREATE TABLE auth_providers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_type   ENUM('entra','ldap','local') NOT NULL,
    name            VARCHAR(100) NOT NULL COMMENT 'Human label e.g. Nexstar Corporate Entra',
    -- Entra fields
    tenant_id       VARCHAR(100) NULL COMMENT 'Azure Entra tenant GUID',
    client_id       VARCHAR(100) NULL COMMENT 'App registration client ID',
    client_secret   VARCHAR(500) NULL COMMENT 'Encrypted; never returned in API responses',
    oidc_authority  VARCHAR(500) NULL COMMENT 'e.g. https://login.microsoftonline.com/{tenant}',
    -- LDAP fields
    ldap_host       VARCHAR(255) NULL,
    ldap_port       SMALLINT UNSIGNED NULL DEFAULT 389,
    ldap_base_dn    VARCHAR(500) NULL,
    ldap_bind_dn    VARCHAR(500) NULL,
    ldap_bind_pass  VARCHAR(500) NULL COMMENT 'Encrypted',
    ldap_use_tls    TINYINT(1) NOT NULL DEFAULT 1,
    -- Config
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_provider_type (provider_type),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps Azure Entra OU/Department/Group attribute values → NexAlert org_node
-- e.g. Entra department="Engineering" + office="Chicago" → org_node_id 42
CREATE TABLE entra_org_mappings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auth_provider_id INT UNSIGNED NOT NULL,
    entra_attribute VARCHAR(100) NOT NULL COMMENT 'Entra claim name: department, officeLocation, jobTitle, etc.',
    entra_value     VARCHAR(255) NOT NULL COMMENT 'Value to match (exact or prefix)',
    match_type      ENUM('exact','prefix','contains') NOT NULL DEFAULT 'exact',
    org_node_id     INT UNSIGNED NOT NULL COMMENT 'Target NexAlert org node for this match',
    sets_home_org   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'If 1, this mapping sets the users home org',
    priority        SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Lower number = evaluated first',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entramapping_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_entramapping_node FOREIGN KEY (org_node_id) REFERENCES org_nodes(id) ON DELETE CASCADE,
    INDEX idx_provider (auth_provider_id),
    INDEX idx_attribute (entra_attribute, entra_value(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 4: USERS
-- Core user record. Auth identity is separate from contact info.
-- =============================================================================

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Identity
    username        VARCHAR(100) NOT NULL UNIQUE,
    display_name    VARCHAR(200) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    avatar_url      VARCHAR(500) NULL,
    -- Home org (can be overridden from Entra mapping)
    home_org_id     INT UNSIGNED NOT NULL COMMENT 'Primary organization for branding and admin ownership',
    home_node_id    INT UNSIGNED NULL COMMENT 'Primary org_node placement (e.g. WHNT > Engineering)',
    home_override   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'If 1, home was manually set and Entra sync will not overwrite it',
    -- Auth
    auth_provider_id INT UNSIGNED NULL COMMENT 'NULL = local auth only',
    external_id     VARCHAR(255) NULL COMMENT 'Entra object ID or LDAP DN',
    local_password_hash VARCHAR(255) NULL COMMENT 'bcrypt; NULL if external auth only',
    -- Status
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    -- Preferences
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone        VARCHAR(60) NOT NULL DEFAULT 'America/Chicago',
    -- Timestamps
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_home_org FOREIGN KEY (home_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_home_node FOREIGN KEY (home_node_id) REFERENCES org_nodes(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_auth_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers(id) ON DELETE SET NULL,
    INDEX idx_username (username),
    INDEX idx_external_id (auth_provider_id, external_id),
    INDEX idx_home_org (home_org_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset and email verification tokens
CREATE TABLE user_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_type      ENUM('password_reset','email_verify','invite','contact_verify') NOT NULL,
    token_hash      VARCHAR(128) NOT NULL UNIQUE COMMENT 'SHA256 of raw token; raw token sent to user',
    payload         JSON NULL COMMENT 'Extra context e.g. {"contact_id": 5, "channel": "sms"}',
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usertoken_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_type (user_id, token_type),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 5: USER ORG MEMBERSHIPS
-- A user can belong to multiple org nodes (multi-org). Home is tracked on users table.
-- =============================================================================

CREATE TABLE user_org_memberships (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    org_id          INT UNSIGNED NOT NULL,
    org_node_id     INT UNSIGNED NOT NULL COMMENT 'Specific node in the org tree the user belongs to',
    position_title  VARCHAR(150) NULL COMMENT 'Job title within this node e.g. Chief Engineer',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at         DATETIME NULL,
    added_by        INT UNSIGNED NULL COMMENT 'user_id of admin who added this membership',
    CONSTRAINT fk_uom_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uom_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_uom_node FOREIGN KEY (org_node_id) REFERENCES org_nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_uom_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_node (user_id, org_node_id),
    INDEX idx_user (user_id),
    INDEX idx_org (org_id),
    INDEX idx_node (org_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 6: CONTACT INFORMATION
-- Separate from user identity. Each contact method is individually verified.
-- =============================================================================

CREATE TABLE user_contacts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    channel         ENUM('email','sms','push_web','push_fcm') NOT NULL,
    contact_value   VARCHAR(320) NOT NULL COMMENT 'Email address, E.164 phone number, or push endpoint URL',
    label           VARCHAR(50) NULL COMMENT 'e.g. Work Email, Personal Cell',
    is_primary      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary contact for this channel',
    is_verified     TINYINT(1) NOT NULL DEFAULT 0,
    verified_at     DATETIME NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_channel (user_id, channel),
    INDEX idx_value (contact_value(100)),
    INDEX idx_primary (user_id, channel, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS-specific opt-in consent lifecycle (Twilio A2P 10DLC compliance)
CREATE TABLE user_sms_consent (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    contact_id      INT UNSIGNED NOT NULL COMMENT 'Links to user_contacts.id for the SMS number',
    phone_e164      VARCHAR(20) NOT NULL COMMENT 'E.164 format e.g. +12565551234; denormalized for fast lookup',
    -- Consent lifecycle
    status          ENUM('pending','invite_sent','opt_in_sent','confirmed','denied','expired','stopped') NOT NULL DEFAULT 'pending',
    -- Tracking
    invite_sent_at  DATETIME NULL COMMENT 'When the pre-notification email was sent',
    opt_in_sent_at  DATETIME NULL COMMENT 'When the Twilio opt-in SMS was sent',
    confirmed_at    DATETIME NULL,
    denied_at       DATETIME NULL,
    stopped_at      DATETIME NULL COMMENT 'User replied STOP at any time',
    expired_at      DATETIME NULL,
    -- Re-invite management
    invite_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of opt-in SMSes sent; used to enforce cooldown',
    last_invite_at  DATETIME NULL,
    next_invite_eligible_at DATETIME NULL COMMENT 'Cooldown: admin cannot re-invite before this date',
    -- Source
    initiated_by    INT UNSIGNED NULL COMMENT 'user_id of admin who triggered initial invite; NULL = system',
    twilio_message_sid VARCHAR(50) NULL COMMENT 'Last Twilio message SID for the opt-in SMS',
    -- Timestamps
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_smsconsent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_smsconsent_contact FOREIGN KEY (contact_id) REFERENCES user_contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_smsconsent_initiator FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_contact (contact_id),
    INDEX idx_user (user_id),
    INDEX idx_phone (phone_e164),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 7: ROLES & PERMISSIONS (RBAC)
-- Feature-based permissions. Roles are scoped to an org or global.
-- =============================================================================

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80) NOT NULL UNIQUE COMMENT 'e.g. super_admin, org_admin, group_admin, sender, recipient',
    display_name    VARCHAR(150) NOT NULL,
    description     TEXT NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL UNIQUE COMMENT 'e.g. alert.send, user.manage, tag.manage_exclusive',
    display_name    VARCHAR(200) NOT NULL,
    category        VARCHAR(60) NOT NULL COMMENT 'Grouping for UI: alerts, users, tags, orgs, reports',
    description     TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User role assignments are scoped: global (org_id NULL) or per-org
CREATE TABLE user_roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    role_id         INT UNSIGNED NOT NULL,
    org_id          INT UNSIGNED NULL COMMENT 'NULL = global scope; set = scoped to this org',
    org_node_id     INT UNSIGNED NULL COMMENT 'NULL = whole org; set = scoped to this subtree',
    granted_by      INT UNSIGNED NULL,
    granted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL COMMENT 'NULL = no expiry',
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_node FOREIGN KEY (org_node_id) REFERENCES org_nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_granted FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_user_role_scope (user_id, role_id, org_id, org_node_id),
    INDEX idx_user (user_id),
    INDEX idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles
INSERT INTO roles (name, display_name, description, is_system) VALUES
('super_admin',  'Super Administrator', 'Full system access across all orgs', 1),
('org_admin',    'Organization Admin',  'Manages users, groups, and tags within their org', 1),
('group_admin',  'Group Admin',         'Manages members and tags within assigned groups', 1),
('sender',       'Alert Sender',        'Can compose and send alerts', 1),
('recipient',    'Recipient',           'Receives alerts; can manage own contact info', 1);

-- Seed permissions
INSERT INTO permissions (name, display_name, category) VALUES
-- Alerts
('alert.send',              'Send Alerts',                  'alerts'),
('alert.send.critical',     'Send Critical/Evacuation',     'alerts'),
('alert.view_all',          'View All Alert History',       'alerts'),
('alert.manage',            'Manage Alert Templates',       'alerts'),
-- Users
('user.view',               'View Users',                   'users'),
('user.manage',             'Create/Edit/Deactivate Users', 'users'),
('user.import',             'Import Users',                 'users'),
('user.manage_roles',       'Assign Roles',                 'users'),
-- Tags
('tag.view',                'View Tags',                    'tags'),
('tag.manage',              'Create/Edit Standard Tags',    'tags'),
('tag.manage_exclusive',    'Manage Exclusive Tags',        'tags'),
('tag.approve_requests',    'Approve Tag Requests',         'tags'),
-- Orgs & Groups
('org.manage',              'Manage Organizations',         'orgs'),
('org.node.manage',         'Manage Org Tree Nodes',        'orgs'),
('group.manage',            'Manage Groups',                'groups'),
-- System
('system.token.manage',     'Manage API System Tokens',     'system'),
('system.audit.view',       'View Audit Logs',              'system'),
('system.config',           'System Configuration',         'system');

-- =============================================================================
-- SECTION 8: TAGS
-- Tags are flat with optional scoping. Inheritance is handled by the alert
-- targeting engine at resolve time, not stored on the tag itself.
-- =============================================================================

CREATE TABLE tags (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL COMMENT 'e.g. Engineering, Transmission, On-Call',
    slug            VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL-safe, system identifier',
    description     TEXT NULL,
    -- Ownership & scoping
    owner_org_id    INT UNSIGNED NULL COMMENT 'NULL = global tag; set = org-scoped tag',
    -- Access control
    is_exclusive    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'If 1, only tag admin or super_admin can assign',
    tag_admin_id    INT UNSIGNED NULL COMMENT 'User designated as this tags admin (for exclusive tags)',
    -- Self-service
    allow_self_request TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Users can request this tag (pending approval)',
    requires_approval  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'All assignments require admin approval',
    -- Visibility
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_system       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-generated from org tree; not manually editable',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tag_org FOREIGN KEY (owner_org_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tag_admin FOREIGN KEY (tag_admin_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tag_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_owner_org (owner_org_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Direct tag assignments to users (manual or approved requests)
CREATE TABLE tag_assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_id          INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    -- Source of assignment
    assignment_type ENUM('auto_org','auto_node','manual','approved_request') NOT NULL,
    source_node_id  INT UNSIGNED NULL COMMENT 'For auto_node: the org_node that triggered this tag',
    -- Approval tracking
    assigned_by     INT UNSIGNED NULL COMMENT 'Admin who assigned or approved',
    assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_ta_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_node FOREIGN KEY (source_node_id) REFERENCES org_nodes(id) ON DELETE SET NULL,
    CONSTRAINT fk_ta_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tag_user (tag_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-initiated tag requests (pending admin approval)
CREATE TABLE tag_approval_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_id          INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL COMMENT 'User requesting the tag',
    requested_by    INT UNSIGNED NOT NULL COMMENT 'Could be the user or an admin requesting on their behalf',
    justification   TEXT NULL,
    status          ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    review_notes    TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tar_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    CONSTRAINT fk_tar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tar_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tar_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 9: GROUPS
-- Groups are independent of the org tree. A group can span orgs.
-- Groups of groups allow nested distribution lists.
-- =============================================================================

CREATE TABLE `groups` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_org_id    INT UNSIGNED NOT NULL COMMENT 'Org that owns/administers this group',
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_group_org FOREIGN KEY (owner_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_group_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_org_slug (owner_org_id, slug),
    INDEX idx_org (owner_org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Direct user members of a group
CREATE TABLE group_memberships (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    added_by        INT UNSIGNED NULL,
    added_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_group_user (group_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups of groups (child group members are resolved recursively at alert time)
-- Cycle detection must be enforced at the application layer.
CREATE TABLE group_children (
    parent_group_id INT UNSIGNED NOT NULL,
    child_group_id  INT UNSIGNED NOT NULL,
    added_by        INT UNSIGNED NULL,
    added_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_group_id, child_group_id),
    CONSTRAINT fk_gc_parent FOREIGN KEY (parent_group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gc_child FOREIGN KEY (child_group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gc_added FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    CHECK (parent_group_id != child_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 10: SYSTEM API TOKENS
-- Scoped tokens for external systems (XPression, CheckMK, etc.) to POST alerts.
-- =============================================================================

CREATE TABLE system_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL COMMENT 'e.g. CheckMK Production, XPression Chicago',
    token_hash      VARCHAR(128) NOT NULL UNIQUE COMMENT 'SHA256 of raw bearer token',
    owner_org_id    INT UNSIGNED NOT NULL COMMENT 'Alerts sent by this token are attributed to this org',
    -- Permissions scope for this token
    allowed_severity  SET('test','info','notice','warning','critical','evacuation') NOT NULL DEFAULT 'test,info,notice,warning',
    allowed_alert_types SET('simple','ack_required','poll','chat','group_chat') NOT NULL DEFAULT 'simple,ack_required',
    -- Optional IP allowlist (comma-separated CIDRs; NULL = any IP)
    ip_allowlist    TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at    DATETIME NULL,
    last_used_ip    VARCHAR(45) NULL,
    expires_at      DATETIME NULL COMMENT 'NULL = no expiry',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_org FOREIGN KEY (owner_org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_token_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_token_hash (token_hash),
    INDEX idx_org (owner_org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 11: ALERTS
-- Core alert record + flexible targeting expression + per-recipient delivery tracking
-- =============================================================================

CREATE TABLE alerts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Origin
    created_by_user INT UNSIGNED NULL COMMENT 'NULL if created by system token',
    created_by_token INT UNSIGNED NULL COMMENT 'system_tokens.id; NULL if created by user',
    org_id          INT UNSIGNED NOT NULL COMMENT 'Owning org of this alert',
    -- Classification
    alert_type      ENUM('simple','ack_required','poll','chat','group_chat') NOT NULL,
    severity        ENUM('test','info','notice','warning','critical','evacuation') NOT NULL,
    -- Content
    subject         VARCHAR(255) NOT NULL,
    body            TEXT NOT NULL,
    body_html       TEXT NULL COMMENT 'Optional rich HTML version for email',
    attachments     JSON NULL COMMENT '[{"filename":"...", "url":"...", "mime":"..."}]',
    -- Delivery channels requested
    channels        SET('email','sms','push_web','push_fcm','in_app') NOT NULL,
    -- Timing
    send_at         DATETIME NULL COMMENT 'NULL = send immediately; future = scheduled',
    ttl_minutes     SMALLINT UNSIGNED NULL COMMENT 'Alert expires after this many minutes; NULL = no expiry',
    expires_at      DATETIME NULL COMMENT 'Computed from ttl_minutes at send time',
    -- Acknowledgement / poll config
    ack_required    TINYINT(1) NOT NULL DEFAULT 0,
    ack_deadline_minutes SMALLINT UNSIGNED NULL COMMENT 'Escalate if not acked within this window',
    escalation_user_id INT UNSIGNED NULL COMMENT 'User to notify if ack deadline is missed',
    poll_question   VARCHAR(500) NULL,
    poll_options    JSON NULL COMMENT '["Yes","No","Maybe"] for poll-type alerts',
    -- Status
    status          ENUM('draft','scheduled','sending','sent','cancelled','expired') NOT NULL DEFAULT 'draft',
    sent_at         DATETIME NULL,
    cancelled_at    DATETIME NULL,
    cancelled_by    INT UNSIGNED NULL,
    -- Metadata
    external_ref    VARCHAR(255) NULL COMMENT 'Reference from the originating system e.g. CheckMK alert ID',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_alert_user FOREIGN KEY (created_by_user) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_token FOREIGN KEY (created_by_token) REFERENCES system_tokens(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_alert_escalation FOREIGN KEY (escalation_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_org (org_id),
    INDEX idx_status (status),
    INDEX idx_severity (severity),
    INDEX idx_send_at (send_at),
    INDEX idx_created_by_user (created_by_user),
    INDEX idx_external_ref (external_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Targeting expressions for an alert. Multiple rows = union (OR) of targets.
-- Each row is an AND expression: all non-null fields must match.
-- e.g. Row 1: org_id=1, tag_id=5 → "All NewsNation users with Engineering tag"
--      Row 2: group_id=3         → "Plus all members of NOC group"
CREATE TABLE alert_targets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL,
    -- Targeting dimensions (all non-null fields are ANDed together)
    target_org_id   INT UNSIGNED NULL COMMENT 'Users in this org (any node)',
    target_node_id  INT UNSIGNED NULL COMMENT 'Users in this org_node subtree',
    target_group_id INT UNSIGNED NULL COMMENT 'Members of this group (recursive)',
    target_tag_id   INT UNSIGNED NULL COMMENT 'Users with this tag',
    target_user_id  INT UNSIGNED NULL COMMENT 'Direct individual target',
    -- Label for display in UI
    target_label    VARCHAR(255) NULL COMMENT 'Human-readable description of this target row',
    CONSTRAINT fk_at_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_org FOREIGN KEY (target_org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_node FOREIGN KEY (target_node_id) REFERENCES org_nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_group FOREIGN KEY (target_group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_tag FOREIGN KEY (target_tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-recipient, per-channel delivery record. Created when alert is resolved and dispatched.
CREATE TABLE alert_deliveries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    contact_id      INT UNSIGNED NOT NULL COMMENT 'user_contacts.id used for this delivery',
    channel         ENUM('email','sms','push_web','push_fcm','in_app') NOT NULL,
    -- Status
    status          ENUM('queued','sent','delivered','failed','skipped','bounced') NOT NULL DEFAULT 'queued',
    skip_reason     VARCHAR(100) NULL COMMENT 'e.g. sms_not_consented, contact_unverified',
    -- Provider tracking
    provider_message_id VARCHAR(255) NULL COMMENT 'Twilio SID, SendGrid message ID, etc.',
    provider_response   JSON NULL COMMENT 'Raw provider response for debugging',
    -- Timing
    queued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME NULL,
    delivered_at    DATETIME NULL,
    failed_at       DATETIME NULL,
    -- Retry
    retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at   DATETIME NULL,
    CONSTRAINT fk_ad_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_contact FOREIGN KEY (contact_id) REFERENCES user_contacts(id) ON DELETE CASCADE,
    INDEX idx_alert (alert_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_channel (channel),
    INDEX idx_queued (queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acknowledgement records for ack_required alerts
CREATE TABLE alert_acks (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    ack_channel     ENUM('web','sms','email','app','api') NOT NULL COMMENT 'How the ack was received',
    ack_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes           TEXT NULL COMMENT 'Optional response note from recipient',
    CONSTRAINT fk_ack_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ack_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_alert_user (alert_id, user_id),
    INDEX idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 12: POLLS
-- For poll-type alerts. Responses stored here.
-- =============================================================================

CREATE TABLE poll_responses (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    response_value  VARCHAR(255) NOT NULL COMMENT 'Must match one of alerts.poll_options values',
    response_channel ENUM('web','sms','email','app') NOT NULL,
    responded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_alert_user (alert_id, user_id),
    INDEX idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 13: CHAT
-- Chat threads are associated with alerts (chat/group_chat types).
-- SMS participants join the same thread via phone number lookup.
-- =============================================================================

CREATE TABLE chat_threads (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL UNIQUE COMMENT 'One thread per alert',
    thread_type     ENUM('chat','group_chat') NOT NULL COMMENT 'chat = replies to originator only; group_chat = all see all',
    is_open         TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Originator can close the thread',
    closed_at       DATETIME NULL,
    closed_by       INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ct_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_closed FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_messages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    -- Channel the message arrived on
    source_channel  ENUM('web','sms','app') NOT NULL DEFAULT 'web',
    body            TEXT NOT NULL,
    -- For SMS: the inbound Twilio SID for dedup
    twilio_message_sid VARCHAR(50) NULL,
    -- Read receipts (stored as JSON array of user_ids for efficiency)
    read_by         JSON NULL COMMENT '[user_id, ...] who have read this message',
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cm_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_thread (thread_id),
    INDEX idx_created (created_at),
    INDEX idx_twilio_sid (twilio_message_sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 14: PUSH SUBSCRIPTIONS (Web Push / VAPID)
-- Stored per browser/device. Users may have multiple push subscriptions.
-- =============================================================================

CREATE TABLE push_subscriptions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    -- Web Push API subscription object fields
    endpoint        TEXT NOT NULL,
    p256dh          VARCHAR(255) NOT NULL COMMENT 'VAPID public key',
    auth_key        VARCHAR(100) NOT NULL COMMENT 'VAPID auth secret',
    -- Device info (from User-Agent at subscription time)
    device_label    VARCHAR(150) NULL COMMENT 'e.g. Chrome on Windows',
    user_agent      VARCHAR(500) NULL,
    -- Status
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at    DATETIME NULL,
    failed_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Increment on 410 Gone; deactivate at threshold',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 15: AUDIT LOG
-- Immutable append-only log. Never UPDATE or DELETE rows in this table.
-- =============================================================================

CREATE TABLE audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Actor
    actor_user_id   INT UNSIGNED NULL COMMENT 'NULL = system or token action',
    actor_token_id  INT UNSIGNED NULL COMMENT 'system_tokens.id if action was via API',
    actor_ip        VARCHAR(45) NULL,
    -- Action
    action          VARCHAR(100) NOT NULL COMMENT 'e.g. user.created, alert.sent, tag.assigned, sms_consent.confirmed',
    -- Target entity
    entity_type     VARCHAR(60) NULL COMMENT 'e.g. user, alert, tag, group',
    entity_id       VARCHAR(40) NULL COMMENT 'ID of the affected entity (stored as string for flexibility)',
    -- Detail
    detail          JSON NULL COMMENT 'Before/after snapshot or action-specific payload',
    -- Timestamp
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor_user (actor_user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 16: USER SESSIONS
-- For local/LDAP auth sessions. Entra sessions are managed via OIDC.
-- =============================================================================

CREATE TABLE user_sessions (
    id              VARCHAR(128) NOT NULL PRIMARY KEY COMMENT 'Cryptographically random session ID',
    user_id         INT UNSIGNED NOT NULL,
    auth_method     ENUM('local','ldap','entra') NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    user_agent      VARCHAR(500) NULL,
    -- Entra-specific
    entra_access_token  TEXT NULL COMMENT 'Encrypted; used for MS Graph calls on behalf of user',
    entra_refresh_token TEXT NULL COMMENT 'Encrypted',
    entra_token_expires DATETIME NULL,
    -- Session lifecycle
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    revoked_at      DATETIME NULL,
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECTION 17: NOTIFICATION PREFERENCES
-- Per-user, per-severity channel preferences. System can override for critical/evac.
-- =============================================================================

CREATE TABLE user_notification_prefs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    severity        ENUM('test','info','notice','warning','critical','evacuation') NOT NULL,
    channel_email   TINYINT(1) NOT NULL DEFAULT 1,
    channel_sms     TINYINT(1) NOT NULL DEFAULT 0,
    channel_push    TINYINT(1) NOT NULL DEFAULT 1,
    channel_in_app  TINYINT(1) NOT NULL DEFAULT 1,
    -- System can force override (e.g. evacuation always sends all channels)
    system_override TINYINT(1) NOT NULL DEFAULT 0,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_severity (user_id, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END OF MIGRATION 001
-- =============================================================================