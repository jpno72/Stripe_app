-- A reviewed allocation is separate from imported Stripe customer ownership.
-- One current allocation per transaction prevents allocating the full charge to siblings.
-- All corrections/removals retain before/after state in audit_log.
CREATE TABLE stripe_transaction_attributions (
    transaction_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    attribution_role VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verified_by BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (transaction_id),
    KEY ix_transaction_attribution_person (person_id),
    KEY ix_transaction_attribution_reviewer (verified_by),
    CONSTRAINT fk_transaction_attribution_transaction FOREIGN KEY (transaction_id) REFERENCES stripe_transactions(id),
    CONSTRAINT fk_transaction_attribution_person FOREIGN KEY (person_id) REFERENCES people(id),
    CONSTRAINT fk_transaction_attribution_reviewer FOREIGN KEY (verified_by) REFERENCES app_users(id),
    CONSTRAINT ck_transaction_attribution_role CHECK (attribution_role IN ('person','payer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
