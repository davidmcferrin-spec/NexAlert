-- Migration 010: Allow org admins to manage system API tokens
-- Run after 009_escalation_group.sql

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'org_admin' AND p.name = 'system.token.manage';
