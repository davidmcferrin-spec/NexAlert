-- Migration 005: Seed role_permissions for alert sending

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'sender' AND p.name IN ('alert.send', 'alert.view_all');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'org_admin' AND p.name IN ('alert.send', 'alert.view_all', 'alert.send.critical');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'super_admin' AND p.name IN ('alert.send', 'alert.send.critical', 'alert.view_all', 'alert.manage');
