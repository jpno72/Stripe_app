-- Step 1 corrections. 001 remains immutable.
-- Runner refuses this migration if legacy raw/error/waiver data needs a retention review.
DROP TRIGGER people_immutable_identity;
ALTER TABLE people MODIFY bma_person_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL;

DELIMITER $$
CREATE TRIGGER people_allocate_identity BEFORE INSERT ON people FOR EACH ROW
BEGIN
    IF NEW.id <> 0 OR NEW.bma_person_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'People must use the database ID allocator';
    END IF;
END$$
CREATE TRIGGER people_immutable_identity BEFORE UPDATE ON people FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BMA internal person ID is immutable';
    END IF;
    IF OLD.bma_person_id IS NULL THEN
        IF NEW.bma_person_id IS NULL OR NEW.bma_person_id <> CONCAT('BMA-', LPAD(CAST(OLD.id AS CHAR), GREATEST(6, CHAR_LENGTH(CAST(OLD.id AS CHAR))), '0')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BMA person ID must derive from allocated internal ID';
        END IF;
    ELSEIF NOT (NEW.bma_person_id <=> OLD.bma_person_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BMA person ID is immutable';
    END IF;
END$$
DELIMITER ;

ALTER TABLE raw_stripe_customers
    DROP COLUMN raw_record,
    ADD CONSTRAINT ck_stripe_customers_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Description","Email","Name","Created (UTC)","Card ID","Address Line1","Address City","Address Postal Code","Address Country","Total Spend","Payment Count","Refunded Volume","Dispute Losses"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE raw_stripe_subscriptions
    DROP COLUMN raw_record,
    ADD CONSTRAINT ck_stripe_subscriptions_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Customer ID","Customer Description","Customer Email","Plan","Product","Interval","Amount","Status","Created (UTC)","Coupon","Canceled At (UTC)","Customer Name"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE raw_stripe_transactions
    DROP COLUMN raw_record,
    ADD CONSTRAINT ck_stripe_transactions_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Created date (UTC)","Amount","Amount Refunded","Currency","Captured","Converted Amount","Converted Amount Refunded","Converted Currency","Decline Reason","Description","Fee","Refunded date (UTC)","Statement Descriptor","Status","Seller Message","Taxes On Fee","Card ID","Customer ID","Customer Description","Customer Email","Invoice ID","Transfer"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE raw_cognito_waivers
    DROP COLUMN raw_record,
    ADD CONSTRAINT ck_cognito_waivers_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["Email","Date Submitted","Name","Phone","Text Friendly","Student Birthdate","Address","Print Name","Date","Relationship to student"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE import_errors DROP COLUMN raw_values;
ALTER TABLE import_batches
    ADD source_stripe_account_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD source_stripe_livemode BOOLEAN NULL,
    ADD source_identity_evidence VARCHAR(30) NULL,
    ADD CONSTRAINT ck_batch_livemode CHECK (source_stripe_livemode IS NULL OR source_stripe_livemode IN (0,1)),
    ADD CONSTRAINT ck_batch_evidence CHECK (source_identity_evidence IS NULL OR source_identity_evidence IN ('operator','stripe_api'));

ALTER TABLE cognito_waivers
    DROP CONSTRAINT ck_waiver_id_kind,
    DROP INDEX uq_cognito_record,
    MODIFY source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CHANGE student_name source_name VARCHAR(255) NULL,
    ADD form_namespace VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD source_locator VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD interpretation_status VARCHAR(20) NOT NULL DEFAULT 'unresolved',
    DROP COLUMN emergency_contact_name,
    DROP COLUMN emergency_contact_phone,
    DROP COLUMN considerations,
    DROP COLUMN goals,
    ADD UNIQUE KEY uq_cognito_record (form_namespace, source_record_id),
    ADD UNIQUE KEY uq_cognito_locator (form_namespace, source_locator),
    ADD CONSTRAINT ck_waiver_id_kind CHECK (source_id_kind IN ('external','fingerprint')),
    ADD CONSTRAINT ck_waiver_interpretation CHECK (interpretation_status IN ('unresolved','verified'));
ALTER TABLE raw_cognito_waivers
    ADD source_locator VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD KEY ix_raw_cognito_locator (source_locator);

CREATE TABLE stripe_subscription_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    stripe_subscription_item_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_locator VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_id BIGINT UNSIGNED NULL,
    product_name_snapshot VARCHAR(255) NULL,
    quantity BIGINT UNSIGNED NULL,
    amount_minor BIGINT NULL,
    amount_basis VARCHAR(30) NOT NULL DEFAULT 'csv_reported',
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    billing_interval VARCHAR(30) NULL,
    billing_interval_count INT UNSIGNED NULL,
    latest_raw_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_subscription_item_external (stripe_subscription_item_id),
    UNIQUE KEY uq_subscription_item_locator (subscription_id, source_locator),
    KEY ix_subscription_item_price (price_id),
    KEY ix_subscription_item_raw (latest_raw_id),
    CONSTRAINT ck_item_money CHECK (amount_minor IS NULL OR currency IS NOT NULL),
    CONSTRAINT ck_item_basis CHECK (amount_basis IN ('csv_reported','unit')),
    CONSTRAINT ck_item_interval CHECK (billing_interval_count IS NULL OR billing_interval_count > 0),
    CONSTRAINT ck_item_currency CHECK (currency IS NULL OR currency REGEXP '^[a-z]{3}$'),
    CONSTRAINT fk_item_subscription FOREIGN KEY (subscription_id) REFERENCES stripe_subscriptions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_item_price FOREIGN KEY (price_id) REFERENCES stripe_prices(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_item_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_stripe_subscriptions(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve any legacy subscription snapshot in one explicitly provisional CSV item.
INSERT INTO stripe_subscription_items (subscription_id, source_locator, price_id, product_name_snapshot, amount_minor, currency, billing_interval, latest_raw_id)
SELECT id, 'csv-single-item', price_id, product_name_snapshot, amount_minor, currency, recurring_interval, latest_raw_id FROM stripe_subscriptions;
ALTER TABLE stripe_subscriptions
    DROP FOREIGN KEY fk_subscription_price,
    DROP COLUMN price_id,
    DROP COLUMN product_name_snapshot,
    DROP COLUMN recurring_interval,
    ADD stripe_subscription_started_at DATETIME(6) NULL;

ALTER TABLE subscription_members
    ADD KEY ix_subscription_member_subscription (subscription_id),
    DROP INDEX uq_subscription_member,
    MODIFY valid_from DATE NULL,
    ADD valid_from_key DATE GENERATED ALWAYS AS (COALESCE(valid_from, '1000-01-01')) STORED,
    ADD UNIQUE KEY uq_subscription_member (subscription_id, member_id, valid_from_key);

ALTER TABLE stripe_transactions
    ADD stripe_balance_transaction_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD balance_amount_minor BIGINT NULL,
    ADD balance_fee_minor BIGINT NULL,
    ADD balance_net_minor BIGINT NULL,
    ADD balance_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD KEY ix_transaction_balance (stripe_balance_transaction_id),
    ADD CONSTRAINT ck_balance_money CHECK ((balance_amount_minor IS NULL AND balance_fee_minor IS NULL AND balance_net_minor IS NULL) OR balance_currency IS NOT NULL),
    ADD CONSTRAINT ck_balance_currency CHECK (balance_currency IS NULL OR balance_currency REGEXP '^[a-z]{3}$');

CREATE TABLE stripe_source_identity (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    stripe_account_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    stripe_livemode BOOLEAN NULL,
    established_at DATETIME(6) NULL,
    CONSTRAINT ck_stripe_singleton CHECK (id = 1),
    CONSTRAINT ck_stripe_mode CHECK (stripe_livemode IS NULL OR stripe_livemode IN (0,1)),
    CONSTRAINT ck_stripe_identity_pair CHECK ((stripe_account_id IS NULL AND stripe_livemode IS NULL AND established_at IS NULL) OR (stripe_account_id IS NOT NULL AND stripe_livemode IS NOT NULL AND established_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO stripe_source_identity (id) VALUES (1);

ALTER TABLE stripe_customers ADD CONSTRAINT ck_stripe_customers_summary_currency CHECK (summary_currency IS NULL OR summary_currency REGEXP '^[a-z]{3}$');
ALTER TABLE stripe_prices ADD CONSTRAINT ck_stripe_prices_currency CHECK (currency IS NULL OR currency REGEXP '^[a-z]{3}$');
ALTER TABLE stripe_subscriptions ADD CONSTRAINT ck_stripe_subscriptions_currency CHECK (currency IS NULL OR currency REGEXP '^[a-z]{3}$');
ALTER TABLE stripe_transactions ADD CONSTRAINT ck_stripe_transactions_currency CHECK (currency IS NULL OR currency REGEXP '^[a-z]{3}$');
ALTER TABLE stripe_transactions ADD CONSTRAINT ck_stripe_transactions_converted_currency CHECK (converted_currency IS NULL OR converted_currency REGEXP '^[a-z]{3}$');
ALTER TABLE stripe_transactions ADD CONSTRAINT ck_stripe_transactions_fee_currency CHECK (fee_currency IS NULL OR fee_currency REGEXP '^[a-z]{3}$');

DELIMITER $$
CREATE TRIGGER stripe_customers_identity_insert BEFORE INSERT ON stripe_customers FOR EACH ROW
BEGIN
    IF NOT (NEW.person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'stripe' AND source_type = 'customer' AND external_id = NEW.stripe_customer_id AND source_role = 'customer' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER stripe_customers_identity_update BEFORE UPDATE ON stripe_customers FOR EACH ROW
BEGIN
    IF NOT (NEW.person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'stripe' AND source_type = 'customer' AND external_id = NEW.stripe_customer_id AND source_role = 'customer' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER cognito_waivers_identity_insert BEFORE INSERT ON cognito_waivers FOR EACH ROW
BEGIN
    IF NOT (NEW.student_person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'cognito' AND source_type = 'waiver' AND external_id = NEW.source_locator AND source_role = 'student' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
    IF NOT (NEW.signer_person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'cognito' AND source_type = 'waiver' AND external_id = NEW.source_locator AND source_role = 'signer' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER cognito_waivers_identity_update BEFORE UPDATE ON cognito_waivers FOR EACH ROW
BEGIN
    IF NOT (NEW.student_person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'cognito' AND source_type = 'waiver' AND external_id = NEW.source_locator AND source_role = 'student' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
    IF NOT (NEW.signer_person_id <=> (SELECT MAX(person_id) FROM identity_links WHERE source_system = 'cognito' AND source_type = 'waiver' AND external_id = NEW.source_locator AND source_role = 'signer' AND match_status IN ('automatic','confirmed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use identity association service for person links';
    END IF;
END$$
DELIMITER ;


