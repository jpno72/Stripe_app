-- Cross-person discovery cache and reviewed emergency-contact identities.
-- No source waiver values are copied. Identity links remain authoritative for students/signers.
CREATE TABLE waiver_association_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 person_id BIGINT UNSIGNED NOT NULL,
 waiver_id BIGINT UNSIGNED NOT NULL,
 source_role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 classification VARCHAR(20) NOT NULL,
 review_status VARCHAR(12) NOT NULL DEFAULT 'possible',
 evidence JSON NOT NULL,
 source_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reviewed_by BIGINT UNSIGNED DEFAULT NULL,
 reviewed_at DATETIME(6) DEFAULT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(id),
 UNIQUE KEY uq_waiver_association(person_id,waiver_id,source_role),
 KEY ix_waiver_association_queue(person_id,review_status,source_role),
 KEY ix_waiver_association_waiver(waiver_id,source_role,review_status),
 KEY ix_waiver_association_actor(reviewed_by),
 CONSTRAINT fk_waiver_association_person FOREIGN KEY(person_id) REFERENCES people(id),
 CONSTRAINT fk_waiver_association_waiver FOREIGN KEY(waiver_id) REFERENCES cognito_waivers(id),
 CONSTRAINT fk_waiver_association_actor FOREIGN KEY(reviewed_by) REFERENCES app_users(id),
 CONSTRAINT ck_waiver_association_role CHECK(source_role IN ('student','signer','emergency','payer','parent','guardian')),
 CONSTRAINT ck_waiver_association_status CHECK(review_status IN ('possible','confirmed','rejected','held')),
 CONSTRAINT ck_waiver_association_class CHECK(classification IN ('verified','strong','possible','ambiguous')),
 CONSTRAINT ck_waiver_association_evidence CHECK(JSON_TYPE(evidence)='ARRAY')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
