-- Step 3: explainable cross-source suggestions. Prior migrations are immutable.
ALTER TABLE identity_links
    ADD COLUMN match_reason VARCHAR(100) NULL AFTER match_score,
    ADD COLUMN match_evidence LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (JSON_VALID(match_evidence)) AFTER match_reason;

ALTER TABLE cognito_waivers
    ADD INDEX ix_waiver_contact_email (contact_email),
    ADD INDEX ix_waiver_signer_name (signer_print_name),
    ADD INDEX ix_waiver_source_name (source_name);

CREATE TABLE identity_match_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stripe_customer_id BIGINT UNSIGNED NOT NULL,
    cognito_waiver_id BIGINT UNSIGNED NOT NULL,
    waiver_role VARCHAR(30) NOT NULL,
    match_score DECIMAL(5,4) NOT NULL,
    match_method VARCHAR(60) NOT NULL,
    match_reason VARCHAR(100) NOT NULL,
    match_status VARCHAR(30) NOT NULL,
    evidence LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (JSON_VALID(evidence)),
    hard_conflict TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_match_candidate_pair (stripe_customer_id, cognito_waiver_id, waiver_role),
    KEY ix_match_candidate_waiver (cognito_waiver_id, waiver_role),
    KEY ix_match_candidate_review (match_status, match_score),
    KEY ix_match_candidate_reviewer (reviewed_by),
    CONSTRAINT fk_candidate_customer FOREIGN KEY (stripe_customer_id) REFERENCES stripe_customers (id),
    CONSTRAINT fk_candidate_waiver FOREIGN KEY (cognito_waiver_id) REFERENCES cognito_waivers (id),
    CONSTRAINT fk_candidate_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users (id),
    CONSTRAINT ck_candidate_role CHECK (waiver_role IN ('signer','source_name_unresolved')),
    CONSTRAINT ck_candidate_score CHECK (match_score BETWEEN 0 AND 1),
    CONSTRAINT ck_candidate_status CHECK (match_status IN ('possible','unmatched','linked','conflict','confirmed','rejected')),
    CONSTRAINT ck_candidate_conflict CHECK (hard_conflict IN (0,1)),
    CONSTRAINT ck_candidate_review CHECK (match_status NOT IN ('confirmed','rejected') OR reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
