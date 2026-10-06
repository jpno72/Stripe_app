-- BMA Analytics initial schema. MariaDB >= 10.4 / MySQL >= 8.0.16.
-- Use bin/install.php against an empty, pre-created database. TRIGGER privilege required.
-- No source data, credentials, default users, destructive SQL, or disabled FK checks.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    email_normalized VARCHAR(254) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'viewer',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_users_email (email_normalized),
    CONSTRAINT ck_users_role CHECK (role IN ('admin','analyst','viewer')),
    CONSTRAINT ck_users_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE people (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bma_person_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_name VARCHAR(100) NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    preferred_name VARCHAR(100) NULL,
    email VARCHAR(254) NULL,
    email_normalized VARCHAR(254) NULL,
    phone VARCHAR(100) NULL,
    phone_normalized VARCHAR(32) NULL,
    birth_date DATE NULL,
    street_address VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    postal_code VARCHAR(32) NULL,
    country CHAR(2) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_people_bma (bma_person_id),
    KEY ix_people_email (email_normalized),
    KEY ix_people_phone (phone_normalized),
    KEY ix_people_name_birth (last_name, first_name, birth_date),
    CONSTRAINT ck_people_bma CHECK (bma_person_id LIKE 'BMA-%' AND CHAR_LENGTH(bma_person_id) > 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,
    member_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    member_since DATE NULL,
    member_until DATE NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_members_person (person_id),
    KEY ix_members_status (member_status, member_since),
    CONSTRAINT ck_member_status CHECK (member_status IN ('unknown','active','inactive','former','trial')),
    CONSTRAINT ck_member_dates CHECK (member_until IS NULL OR member_since IS NULL OR member_until >= member_since),
    CONSTRAINT fk_members_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE person_relationships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,
    related_person_id BIGINT UNSIGNED NOT NULL,
    relationship_type VARCHAR(30) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_relationship (person_id, related_person_id, relationship_type),
    KEY ix_relationship_related (related_person_id, relationship_type),
    CONSTRAINT ck_relationship_type CHECK (relationship_type IN ('parent','guardian','payer','child','spouse','self')),
    CONSTRAINT ck_relationship_self CHECK ((relationship_type = 'self' AND person_id = related_person_id) OR (relationship_type <> 'self' AND person_id <> related_person_id)),
    CONSTRAINT fk_relationship_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_relationship_related FOREIGN KEY (related_person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(40) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_headers JSON NOT NULL,
    source_timezone VARCHAR(64) NULL,
    assumed_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    import_options JSON NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    inserted_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    KEY ix_batches_hash (source_type, file_hash),
    KEY ix_batches_status (status, started_at),
    KEY ix_batches_user (created_by),
    CONSTRAINT ck_batch_source CHECK (source_type IN ('stripe_customers','stripe_subscriptions','stripe_transactions','cognito_waivers')),
    CONSTRAINT ck_batch_status CHECK (status IN ('pending','running','completed','completed_with_errors','failed')),
    CONSTRAINT ck_batch_dates CHECK (completed_at IS NULL OR completed_at >= started_at),
    CONSTRAINT fk_batch_user FOREIGN KEY (created_by) REFERENCES app_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE raw_stripe_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number BIGINT UNSIGNED NOT NULL,
    source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_email VARCHAR(254) NULL,
    raw_values JSON NOT NULL,
    raw_record LONGBLOB NOT NULL,
    row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_raw_stripe_customers_row (import_batch_id, source_row_number),
    KEY ix_raw_stripe_customers_external (source_record_id),
    KEY ix_raw_stripe_customers_hash (row_hash),
    KEY ix_raw_stripe_customers_status (processing_status),
    CONSTRAINT ck_raw_stripe_customers_row CHECK (source_row_number > 0),
    CONSTRAINT ck_raw_stripe_customers_status CHECK (processing_status IN ('pending','inserted','updated','skipped','error')),
    CONSTRAINT fk_raw_stripe_customers_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE raw_stripe_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number BIGINT UNSIGNED NOT NULL,
    source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    raw_values JSON NOT NULL,
    raw_record LONGBLOB NOT NULL,
    row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_raw_stripe_subscriptions_row (import_batch_id, source_row_number),
    KEY ix_raw_stripe_subscriptions_external (source_record_id),
    KEY ix_raw_stripe_subscriptions_hash (row_hash),
    KEY ix_raw_stripe_subscriptions_status (processing_status),
    CONSTRAINT ck_raw_stripe_subscriptions_row CHECK (source_row_number > 0),
    CONSTRAINT ck_raw_stripe_subscriptions_status CHECK (processing_status IN ('pending','inserted','updated','skipped','error')),
    CONSTRAINT fk_raw_stripe_subscriptions_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE raw_stripe_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number BIGINT UNSIGNED NOT NULL,
    source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    raw_values JSON NOT NULL,
    raw_record LONGBLOB NOT NULL,
    row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_raw_stripe_transactions_row (import_batch_id, source_row_number),
    KEY ix_raw_stripe_transactions_external (source_record_id),
    KEY ix_raw_stripe_transactions_hash (row_hash),
    KEY ix_raw_stripe_transactions_status (processing_status),
    CONSTRAINT ck_raw_stripe_transactions_row CHECK (source_row_number > 0),
    CONSTRAINT ck_raw_stripe_transactions_status CHECK (processing_status IN ('pending','inserted','updated','skipped','error')),
    CONSTRAINT fk_raw_stripe_transactions_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE raw_cognito_waivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number BIGINT UNSIGNED NOT NULL,
    source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_email VARCHAR(254) NULL,
    raw_values JSON NOT NULL,
    raw_record LONGBLOB NOT NULL,
    row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_raw_cognito_waivers_row (import_batch_id, source_row_number),
    KEY ix_raw_cognito_waivers_external (source_record_id),
    KEY ix_raw_cognito_waivers_hash (row_hash),
    KEY ix_raw_cognito_waivers_status (processing_status),
    CONSTRAINT ck_raw_cognito_waivers_row CHECK (source_row_number > 0),
    CONSTRAINT ck_raw_cognito_waivers_status CHECK (processing_status IN ('pending','inserted','updated','skipped','error')),
    CONSTRAINT fk_raw_cognito_waivers_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_errors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id BIGINT UNSIGNED NOT NULL,
    source_row_number BIGINT UNSIGNED NULL,
    field_name VARCHAR(255) NULL,
    error_code VARCHAR(100) NOT NULL,
    error_message TEXT NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'error',
    raw_values JSON NULL,
    resolved_at DATETIME(6) NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_errors_batch_row (import_batch_id, source_row_number),
    KEY ix_errors_resolution (resolved_at),
    KEY ix_errors_user (resolved_by),
    CONSTRAINT ck_error_severity CHECK (severity IN ('warning','error')),
    CONSTRAINT fk_error_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_error_user FOREIGN KEY (resolved_by) REFERENCES app_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stripe_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    person_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NULL,
    description TEXT NULL,
    email VARCHAR(254) NULL,
    email_normalized VARCHAR(254) NULL,
    card_or_payment_method_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    address_line1 VARCHAR(255) NULL,
    address_city VARCHAR(100) NULL,
    address_postal_code VARCHAR(32) NULL,
    address_country CHAR(2) NULL,
    stripe_created_at DATETIME(6) NULL,
    total_spend_minor BIGINT NULL,
    payment_count BIGINT UNSIGNED NULL,
    refunded_volume_minor BIGINT NULL,
    dispute_losses_minor BIGINT NULL,
    summary_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    is_stub BOOLEAN NOT NULL DEFAULT FALSE,
    latest_raw_id BIGINT UNSIGNED NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_stripe_customer (stripe_customer_id),
    KEY ix_customer_person (person_id),
    KEY ix_customer_email (email_normalized),
    KEY ix_customer_raw (latest_raw_id),
    CONSTRAINT ck_customer_stub CHECK (is_stub IN (0,1)),
    CONSTRAINT ck_customer_money CHECK ((total_spend_minor IS NULL AND refunded_volume_minor IS NULL AND dispute_losses_minor IS NULL) OR summary_currency IS NOT NULL),
    CONSTRAINT fk_customer_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_customer_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_stripe_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stripe_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_product_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(255) NULL,
    description TEXT NULL,
    active BOOLEAN NULL,
    metadata JSON NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_stripe_product (stripe_product_id),
    CONSTRAINT ck_product_active CHECK (active IS NULL OR active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stripe_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_price_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    unit_amount_minor BIGINT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recurring_interval VARCHAR(30) NULL,
    interval_count INT UNSIGNED NULL,
    active BOOLEAN NULL,
    metadata JSON NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_stripe_price (stripe_price_id),
    KEY ix_price_product (product_id),
    CONSTRAINT ck_price_money CHECK (unit_amount_minor IS NULL OR currency IS NOT NULL),
    CONSTRAINT ck_price_interval CHECK (interval_count IS NULL OR interval_count > 0),
    CONSTRAINT ck_price_active CHECK (active IS NULL OR active IN (0,1)),
    CONSTRAINT fk_price_product FOREIGN KEY (product_id) REFERENCES stripe_products (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stripe_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_subscription_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    price_id BIGINT UNSIGNED NULL,
    product_name_snapshot VARCHAR(255) NULL,
    customer_name_snapshot VARCHAR(255) NULL,
    customer_email_snapshot VARCHAR(254) NULL,
    customer_description_snapshot TEXT NULL,
    recurring_interval VARCHAR(30) NULL,
    amount_minor BIGINT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(60) NOT NULL,
    coupon_reference VARCHAR(255) NULL,
    stripe_created_at DATETIME(6) NULL,
    canceled_at DATETIME(6) NULL,
    current_period_start DATETIME(6) NULL,
    current_period_end DATETIME(6) NULL,
    latest_raw_id BIGINT UNSIGNED NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_stripe_subscription (stripe_subscription_id),
    KEY ix_subscription_customer (customer_id),
    KEY ix_subscription_price (price_id),
    KEY ix_subscription_status (status, current_period_end),
    KEY ix_subscription_raw (latest_raw_id),
    CONSTRAINT ck_subscription_money CHECK (amount_minor IS NULL OR currency IS NOT NULL),
    CONSTRAINT fk_subscription_customer FOREIGN KEY (customer_id) REFERENCES stripe_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_subscription_price FOREIGN KEY (price_id) REFERENCES stripe_prices (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_subscription_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_stripe_subscriptions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_subscription_member (subscription_id, member_id, valid_from),
    KEY ix_subscription_member_member (member_id),
    CONSTRAINT ck_subscription_member_dates CHECK (valid_until IS NULL OR valid_until >= valid_from),
    CONSTRAINT fk_subscription_member_subscription FOREIGN KEY (subscription_id) REFERENCES stripe_subscriptions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_subscription_member_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stripe_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_charge_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stripe_invoice_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    stripe_payment_intent_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    customer_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    stripe_created_at DATETIME(6) NULL,
    amount_minor BIGINT NOT NULL,
    amount_refunded_minor BIGINT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    captured BOOLEAN NULL,
    converted_amount_minor BIGINT NULL,
    converted_amount_refunded_minor BIGINT NULL,
    converted_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    fee_minor BIGINT NULL,
    taxes_on_fee_minor BIGINT NULL,
    fee_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decline_reason TEXT NULL,
    description TEXT NULL,
    refunded_at DATETIME(6) NULL,
    statement_descriptor VARCHAR(255) NULL,
    status VARCHAR(60) NOT NULL,
    seller_message TEXT NULL,
    card_or_payment_method_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    customer_description_snapshot TEXT NULL,
    customer_email_snapshot VARCHAR(254) NULL,
    transfer_reference VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    latest_raw_id BIGINT UNSIGNED NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_stripe_charge (stripe_charge_id),
    KEY ix_transaction_invoice (stripe_invoice_id),
    KEY ix_transaction_intent (stripe_payment_intent_id),
    KEY ix_transaction_customer_date (customer_id, stripe_created_at),
    KEY ix_transaction_subscription (subscription_id),
    KEY ix_transaction_date_status (stripe_created_at, status),
    KEY ix_transaction_raw (latest_raw_id),
    CONSTRAINT ck_transaction_captured CHECK (captured IS NULL OR captured IN (0,1)),
    CONSTRAINT ck_transaction_converted CHECK ((converted_amount_minor IS NULL AND converted_amount_refunded_minor IS NULL) OR converted_currency IS NOT NULL),
    CONSTRAINT ck_transaction_fee CHECK ((fee_minor IS NULL AND taxes_on_fee_minor IS NULL) OR fee_currency IS NOT NULL),
    CONSTRAINT fk_transaction_customer FOREIGN KEY (customer_id) REFERENCES stripe_customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_transaction_subscription FOREIGN KEY (subscription_id) REFERENCES stripe_subscriptions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_transaction_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_stripe_transactions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cognito_waivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_record_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id_kind VARCHAR(30) NOT NULL,
    student_person_id BIGINT UNSIGNED NULL,
    signer_person_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME(6) NULL,
    source_timezone VARCHAR(64) NULL,
    student_name VARCHAR(255) NULL,
    contact_email VARCHAR(254) NULL,
    contact_phone VARCHAR(100) NULL,
    text_friendly BOOLEAN NULL,
    student_birth_date DATE NULL,
    address_text TEXT NULL,
    emergency_contact_name VARCHAR(255) NULL,
    emergency_contact_phone VARCHAR(100) NULL,
    considerations TEXT NULL,
    goals TEXT NULL,
    signer_print_name VARCHAR(255) NULL,
    signed_date DATE NULL,
    relationship_to_student VARCHAR(255) NULL,
    latest_raw_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_cognito_record (source_record_id),
    KEY ix_waiver_student (student_person_id),
    KEY ix_waiver_signer (signer_person_id),
    KEY ix_waiver_submitted (submitted_at),
    KEY ix_waiver_raw (latest_raw_id),
    CONSTRAINT ck_waiver_id_kind CHECK (source_id_kind IN ('external','file_row')),
    CONSTRAINT ck_waiver_text CHECK (text_friendly IS NULL OR text_friendly IN (0,1)),
    CONSTRAINT ck_waiver_timezone CHECK (submitted_at IS NULL OR source_timezone IS NOT NULL),
    CONSTRAINT fk_waiver_student FOREIGN KEY (student_person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_waiver_signer FOREIGN KEY (signer_person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_waiver_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_cognito_waivers (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE identity_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NULL,
    source_system VARCHAR(30) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    external_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_role VARCHAR(30) NOT NULL,
    match_method VARCHAR(60) NULL,
    match_score DECIMAL(5,4) NULL,
    match_status VARCHAR(30) NOT NULL DEFAULT 'unmatched',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    notes TEXT NULL,
    candidate_person_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(person_id, 0)) STORED,
    accepted_slot TINYINT GENERATED ALWAYS AS (CASE WHEN match_status IN ('automatic','confirmed') THEN 1 ELSE NULL END) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_identity_candidate (source_system, source_type, external_id, source_role, candidate_person_key),
    UNIQUE KEY uq_identity_accepted (source_system, source_type, external_id, source_role, accepted_slot),
    KEY ix_identity_person (person_id),
    KEY ix_identity_review (match_status, created_at),
    KEY ix_identity_reviewer (reviewed_by),
    CONSTRAINT ck_identity_source CHECK (source_system IN ('stripe','cognito','manual')),
    CONSTRAINT ck_identity_role CHECK (source_role IN ('customer','student','signer','person')),
    CONSTRAINT ck_identity_status CHECK (match_status IN ('automatic','confirmed','possible','rejected','unmatched')),
    CONSTRAINT ck_identity_score CHECK (match_score IS NULL OR match_score BETWEEN 0 AND 1),
    CONSTRAINT ck_identity_person CHECK ((match_status = 'unmatched' AND person_id IS NULL) OR (match_status <> 'unmatched' AND person_id IS NOT NULL)),
    CONSTRAINT ck_identity_review CHECK (match_status NOT IN ('confirmed','rejected') OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
    CONSTRAINT fk_identity_person FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_identity_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    setting_value JSON NOT NULL,
    description TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY ix_settings_user (updated_by),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES app_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_audit_actor_date (actor_user_id, created_at),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_date (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (actor_user_id) REFERENCES app_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve both internal identity fields when any other person data changes.
CREATE TRIGGER people_immutable_identity BEFORE UPDATE ON people
FOR EACH ROW SET NEW.id = OLD.id, NEW.bma_person_id = OLD.bma_person_id;

