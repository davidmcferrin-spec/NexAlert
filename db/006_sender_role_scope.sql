-- Migration 006: Scoped sender roles (group + tag anchors) and role-management seeds

ALTER TABLE user_roles
    ADD COLUMN group_id INT UNSIGNED NULL COMMENT 'Scoped to this group (sender/recipient envelope)' AFTER org_node_id,
    ADD COLUMN tag_id   INT UNSIGNED NULL COMMENT 'Scoped to users with this tag' AFTER group_id,
    ADD CONSTRAINT fk_ur_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_ur_tag   FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE;

ALTER TABLE user_roles
    DROP INDEX uq_user_role_scope,
    ADD UNIQUE KEY uq_user_role_scope (user_id, role_id, org_id, org_node_id, group_id, tag_id);

-- Org admins can assign roles within their org
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'org_admin'
  AND p.name IN ('user.view', 'user.manage', 'user.manage_roles');

-- Super admin gets explicit role-management permission (middleware also bypasses)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'super_admin' AND p.name = 'user.manage_roles';
