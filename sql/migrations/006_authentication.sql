-- Application authentication; existing applied migrations remain unchanged.
ALTER TABLE app_users
    ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    DROP CONSTRAINT ck_users_role,
    ADD CONSTRAINT ck_users_role CHECK (role IN ('admin','viewer','staff','analyst'));

CREATE TABLE auth_throttle (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    KEY ix_auth_throttle_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
