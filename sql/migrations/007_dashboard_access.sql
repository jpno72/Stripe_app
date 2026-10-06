-- Dashboard authorization; no existing business data or migrations changed.
CREATE TABLE dashboards (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 dashboard_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(100) NOT NULL,
 route VARCHAR(255) NOT NULL,
 is_enabled BOOLEAN NOT NULL DEFAULT 1,
 show_in_navigation BOOLEAN NOT NULL DEFAULT 1,
 navigation_order INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_dashboard_key (dashboard_key),
 KEY ix_dashboard_navigation (navigation_order),
 CONSTRAINT ck_dashboard_flags CHECK (is_enabled IN (0,1) AND show_in_navigation IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE role_dashboard_permissions (
 role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 dashboard_id BIGINT UNSIGNED NOT NULL,
 can_view BOOLEAN NOT NULL DEFAULT 0,
 PRIMARY KEY (role,dashboard_id),
 KEY ix_role_dashboard (dashboard_id),
 CONSTRAINT fk_role_dashboard FOREIGN KEY (dashboard_id) REFERENCES dashboards(id),
 CONSTRAINT ck_dashboard_role CHECK (role IN ('admin','analyst','viewer','staff')),
 CONSTRAINT ck_dashboard_permission CHECK (can_view IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_dashboard_overrides (
 user_id BIGINT UNSIGNED NOT NULL,
 dashboard_id BIGINT UNSIGNED NOT NULL,
 permission VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (user_id,dashboard_id),
 KEY ix_override_dashboard (dashboard_id),
 CONSTRAINT fk_override_user FOREIGN KEY (user_id) REFERENCES app_users(id),
 CONSTRAINT fk_override_dashboard FOREIGN KEY (dashboard_id) REFERENCES dashboards(id),
 CONSTRAINT ck_dashboard_override CHECK (permission IN ('ALLOW','DENY'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO dashboards (dashboard_key,display_name,route,navigation_order) VALUES
 ('overview','Dashboard','/index.php',10),
 ('revenue','Revenue','/revenue',20),
 ('membership','Membership','/membership',30),
 ('demographics','Demographics','/demographics',40),
 ('members','Members','/index.php?page=members',50),
 ('transactions','Transactions','/index.php?page=transactions',60),
 ('waivers','Waivers','/index.php?page=waivers',70),
 ('reports','Reports','/index.php?page=reports',80),
 ('imports','Imports','/admin/imports.php',90),
 ('matches','Matches','/admin/matches.php',100),
 ('settings','Settings','/admin/settings.php',110),
 ('users','User management','/admin/users.php',120);
INSERT INTO role_dashboard_permissions (role,dashboard_id,can_view)
SELECT r.role,d.id, CASE WHEN r.role='admin' THEN 1
 WHEN r.role='analyst' AND d.dashboard_key NOT IN ('imports','matches','settings','users') THEN 1
 WHEN r.role IN ('viewer','staff') AND d.dashboard_key IN ('overview','membership','demographics','members') THEN 1 ELSE 0 END
FROM dashboards d CROSS JOIN (SELECT 'admin' AS role UNION ALL SELECT 'analyst' UNION ALL SELECT 'viewer' UNION ALL SELECT 'staff') r;
