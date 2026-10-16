-- Consolidated current schema, generated after applying all versioned migrations.
-- Install with bin/install.php. Never reapply to an existing database.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

CREATE TABLE `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `applied_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) NOT NULL,
  `email_normalized` varchar(254) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'viewer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `auth_version` bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email_normalized`),
  CONSTRAINT `ck_users_active` CHECK (`is_active` in (0,1)),
  CONSTRAINT `ck_users_role` CHECK (`role` in ('admin','viewer','staff','analyst'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `people` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bma_person_id` varchar(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `preferred_name` varchar(100) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `email_normalized` varchar(254) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `phone_normalized` varchar(32) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(32) DEFAULT NULL,
  `country` char(2) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_people_bma` (`bma_person_id`),
  KEY `ix_people_email` (`email_normalized`),
  KEY `ix_people_phone` (`phone_normalized`),
  KEY `ix_people_name_birth` (`last_name`,`first_name`,`birth_date`),
  CONSTRAINT `ck_people_bma` CHECK (`bma_person_id` like 'BMA-%' and char_length(`bma_person_id`) > 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint(20) unsigned NOT NULL,
  `member_status` varchar(30) NOT NULL DEFAULT 'unknown',
  `member_since` date DEFAULT NULL,
  `member_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_members_person` (`person_id`),
  KEY `ix_members_status` (`member_status`,`member_since`),
  CONSTRAINT `fk_members_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `ck_member_status` CHECK (`member_status` in ('unknown','active','inactive','former','trial')),
  CONSTRAINT `ck_member_dates` CHECK (`member_until` is null or `member_since` is null or `member_until` >= `member_since`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `person_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint(20) unsigned NOT NULL,
  `related_person_id` bigint(20) unsigned NOT NULL,
  `relationship_type` varchar(30) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_relationship` (`person_id`,`related_person_id`,`relationship_type`),
  KEY `ix_relationship_related` (`related_person_id`,`relationship_type`),
  CONSTRAINT `fk_relationship_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `fk_relationship_related` FOREIGN KEY (`related_person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `ck_relationship_type` CHECK (`relationship_type` in ('parent','guardian','payer','child','spouse','self')),
  CONSTRAINT `ck_relationship_self` CHECK (`relationship_type` = 'self' and `person_id` = `related_person_id` or `relationship_type` <> 'self' and `person_id` <> `related_person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(40) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`source_headers`)),
  `source_timezone` varchar(64) DEFAULT NULL,
  `assumed_currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `import_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`import_options`)),
  `started_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `completed_at` datetime(6) DEFAULT NULL,
  `row_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `inserted_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `skipped_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `error_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `source_stripe_account_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_stripe_livemode` tinyint(1) DEFAULT NULL,
  `source_identity_evidence` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_batches_hash` (`source_type`,`file_hash`),
  KEY `ix_batches_status` (`status`,`started_at`),
  KEY `ix_batches_user` (`created_by`),
  CONSTRAINT `fk_batch_user` FOREIGN KEY (`created_by`) REFERENCES `app_users` (`id`),
  CONSTRAINT `ck_batch_source` CHECK (`source_type` in ('stripe_customers','stripe_subscriptions','stripe_transactions','cognito_waivers')),
  CONSTRAINT `ck_batch_status` CHECK (`status` in ('pending','running','completed','completed_with_errors','failed')),
  CONSTRAINT `ck_batch_dates` CHECK (`completed_at` is null or `completed_at` >= `started_at`),
  CONSTRAINT `ck_batch_livemode` CHECK (`source_stripe_livemode` is null or `source_stripe_livemode` in (0,1)),
  CONSTRAINT `ck_batch_evidence` CHECK (`source_identity_evidence` is null or `source_identity_evidence` in ('operator','stripe_api'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raw_stripe_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) unsigned NOT NULL,
  `source_row_number` bigint(20) unsigned NOT NULL,
  `source_record_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_email` varchar(254) DEFAULT NULL,
  `raw_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_values`)),
  `row_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `processing_status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raw_stripe_customers_row` (`import_batch_id`,`source_row_number`),
  KEY `ix_raw_stripe_customers_external` (`source_record_id`),
  KEY `ix_raw_stripe_customers_hash` (`row_hash`),
  KEY `ix_raw_stripe_customers_status` (`processing_status`),
  CONSTRAINT `fk_raw_stripe_customers_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  CONSTRAINT `ck_raw_stripe_customers_row` CHECK (`source_row_number` > 0),
  CONSTRAINT `ck_raw_stripe_customers_status` CHECK (`processing_status` in ('pending','inserted','updated','skipped','error')),
  CONSTRAINT `ck_stripe_customers_approved` CHECK (json_type(`raw_values`) = 'OBJECT' and json_contains('["id","Email","Name","Created (UTC)","Address Line1","Address City","Address Postal Code","Address Country","Total Spend","Payment Count","Refunded Volume","Dispute Losses"]',json_keys(`raw_values`)) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raw_stripe_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) unsigned NOT NULL,
  `source_row_number` bigint(20) unsigned NOT NULL,
  `source_record_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_customer_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `raw_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_values`)),
  `row_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `processing_status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raw_stripe_subscriptions_row` (`import_batch_id`,`source_row_number`),
  KEY `ix_raw_stripe_subscriptions_external` (`source_record_id`),
  KEY `ix_raw_stripe_subscriptions_hash` (`row_hash`),
  KEY `ix_raw_stripe_subscriptions_status` (`processing_status`),
  CONSTRAINT `fk_raw_stripe_subscriptions_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  CONSTRAINT `ck_raw_stripe_subscriptions_row` CHECK (`source_row_number` > 0),
  CONSTRAINT `ck_raw_stripe_subscriptions_status` CHECK (`processing_status` in ('pending','inserted','updated','skipped','error')),
  CONSTRAINT `ck_stripe_subscriptions_approved` CHECK (json_type(`raw_values`) = 'OBJECT' and json_contains('["id","Customer ID","Customer Email","Plan","Product","Interval","Amount","Status","Created (UTC)","Coupon","Canceled At (UTC)","Customer Name"]',json_keys(`raw_values`)) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raw_stripe_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) unsigned NOT NULL,
  `source_row_number` bigint(20) unsigned NOT NULL,
  `source_record_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_customer_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `raw_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_values`)),
  `row_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `processing_status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raw_stripe_transactions_row` (`import_batch_id`,`source_row_number`),
  KEY `ix_raw_stripe_transactions_external` (`source_record_id`),
  KEY `ix_raw_stripe_transactions_hash` (`row_hash`),
  KEY `ix_raw_stripe_transactions_status` (`processing_status`),
  CONSTRAINT `fk_raw_stripe_transactions_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  CONSTRAINT `ck_raw_stripe_transactions_row` CHECK (`source_row_number` > 0),
  CONSTRAINT `ck_raw_stripe_transactions_status` CHECK (`processing_status` in ('pending','inserted','updated','skipped','error')),
  CONSTRAINT `ck_stripe_transactions_approved` CHECK (json_type(`raw_values`) = 'OBJECT' and json_contains('["id","Created date (UTC)","Amount","Amount Refunded","Currency","Captured","Converted Amount","Converted Amount Refunded","Converted Currency","Decline Reason","Description","Fee","Refunded date (UTC)","Statement Descriptor","Status","Seller Message","Taxes On Fee","Customer ID","Customer Email","Invoice ID","Transfer"]',json_keys(`raw_values`)) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `raw_cognito_waivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) unsigned NOT NULL,
  `source_row_number` bigint(20) unsigned NOT NULL,
  `source_record_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_email` varchar(254) DEFAULT NULL,
  `raw_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_values`)),
  `row_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `processing_status` varchar(30) NOT NULL DEFAULT 'pending',
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `source_locator` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `content_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raw_cognito_waivers_row` (`import_batch_id`,`source_row_number`),
  KEY `ix_raw_cognito_waivers_external` (`source_record_id`),
  KEY `ix_raw_cognito_waivers_hash` (`row_hash`),
  KEY `ix_raw_cognito_waivers_status` (`processing_status`),
  KEY `ix_raw_cognito_locator` (`source_locator`),
  CONSTRAINT `fk_raw_cognito_waivers_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  CONSTRAINT `ck_raw_cognito_waivers_row` CHECK (`source_row_number` > 0),
  CONSTRAINT `ck_raw_cognito_waivers_status` CHECK (`processing_status` in ('pending','inserted','updated','skipped','error')),
  CONSTRAINT `ck_cognito_waivers_approved` CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND (JSON_CONTAINS('["Email","Date Submitted","Name","Phone","Text Friendly","Student Birthdate","Address","Print Name","Date","Relationship to student"]', JSON_KEYS(raw_values)) = 1 OR JSON_CONTAINS('["BloomsburgMartialArtsAcademy2_Id","StudentName_First","StudentName_Last","Phone","TextFriendly","StudentBirthdate","Email","Address_Line1","Address_Line2","Address_City","Address_State","Address_PostalCode","PrintName","Date","RelationshipToStudent","Entry_DateSubmitted"]', JSON_KEYS(raw_values)) = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `import_errors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_batch_id` bigint(20) unsigned NOT NULL,
  `source_row_number` bigint(20) unsigned DEFAULT NULL,
  `field_name` varchar(255) DEFAULT NULL,
  `error_code` varchar(100) NOT NULL,
  `error_message` text NOT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'error',
  `resolved_at` datetime(6) DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  KEY `ix_errors_batch_row` (`import_batch_id`,`source_row_number`),
  KEY `ix_errors_resolution` (`resolved_at`),
  KEY `ix_errors_user` (`resolved_by`),
  CONSTRAINT `fk_error_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`),
  CONSTRAINT `fk_error_user` FOREIGN KEY (`resolved_by`) REFERENCES `app_users` (`id`),
  CONSTRAINT `ck_error_severity` CHECK (`severity` in ('warning','error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_customer_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `email_normalized` varchar(254) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_city` varchar(100) DEFAULT NULL,
  `address_postal_code` varchar(32) DEFAULT NULL,
  `address_country` char(2) DEFAULT NULL,
  `stripe_created_at` datetime(6) DEFAULT NULL,
  `total_spend_minor` bigint(20) DEFAULT NULL,
  `payment_count` bigint(20) unsigned DEFAULT NULL,
  `refunded_volume_minor` bigint(20) DEFAULT NULL,
  `dispute_losses_minor` bigint(20) DEFAULT NULL,
  `summary_currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `is_stub` tinyint(1) NOT NULL DEFAULT 0,
  `latest_raw_id` bigint(20) unsigned DEFAULT NULL,
  `synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_customer` (`stripe_customer_id`),
  KEY `ix_customer_person` (`person_id`),
  KEY `ix_customer_email` (`email_normalized`),
  KEY `ix_customer_raw` (`latest_raw_id`),
  CONSTRAINT `fk_customer_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `fk_customer_raw` FOREIGN KEY (`latest_raw_id`) REFERENCES `raw_stripe_customers` (`id`),
  CONSTRAINT `ck_customer_stub` CHECK (`is_stub` in (0,1)),
  CONSTRAINT `ck_customer_money` CHECK (`total_spend_minor` is null and `refunded_volume_minor` is null and `dispute_losses_minor` is null or `summary_currency` is not null),
  CONSTRAINT `ck_stripe_customers_summary_currency` CHECK (`summary_currency` is null or `summary_currency` regexp '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_product_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_product` (`stripe_product_id`),
  CONSTRAINT `ck_product_active` CHECK (`active` is null or `active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_price_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `unit_amount_minor` bigint(20) DEFAULT NULL,
  `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `recurring_interval` varchar(30) DEFAULT NULL,
  `interval_count` int(10) unsigned DEFAULT NULL,
  `active` tinyint(1) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_price` (`stripe_price_id`),
  KEY `ix_price_product` (`product_id`),
  CONSTRAINT `fk_price_product` FOREIGN KEY (`product_id`) REFERENCES `stripe_products` (`id`),
  CONSTRAINT `ck_price_money` CHECK (`unit_amount_minor` is null or `currency` is not null),
  CONSTRAINT `ck_price_interval` CHECK (`interval_count` is null or `interval_count` > 0),
  CONSTRAINT `ck_price_active` CHECK (`active` is null or `active` in (0,1)),
  CONSTRAINT `ck_stripe_prices_currency` CHECK (`currency` is null or `currency` regexp '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_subscription_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `customer_name_snapshot` varchar(255) DEFAULT NULL,
  `customer_email_snapshot` varchar(254) DEFAULT NULL,
  `amount_minor` bigint(20) DEFAULT NULL,
  `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `status` varchar(60) NOT NULL,
  `coupon_reference` varchar(255) DEFAULT NULL,
  `stripe_created_at` datetime(6) DEFAULT NULL,
  `canceled_at` datetime(6) DEFAULT NULL,
  `current_period_start` datetime(6) DEFAULT NULL,
  `current_period_end` datetime(6) DEFAULT NULL,
  `latest_raw_id` bigint(20) unsigned DEFAULT NULL,
  `synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `stripe_subscription_started_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_subscription` (`stripe_subscription_id`),
  KEY `ix_subscription_customer` (`customer_id`),
  KEY `ix_subscription_status` (`status`,`current_period_end`),
  KEY `ix_subscription_raw` (`latest_raw_id`),
  CONSTRAINT `fk_subscription_customer` FOREIGN KEY (`customer_id`) REFERENCES `stripe_customers` (`id`),
  CONSTRAINT `fk_subscription_raw` FOREIGN KEY (`latest_raw_id`) REFERENCES `raw_stripe_subscriptions` (`id`),
  CONSTRAINT `ck_subscription_money` CHECK (`amount_minor` is null or `currency` is not null),
  CONSTRAINT `ck_stripe_subscriptions_currency` CHECK (`currency` is null or `currency` regexp '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subscription_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `valid_from_key` date GENERATED ALWAYS AS (coalesce(`valid_from`,'1000-01-01')) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_member` (`subscription_id`,`member_id`,`valid_from_key`),
  KEY `ix_subscription_member_member` (`member_id`),
  KEY `ix_subscription_member_subscription` (`subscription_id`),
  CONSTRAINT `fk_subscription_member_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `fk_subscription_member_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `stripe_subscriptions` (`id`),
  CONSTRAINT `ck_subscription_member_dates` CHECK (`valid_until` is null or `valid_until` >= `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_charge_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `stripe_invoice_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `stripe_created_at` datetime(6) DEFAULT NULL,
  `amount_minor` bigint(20) NOT NULL,
  `amount_refunded_minor` bigint(20) DEFAULT NULL,
  `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `captured` tinyint(1) DEFAULT NULL,
  `converted_amount_minor` bigint(20) DEFAULT NULL,
  `converted_amount_refunded_minor` bigint(20) DEFAULT NULL,
  `converted_currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `fee_minor` bigint(20) DEFAULT NULL,
  `taxes_on_fee_minor` bigint(20) DEFAULT NULL,
  `fee_currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `refunded_at` datetime(6) DEFAULT NULL,
  `statement_descriptor` varchar(255) DEFAULT NULL,
  `status` varchar(60) NOT NULL,
  `seller_message` text DEFAULT NULL,
  `customer_email_snapshot` varchar(254) DEFAULT NULL,
  `transfer_reference` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `latest_raw_id` bigint(20) unsigned DEFAULT NULL,
  `synced_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `stripe_balance_transaction_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `balance_amount_minor` bigint(20) DEFAULT NULL,
  `balance_fee_minor` bigint(20) DEFAULT NULL,
  `balance_net_minor` bigint(20) DEFAULT NULL,
  `balance_currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_charge` (`stripe_charge_id`),
  KEY `ix_transaction_invoice` (`stripe_invoice_id`),
  KEY `ix_transaction_intent` (`stripe_payment_intent_id`),
  KEY `ix_transaction_customer_date` (`customer_id`,`stripe_created_at`),
  KEY `ix_transaction_subscription` (`subscription_id`),
  KEY `ix_transaction_date_status` (`stripe_created_at`,`status`),
  KEY `ix_transaction_raw` (`latest_raw_id`),
  KEY `ix_transaction_balance` (`stripe_balance_transaction_id`),
  CONSTRAINT `fk_transaction_customer` FOREIGN KEY (`customer_id`) REFERENCES `stripe_customers` (`id`),
  CONSTRAINT `fk_transaction_raw` FOREIGN KEY (`latest_raw_id`) REFERENCES `raw_stripe_transactions` (`id`),
  CONSTRAINT `fk_transaction_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `stripe_subscriptions` (`id`),
  CONSTRAINT `ck_transaction_captured` CHECK (`captured` is null or `captured` in (0,1)),
  CONSTRAINT `ck_transaction_converted` CHECK (`converted_amount_minor` is null and `converted_amount_refunded_minor` is null or `converted_currency` is not null),
  CONSTRAINT `ck_transaction_fee` CHECK (`fee_minor` is null and `taxes_on_fee_minor` is null or `fee_currency` is not null),
  CONSTRAINT `ck_balance_money` CHECK (`balance_amount_minor` is null and `balance_fee_minor` is null and `balance_net_minor` is null or `balance_currency` is not null),
  CONSTRAINT `ck_balance_currency` CHECK (`balance_currency` is null or `balance_currency` regexp '^[a-z]{3}$'),
  CONSTRAINT `ck_stripe_transactions_currency` CHECK (`currency` is null or `currency` regexp '^[a-z]{3}$'),
  CONSTRAINT `ck_stripe_transactions_converted_currency` CHECK (`converted_currency` is null or `converted_currency` regexp '^[a-z]{3}$'),
  CONSTRAINT `ck_stripe_transactions_fee_currency` CHECK (`fee_currency` is null or `fee_currency` regexp '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cognito_waivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_record_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_id_kind` varchar(30) NOT NULL,
  `student_person_id` bigint(20) unsigned DEFAULT NULL,
  `signer_person_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `source_timezone` varchar(64) DEFAULT NULL,
  `source_name` varchar(255) DEFAULT NULL,
  `contact_email` varchar(254) DEFAULT NULL,
  `contact_phone` varchar(100) DEFAULT NULL,
  `text_friendly` tinyint(1) DEFAULT NULL,
  `student_birth_date` date DEFAULT NULL,
  `address_text` text DEFAULT NULL,
  `signer_print_name` varchar(255) DEFAULT NULL,
  `signed_date` date DEFAULT NULL,
  `relationship_to_student` varchar(255) DEFAULT NULL,
  `latest_raw_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  `form_namespace` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_locator` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `interpretation_status` varchar(20) NOT NULL DEFAULT 'unresolved',
  `source_format` varchar(30) NOT NULL DEFAULT 'legacy',
  `source_name_role` varchar(20) NOT NULL DEFAULT 'unresolved',
  `source_student_first_name` varchar(255) DEFAULT NULL,
  `source_student_last_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cognito_locator` (`form_namespace`,`source_locator`),
  UNIQUE KEY `uq_cognito_record` (`form_namespace`,`source_record_id`),
  KEY `ix_waiver_student` (`student_person_id`),
  KEY `ix_waiver_signer` (`signer_person_id`),
  KEY `ix_waiver_submitted` (`submitted_at`),
  KEY `ix_waiver_raw` (`latest_raw_id`),
  KEY `ix_waiver_contact_email` (`contact_email`),
  KEY `ix_waiver_signer_name` (`signer_print_name`),
  KEY `ix_waiver_source_name` (`source_name`),
  CONSTRAINT `fk_waiver_raw` FOREIGN KEY (`latest_raw_id`) REFERENCES `raw_cognito_waivers` (`id`),
  CONSTRAINT `fk_waiver_signer` FOREIGN KEY (`signer_person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `fk_waiver_student` FOREIGN KEY (`student_person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `ck_waiver_text` CHECK (`text_friendly` is null or `text_friendly` in (0,1)),
  CONSTRAINT `ck_waiver_timezone` CHECK (`submitted_at` is null or `source_timezone` is not null),
  CONSTRAINT `ck_waiver_id_kind` CHECK (`source_id_kind` in ('external','fingerprint')),
  CONSTRAINT `ck_waiver_interpretation` CHECK (`interpretation_status` in ('unresolved','verified')),
  CONSTRAINT `ck_waiver_source_labels` CHECK ((source_format = 'legacy' AND source_name_role = 'unresolved') OR (source_format = 'bma2_v1' AND source_name_role = 'student'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `identity_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` bigint(20) unsigned DEFAULT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_type` varchar(40) NOT NULL,
  `external_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_role` varchar(30) NOT NULL,
  `match_method` varchar(60) DEFAULT NULL,
  `match_score` decimal(5,4) DEFAULT NULL,
  `match_reason` varchar(100) DEFAULT NULL,
  `match_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`match_evidence`)),
  `match_status` varchar(30) NOT NULL DEFAULT 'unmatched',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime(6) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `candidate_person_key` bigint(20) unsigned GENERATED ALWAYS AS (coalesce(`person_id`,0)) STORED,
  `accepted_slot` tinyint(4) GENERATED ALWAYS AS (case when `match_status` in ('automatic','confirmed') then 1 else NULL end) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_identity_candidate` (`source_system`,`source_type`,`external_id`,`source_role`,`candidate_person_key`),
  UNIQUE KEY `uq_identity_accepted` (`source_system`,`source_type`,`external_id`,`source_role`,`accepted_slot`),
  KEY `ix_identity_person` (`person_id`),
  KEY `ix_identity_review` (`match_status`,`created_at`),
  KEY `ix_identity_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_identity_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `fk_identity_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `app_users` (`id`),
  CONSTRAINT `ck_identity_source` CHECK (`source_system` in ('stripe','cognito','manual')),
  CONSTRAINT `ck_identity_role` CHECK (`source_role` in ('customer','student','signer','person')),
  CONSTRAINT `ck_identity_status` CHECK (`match_status` in ('automatic','confirmed','possible','rejected','unmatched')),
  CONSTRAINT `ck_identity_score` CHECK (`match_score` is null or `match_score` between 0 and 1),
  CONSTRAINT `ck_identity_person` CHECK (`match_status` = 'unmatched' and `person_id` is null or `match_status` <> 'unmatched' and `person_id` is not null),
  CONSTRAINT `ck_identity_review` CHECK (`match_status` not in ('confirmed','rejected') or `reviewed_by` is not null and `reviewed_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`setting_value`)),
  `description` text DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`setting_key`),
  KEY `ix_settings_user` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `app_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `ip_address` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  PRIMARY KEY (`id`),
  KEY `ix_audit_actor_date` (`actor_user_id`,`created_at`),
  KEY `ix_audit_entity` (`entity_type`,`entity_id`),
  KEY `ix_audit_date` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`actor_user_id`) REFERENCES `app_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_subscription_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `stripe_subscription_item_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_locator` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `price_id` bigint(20) unsigned DEFAULT NULL,
  `product_name_snapshot` varchar(255) DEFAULT NULL,
  `quantity` bigint(20) unsigned DEFAULT NULL,
  `amount_minor` bigint(20) DEFAULT NULL,
  `amount_basis` varchar(30) NOT NULL DEFAULT 'csv_reported',
  `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `billing_interval` varchar(30) DEFAULT NULL,
  `billing_interval_count` int(10) unsigned DEFAULT NULL,
  `latest_raw_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_item_locator` (`subscription_id`,`source_locator`),
  UNIQUE KEY `uq_subscription_item_external` (`stripe_subscription_item_id`),
  KEY `ix_subscription_item_price` (`price_id`),
  KEY `ix_subscription_item_raw` (`latest_raw_id`),
  CONSTRAINT `fk_item_price` FOREIGN KEY (`price_id`) REFERENCES `stripe_prices` (`id`),
  CONSTRAINT `fk_item_raw` FOREIGN KEY (`latest_raw_id`) REFERENCES `raw_stripe_subscriptions` (`id`),
  CONSTRAINT `fk_item_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `stripe_subscriptions` (`id`),
  CONSTRAINT `ck_item_money` CHECK (`amount_minor` is null or `currency` is not null),
  CONSTRAINT `ck_item_basis` CHECK (`amount_basis` in ('csv_reported','unit')),
  CONSTRAINT `ck_item_interval` CHECK (`billing_interval_count` is null or `billing_interval_count` > 0),
  CONSTRAINT `ck_item_currency` CHECK (`currency` is null or `currency` regexp '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stripe_source_identity` (
  `id` tinyint(3) unsigned NOT NULL,
  `stripe_account_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_livemode` tinyint(1) DEFAULT NULL,
  `established_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_stripe_singleton` CHECK (`id` = 1),
  CONSTRAINT `ck_stripe_mode` CHECK (`stripe_livemode` is null or `stripe_livemode` in (0,1)),
  CONSTRAINT `ck_stripe_identity_pair` CHECK (`stripe_account_id` is null and `stripe_livemode` is null and `established_at` is null or `stripe_account_id` is not null and `stripe_livemode` is not null and `established_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `identity_match_candidates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_customer_id` bigint(20) unsigned NOT NULL,
  `cognito_waiver_id` bigint(20) unsigned NOT NULL,
  `waiver_role` varchar(30) NOT NULL,
  `match_score` decimal(5,4) NOT NULL,
  `match_method` varchar(60) NOT NULL,
  `match_reason` varchar(100) NOT NULL,
  `match_status` varchar(30) NOT NULL,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`evidence`)),
  `hard_conflict` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_match_candidate_pair` (`stripe_customer_id`,`cognito_waiver_id`,`waiver_role`),
  KEY `ix_match_candidate_waiver` (`cognito_waiver_id`,`waiver_role`),
  KEY `ix_match_candidate_review` (`match_status`,`match_score`),
  KEY `ix_match_candidate_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_candidate_customer` FOREIGN KEY (`stripe_customer_id`) REFERENCES `stripe_customers` (`id`),
  CONSTRAINT `fk_candidate_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `app_users` (`id`),
  CONSTRAINT `fk_candidate_waiver` FOREIGN KEY (`cognito_waiver_id`) REFERENCES `cognito_waivers` (`id`),
  CONSTRAINT `ck_candidate_role` CHECK (`waiver_role` in ('signer','source_name_unresolved')),
  CONSTRAINT `ck_candidate_score` CHECK (`match_score` between 0 and 1),
  CONSTRAINT `ck_candidate_status` CHECK (`match_status` in ('possible','unmatched','linked','conflict','confirmed','rejected')),
  CONSTRAINT `ck_candidate_conflict` CHECK (`hard_conflict` in (0,1)),
  CONSTRAINT `ck_candidate_review` CHECK (`match_status` not in ('confirmed','rejected') or `reviewed_by` is not null and `reviewed_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_throttle` (
  `bucket_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket_hash`),
  KEY `ix_auth_throttle_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

DELIMITER $$
CREATE TRIGGER people_allocate_identity BEFORE INSERT ON people FOR EACH ROW
BEGIN
    IF NEW.id <> 0 OR NEW.bma_person_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'People must use the database ID allocator';
    END IF;
END$$
DELIMITER ;

DELIMITER $$
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

INSERT INTO stripe_source_identity (id) VALUES (1);

-- Step 5 reporting views. Run only after migrations 001-004 on MariaDB 10.4+.
-- Monetary values remain integer minor units except documented DECIMAL monthly equivalents.
-- SQL SECURITY INVOKER avoids embedding a deployment-specific DEFINER account.

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_member_summary AS
SELECT m.id AS member_id, p.id AS person_id, p.bma_person_id,
       TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS member_name,
       m.member_status, m.member_since, m.member_until,
       p.birth_date, p.city, p.state, p.postal_code,
       m.created_at AS member_record_created_at
FROM members m
JOIN people p ON p.id = m.person_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_active_members AS
SELECT ms.*
FROM vw_member_summary ms
WHERE ms.member_status = 'active'
  AND (ms.member_until IS NULL OR ms.member_until >= CURRENT_DATE());

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_member_demographics AS
SELECT ms.member_id, ms.person_id, ms.bma_person_id, ms.member_name,
       ms.birth_date, ms.city, ms.state, ms.postal_code,
       CASE WHEN ms.birth_date IS NOT NULL AND ms.birth_date <= CURRENT_DATE()
            THEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) ELSE NULL END AS age_years,
       CASE WHEN ms.birth_date IS NULL OR ms.birth_date > CURRENT_DATE() THEN NULL
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 13 THEN '0-12'
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 18 THEN '13-17'
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 25 THEN '18-24'
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 35 THEN '25-34'
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 45 THEN '35-44'
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 55 THEN '45-54'
            ELSE '55+' END AS age_bracket,
       CASE WHEN ms.birth_date IS NULL OR ms.birth_date > CURRENT_DATE() THEN NULL
            WHEN TIMESTAMPDIFF(YEAR, ms.birth_date, CURRENT_DATE()) < 18 THEN 'youth'
            ELSE 'adult' END AS adult_youth
FROM vw_member_summary ms;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_member_retention AS
SELECT ms.member_id, ms.bma_person_id, ms.member_status, ms.member_since, ms.member_until,
       CASE WHEN ms.member_since IS NULL OR ms.member_since > CURRENT_DATE() THEN NULL
            WHEN DATE_ADD(ms.member_since, INTERVAL 3 MONTH) > CURRENT_DATE() THEN NULL
            WHEN ms.member_until IS NOT NULL THEN IF(ms.member_until >= DATE_ADD(ms.member_since, INTERVAL 3 MONTH), 1, 0)
            WHEN ms.member_status = 'active' THEN 1 ELSE NULL END AS retained_3_months,
       CASE WHEN ms.member_since IS NULL OR ms.member_since > CURRENT_DATE() THEN NULL
            WHEN DATE_ADD(ms.member_since, INTERVAL 6 MONTH) > CURRENT_DATE() THEN NULL
            WHEN ms.member_until IS NOT NULL THEN IF(ms.member_until >= DATE_ADD(ms.member_since, INTERVAL 6 MONTH), 1, 0)
            WHEN ms.member_status = 'active' THEN 1 ELSE NULL END AS retained_6_months,
       CASE WHEN ms.member_since IS NULL OR ms.member_since > CURRENT_DATE() THEN NULL
            WHEN DATE_ADD(ms.member_since, INTERVAL 12 MONTH) > CURRENT_DATE() THEN NULL
            WHEN ms.member_until IS NOT NULL THEN IF(ms.member_until >= DATE_ADD(ms.member_since, INTERVAL 12 MONTH), 1, 0)
            WHEN ms.member_status = 'active' THEN 1 ELSE NULL END AS retained_12_months
FROM vw_member_summary ms;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_subscription_item_rates AS
SELECT x.subscription_item_id, x.subscription_id, x.stripe_subscription_item_id,
       x.stripe_price_id, x.stripe_product_id, x.product_name,
       x.amount_minor, x.amount_basis, x.quantity, x.currency,
       x.billing_interval, x.billing_interval_count,
       CASE WHEN x.amount_minor IS NULL OR x.currency IS NULL OR x.billing_interval_count IS NULL
                 OR x.billing_interval_count = 0 OR (x.amount_basis = 'unit' AND x.quantity IS NULL)
                 OR x.currency_conflict = 1 THEN NULL
            WHEN x.billing_interval = 'month' THEN ROUND(CAST(x.amount_minor AS DECIMAL(28,4)) * x.effective_quantity / x.billing_interval_count, 4)
            WHEN x.billing_interval = 'quarter' THEN ROUND(CAST(x.amount_minor AS DECIMAL(28,4)) * x.effective_quantity / (3 * x.billing_interval_count), 4)
            WHEN x.billing_interval = 'year' THEN ROUND(CAST(x.amount_minor AS DECIMAL(28,4)) * x.effective_quantity / (12 * x.billing_interval_count), 4)
            WHEN x.billing_interval = 'week' THEN ROUND(CAST(x.amount_minor AS DECIMAL(28,4)) * x.effective_quantity * 52 / (12 * x.billing_interval_count), 4)
            WHEN x.billing_interval = 'day' THEN ROUND(CAST(x.amount_minor AS DECIMAL(28,4)) * x.effective_quantity * 365 / (12 * x.billing_interval_count), 4)
            ELSE NULL END AS monthly_equivalent_minor
FROM (
    SELECT i.id AS subscription_item_id, i.subscription_id, i.stripe_subscription_item_id,
           p.stripe_price_id, pr.stripe_product_id,
           COALESCE(NULLIF(i.product_name_snapshot, ''), pr.name) AS product_name,
           i.amount_minor, i.amount_basis, i.quantity,
           CASE WHEN i.currency IS NOT NULL AND p.currency IS NOT NULL AND i.currency <> p.currency
                THEN NULL ELSE COALESCE(i.currency, p.currency) END AS currency,
           IF(i.currency IS NOT NULL AND p.currency IS NOT NULL AND i.currency <> p.currency, 1, 0) AS currency_conflict,
           CASE LOWER(COALESCE(i.billing_interval, p.recurring_interval, ''))
                WHEN 'monthly' THEN 'month' WHEN 'quarterly' THEN 'quarter'
                WHEN 'yearly' THEN 'year' WHEN 'annual' THEN 'year'
                WHEN 'weekly' THEN 'week' WHEN 'daily' THEN 'day'
                ELSE LOWER(COALESCE(i.billing_interval, p.recurring_interval, '')) END AS billing_interval,
           COALESCE(i.billing_interval_count, p.interval_count) AS billing_interval_count,
           CASE WHEN i.amount_basis = 'unit' THEN i.quantity ELSE 1 END AS effective_quantity
    FROM stripe_subscription_items i
    LEFT JOIN stripe_prices p ON p.id = i.price_id
    LEFT JOIN stripe_products pr ON pr.id = p.product_id
) x;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_subscription_summary AS
SELECT s.id AS subscription_id, s.stripe_subscription_id, s.customer_id, c.stripe_customer_id,
       s.status AS subscription_status, s.stripe_created_at,
       s.stripe_subscription_started_at AS subscription_started_at,
       s.current_period_start, s.current_period_end, s.canceled_at,
       COALESCE(i.item_count, 0) AS item_count,
       COALESCE(sm.member_count, 0) AS explicitly_linked_member_count,
       CASE WHEN i.item_count = 1 THEN i.single_product_name ELSE NULL END AS single_product_name,
       CASE WHEN i.item_count > 0 AND i.currency_known_count = i.item_count
                     AND i.distinct_currency_count = 1 THEN i.single_currency ELSE NULL END AS currency,
       CASE WHEN s.status = 'active' AND i.item_count > 0
                     AND i.rate_known_count = i.item_count AND i.currency_known_count = i.item_count
                     AND i.distinct_currency_count = 1 THEN i.monthly_equivalent_minor ELSE NULL END AS active_mrr_minor,
       CASE WHEN s.status = 'active' THEN 1 ELSE 0 END AS is_active_subscription
FROM stripe_subscriptions s
JOIN stripe_customers c ON c.id = s.customer_id
LEFT JOIN (
    SELECT subscription_id, COUNT(*) AS item_count,
           COUNT(currency) AS currency_known_count, COUNT(DISTINCT currency) AS distinct_currency_count,
           COUNT(monthly_equivalent_minor) AS rate_known_count,
           MAX(currency) AS single_currency, MAX(product_name) AS single_product_name,
           SUM(monthly_equivalent_minor) AS monthly_equivalent_minor
    FROM vw_subscription_item_rates GROUP BY subscription_id
) i ON i.subscription_id = s.id
LEFT JOIN (SELECT subscription_id, COUNT(DISTINCT member_id) AS member_count
           FROM subscription_members GROUP BY subscription_id) sm ON sm.subscription_id = s.id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_transaction_summary AS
SELECT x.transaction_id, x.stripe_charge_id, x.stripe_invoice_id, x.stripe_payment_intent_id,
       x.customer_id, x.stripe_customer_id, x.payer_person_id, x.payer_bma_person_id,
       x.subscription_id, x.stripe_subscription_id,
       x.stripe_created_at, x.transaction_day_utc, x.week_start_utc,
       x.month_start_utc, x.year_start_utc, x.status, x.captured, x.currency,
       x.amount_minor, x.amount_refunded_minor, x.is_paid,
       CASE WHEN x.is_paid = 1 THEN x.amount_minor ELSE 0 END AS gross_paid_minor,
       CASE WHEN x.is_paid = 1 THEN x.amount_refunded_minor ELSE 0 END AS refunds_minor,
       CASE WHEN x.is_paid = 1 THEN x.fee_same_currency_minor ELSE 0 END AS stripe_fees_minor,
       CASE WHEN x.is_paid = 1 AND x.amount_refunded_minor IS NOT NULL THEN 1 ELSE 0 END AS refund_known,
       CASE WHEN x.is_paid = 1 AND x.fee_same_currency_minor IS NOT NULL THEN 1 ELSE 0 END AS fee_known,
       CASE WHEN x.is_paid = 1 AND x.amount_refunded_minor IS NOT NULL
                     AND x.fee_same_currency_minor IS NOT NULL
            THEN x.amount_minor - x.amount_refunded_minor - x.fee_same_currency_minor ELSE NULL END AS net_after_recorded_costs_minor
FROM (
    SELECT t.id AS transaction_id, t.stripe_charge_id, t.stripe_invoice_id, t.stripe_payment_intent_id,
           t.customer_id, c.stripe_customer_id, l.person_id AS payer_person_id,
           p.bma_person_id AS payer_bma_person_id,
           t.subscription_id, s.stripe_subscription_id,
           t.stripe_created_at, DATE(t.stripe_created_at) AS transaction_day_utc,
           DATE_SUB(DATE(t.stripe_created_at), INTERVAL WEEKDAY(t.stripe_created_at) DAY) AS week_start_utc,
           CAST(DATE_FORMAT(t.stripe_created_at, '%Y-%m-01') AS DATE) AS month_start_utc,
           CAST(DATE_FORMAT(t.stripe_created_at, '%Y-01-01') AS DATE) AS year_start_utc,
           t.status, t.captured, t.currency, t.amount_minor, t.amount_refunded_minor,
           CASE WHEN LOWER(t.status) IN ('paid','succeeded') AND COALESCE(t.captured, 1) = 1 THEN 1 ELSE 0 END AS is_paid,
           CASE WHEN t.balance_fee_minor IS NOT NULL AND t.balance_currency = t.currency THEN t.balance_fee_minor
                WHEN t.fee_minor IS NOT NULL AND t.fee_currency = t.currency THEN t.fee_minor
                ELSE NULL END AS fee_same_currency_minor
    FROM stripe_transactions t
    LEFT JOIN stripe_customers c ON c.id = t.customer_id
    LEFT JOIN identity_links l ON l.source_system = 'stripe' AND l.source_type = 'customer'
                              AND l.external_id = c.stripe_customer_id AND l.source_role = 'customer'
                              AND l.match_status IN ('automatic','confirmed')
    LEFT JOIN people p ON p.id = l.person_id
    LEFT JOIN stripe_subscriptions s ON s.id = t.subscription_id
) x;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_revenue_by_period AS
SELECT g.period_grain, g.period_start_utc, g.currency,
       COUNT(*) AS transaction_count, SUM(g.gross_paid_minor) AS gross_paid_minor,
       SUM(g.refund_known) AS refund_known_count,
       CASE WHEN SUM(g.refund_known) = COUNT(*) THEN SUM(g.refunds_minor) ELSE NULL END AS refunds_minor,
       SUM(g.fee_known) AS fee_known_count,
       CASE WHEN SUM(g.fee_known) = COUNT(*) THEN SUM(g.stripe_fees_minor) ELSE NULL END AS stripe_fees_minor,
       CASE WHEN SUM(g.refund_known) = COUNT(*) AND SUM(g.fee_known) = COUNT(*)
            THEN SUM(g.net_after_recorded_costs_minor) ELSE NULL END AS net_after_recorded_costs_minor
FROM (
    SELECT grains.period_grain,
           CASE grains.period_grain WHEN 'day' THEN t.transaction_day_utc
                WHEN 'week' THEN t.week_start_utc
                WHEN 'month' THEN t.month_start_utc ELSE t.year_start_utc END AS period_start_utc,
           t.currency, t.gross_paid_minor, t.refunds_minor, t.stripe_fees_minor,
           t.refund_known, t.fee_known, t.net_after_recorded_costs_minor
    FROM vw_transaction_summary t
    CROSS JOIN (SELECT 'day' AS period_grain UNION ALL SELECT 'week' UNION ALL
                SELECT 'month' UNION ALL SELECT 'year') grains
    WHERE t.is_paid = 1 AND t.stripe_created_at IS NOT NULL
) g
GROUP BY g.period_grain, g.period_start_utc, g.currency;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_monthly_revenue AS
SELECT period_start_utc AS month_start_utc, currency, transaction_count,
       gross_paid_minor, refund_known_count, refunds_minor,
       fee_known_count, stripe_fees_minor, net_after_recorded_costs_minor
FROM vw_revenue_by_period WHERE period_grain = 'month';

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_revenue_by_program AS
SELECT t.month_start_utc, t.currency, s.single_product_name AS program_name,
       s.subscription_status AS current_subscription_status,
       COUNT(*) AS transaction_count, SUM(t.gross_paid_minor) AS gross_paid_minor,
       SUM(t.refund_known) AS refund_known_count,
       CASE WHEN SUM(t.refund_known) = COUNT(*) THEN SUM(t.refunds_minor) ELSE NULL END AS refunds_minor,
       SUM(t.fee_known) AS fee_known_count,
       CASE WHEN SUM(t.fee_known) = COUNT(*) THEN SUM(t.stripe_fees_minor) ELSE NULL END AS stripe_fees_minor,
       CASE WHEN SUM(t.refund_known) = COUNT(*) AND SUM(t.fee_known) = COUNT(*)
            THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END AS net_after_recorded_costs_minor
FROM vw_transaction_summary t
JOIN vw_subscription_summary s ON s.subscription_id = t.subscription_id
WHERE t.is_paid = 1 AND t.stripe_created_at IS NOT NULL
  AND s.item_count = 1 AND s.single_product_name IS NOT NULL
GROUP BY t.month_start_utc, t.currency, s.single_product_name, s.subscription_status;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_payer_member_scope AS
SELECT r.person_id AS payer_person_id, m.id AS member_id, m.person_id AS member_person_id
FROM person_relationships r
JOIN members m ON m.person_id = r.related_person_id
WHERE r.relationship_type = 'payer'
UNION
SELECT r.person_id AS payer_person_id, m.id AS member_id, m.person_id AS member_person_id
FROM person_relationships r
JOIN members m ON m.person_id = r.person_id
WHERE r.relationship_type = 'self' AND r.person_id = r.related_person_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_payer_financial_summary AS
SELECT t.payer_person_id, p.bma_person_id AS payer_bma_person_id,
       t.currency, COUNT(*) AS transaction_count,
       COUNT(DISTINCT t.customer_id) AS stripe_customer_count,
       SUM(t.gross_paid_minor) AS gross_paid_minor,
       SUM(t.refund_known) AS refund_known_count,
       CASE WHEN SUM(t.refund_known) = COUNT(*) THEN SUM(t.refunds_minor) ELSE NULL END AS refunds_minor,
       SUM(t.fee_known) AS fee_known_count,
       CASE WHEN SUM(t.fee_known) = COUNT(*) THEN SUM(t.stripe_fees_minor) ELSE NULL END AS stripe_fees_minor,
       CASE WHEN SUM(t.refund_known) = COUNT(*) AND SUM(t.fee_known) = COUNT(*)
            THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END AS net_after_recorded_costs_minor,
       MIN(t.stripe_created_at) AS first_payment_at,
       MAX(t.stripe_created_at) AS last_payment_at
FROM vw_transaction_summary t
JOIN people p ON p.id = t.payer_person_id
WHERE t.is_paid = 1
GROUP BY t.payer_person_id, p.bma_person_id, t.currency;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_member_financial_summary AS
SELECT ms.member_id, ms.person_id, ms.bma_person_id, ms.member_name,
       COALESCE(ps.payer_count, 0) AS payer_count,
       CASE WHEN ps.payer_count = 1 THEN ps.payer_person_id ELSE NULL END AS payer_person_id,
       CASE WHEN ps.payer_count = 1 THEN TRIM(CONCAT_WS(' ',pp.first_name,pp.last_name)) ELSE NULL END AS payer_name,
       CASE WHEN ps.payer_count = 1 AND pc.customer_count = 1 THEN pc.stripe_customer_id ELSE NULL END AS stripe_customer_id,
       CASE WHEN ps.payer_count = 1 THEN pm.member_count ELSE NULL END AS payer_member_count,
       CASE WHEN ps.payer_count IS NULL OR ps.payer_count = 0 THEN 'no_confirmed_payer'
            WHEN ps.payer_count > 1 THEN 'multiple_payers'
            WHEN pm.member_count > 1 THEN 'shared_payer_unallocated'
            WHEN pf.currency_count > 1 THEN 'multiple_currencies'
            WHEN pf.currency_count IS NULL THEN 'no_linked_paid_transactions'
            ELSE 'exclusive_payer' END AS financial_attribution_status,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.currency ELSE NULL END AS currency,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.transaction_count ELSE NULL END AS transaction_count,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.gross_paid_minor ELSE NULL END AS gross_paid_minor,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.refunds_minor ELSE NULL END AS refunds_minor,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.stripe_fees_minor ELSE NULL END AS stripe_fees_minor,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.net_after_recorded_costs_minor ELSE NULL END AS net_after_recorded_costs_minor,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.first_payment_at ELSE NULL END AS first_payment_at,
       CASE WHEN ps.payer_count = 1 AND pm.member_count = 1 AND pf.currency_count = 1 THEN pf.last_payment_at ELSE NULL END AS last_payment_at,
       COALESCE(sub.linked_subscription_count, 0) AS explicitly_linked_subscription_count,
       COALESCE(sub.active_subscription_count, 0) AS active_subscription_count,
       CASE WHEN sub.linked_subscription_count IS NULL THEN 'no_explicit_subscription_link'
            WHEN sub.linked_subscription_count > 1 THEN 'multiple_subscriptions'
            WHEN sub.single_subscription_member_count > 1 THEN 'shared_subscription_unallocated'
            WHEN sub.single_active_mrr_minor IS NULL THEN 'rate_or_status_unresolved'
            ELSE 'exclusive_subscription' END AS subscription_allocation_status,
       CASE WHEN sub.linked_subscription_count = 1 THEN sub.single_subscription_id ELSE NULL END AS stripe_subscription_id,
       CASE WHEN sub.linked_subscription_count = 1 THEN sub.single_subscription_status ELSE NULL END AS subscription_status,
       CASE WHEN sub.linked_subscription_count = 1 THEN sub.single_product_name ELSE NULL END AS membership_product,
       CASE WHEN sub.linked_subscription_count = 1 AND sub.single_subscription_member_count = 1
            THEN sub.single_active_mrr_minor ELSE NULL END AS monthly_equivalent_minor,
       CASE WHEN sub.linked_subscription_count = 1 THEN sub.single_subscription_started_at ELSE NULL END AS subscription_started_at,
       CASE WHEN sub.linked_subscription_count = 1 THEN sub.single_canceled_at ELSE NULL END AS subscription_canceled_at
FROM vw_member_summary ms
LEFT JOIN (
    SELECT member_id, COUNT(*) AS payer_count, MAX(payer_person_id) AS payer_person_id
    FROM vw_payer_member_scope GROUP BY member_id
) ps ON ps.member_id = ms.member_id
LEFT JOIN people pp ON pp.id = ps.payer_person_id AND ps.payer_count = 1
LEFT JOIN (
    SELECT payer_person_id, COUNT(*) AS member_count FROM vw_payer_member_scope GROUP BY payer_person_id
) pm ON pm.payer_person_id = ps.payer_person_id AND ps.payer_count = 1
LEFT JOIN (
    SELECT l.person_id AS payer_person_id, COUNT(*) AS customer_count,
           MAX(c.stripe_customer_id) AS stripe_customer_id
    FROM identity_links l
    JOIN stripe_customers c ON c.stripe_customer_id = l.external_id
    WHERE l.source_system = 'stripe' AND l.source_type = 'customer' AND l.source_role = 'customer'
      AND l.match_status IN ('automatic','confirmed')
    GROUP BY l.person_id
) pc ON pc.payer_person_id = ps.payer_person_id AND ps.payer_count = 1
LEFT JOIN (
    SELECT payer_person_id, COUNT(*) AS currency_count, MAX(currency) AS currency,
           SUM(transaction_count) AS transaction_count, SUM(gross_paid_minor) AS gross_paid_minor,
           CASE WHEN SUM(refund_known_count) = SUM(transaction_count) THEN SUM(refunds_minor) ELSE NULL END AS refunds_minor,
           CASE WHEN SUM(fee_known_count) = SUM(transaction_count) THEN SUM(stripe_fees_minor) ELSE NULL END AS stripe_fees_minor,
           CASE WHEN SUM(refund_known_count) = SUM(transaction_count) AND SUM(fee_known_count) = SUM(transaction_count)
                THEN SUM(net_after_recorded_costs_minor) ELSE NULL END AS net_after_recorded_costs_minor,
           MIN(first_payment_at) AS first_payment_at, MAX(last_payment_at) AS last_payment_at
    FROM vw_payer_financial_summary GROUP BY payer_person_id
) pf ON pf.payer_person_id = ps.payer_person_id AND ps.payer_count = 1
LEFT JOIN (
    SELECT map.member_id, COUNT(*) AS linked_subscription_count,
           SUM(s.is_active_subscription) AS active_subscription_count,
           MAX(s.stripe_subscription_id) AS single_subscription_id,
           MAX(s.subscription_status) AS single_subscription_status,
           MAX(s.explicitly_linked_member_count) AS single_subscription_member_count,
           MAX(s.single_product_name) AS single_product_name,
           MAX(s.active_mrr_minor) AS single_active_mrr_minor,
           MAX(s.subscription_started_at) AS single_subscription_started_at,
           MAX(s.canceled_at) AS single_canceled_at
    FROM (
        SELECT DISTINCT member_id, subscription_id FROM subscription_members
        WHERE (valid_from IS NULL OR valid_from <= CURRENT_DATE())
          AND (valid_until IS NULL OR valid_until >= CURRENT_DATE())
    ) map
    JOIN vw_subscription_summary s ON s.subscription_id = map.subscription_id
    GROUP BY map.member_id
) sub ON sub.member_id = ms.member_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_import_health AS
SELECT b.id AS import_batch_id, b.source_type, b.filename, b.started_at, b.completed_at,
       b.status, b.row_count, b.inserted_count, b.updated_count,
       b.skipped_count, b.error_count,
       CASE WHEN b.row_count > 0 THEN ROUND(CAST(b.error_count AS DECIMAL(20,4)) / b.row_count, 4) ELSE NULL END AS error_rate,
       CASE WHEN b.status IN ('completed','completed_with_errors','failed') THEN 1 ELSE 0 END AS is_finished
FROM import_batches b;

INSERT INTO schema_migrations (version, checksum) VALUES ('001_initial_schema', '75d740ab762c93a022de436c31199b6601ae0e505dbab424bfb40b698008fb50');
INSERT INTO schema_migrations (version, checksum) VALUES ('002_step1_contract', '9a3199c62470ceadf576c3514765044affc2a473cf710dc20106c399a108a137');
INSERT INTO schema_migrations (version, checksum) VALUES ('003_minimize_stripe_fields', '90d12b6b50368d56a3186ef9f706c4197593d07b944a8c2b825a3d150c0c807c');
INSERT INTO schema_migrations (version, checksum) VALUES ('004_identity_match_candidates', '040a5ef5ef4b78739f5e3956b7826c31caae7aa497e5f783be52a70c009cf20d');
INSERT INTO schema_migrations (version, checksum) VALUES ('005_reporting_views', '64e857306ad3ff382646e12c045c008ac43e2d6c06774186d203eef84b6e6414');
INSERT INTO schema_migrations (version, checksum) VALUES ('006_authentication', '434816d45a96da2f0ed834813b4cfcc5a45c9e10ff813e9e701d68fccc99abac');

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

INSERT INTO schema_migrations (version, checksum) VALUES ('007_dashboard_access', 'ff5810d3afbc64093ea39ae8147b0468811d03045fe649ff1fb80558e521003a');

-- Dashboard guest groups are isolated from Customer API objects, member identity and payment ownership.
-- Existing regular customer rows, relationships, financial data and historical migrations are unchanged.
CREATE TABLE stripe_guest_customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_guest_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  record_type VARCHAR(30) NOT NULL DEFAULT 'dashboard_guest',
  name VARCHAR(255) NULL,
  email VARCHAR(254) NULL,
  email_normalized VARCHAR(254) NULL,
  address_line1 VARCHAR(255) NULL,
  address_city VARCHAR(100) NULL,
  address_postal_code VARCHAR(32) NULL,
  address_country CHAR(2) NULL,
  export_created_at DATETIME(6) NULL,
  payment_count BIGINT UNSIGNED NULL,
  total_spend_minor BIGINT NULL,
  refunded_volume_minor BIGINT NULL,
  dispute_losses_minor BIGINT NULL,
  summary_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
  latest_raw_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stripe_guest_customer (stripe_guest_customer_id),
  KEY ix_guest_customer_raw (latest_raw_id),
  CONSTRAINT fk_guest_customer_raw FOREIGN KEY (latest_raw_id) REFERENCES raw_stripe_customers (id),
  CONSTRAINT ck_guest_customer_type CHECK (record_type = 'dashboard_guest'),
  CONSTRAINT ck_guest_customer_id CHECK (stripe_guest_customer_id REGEXP '^gcus_[A-Za-z0-9_]+$'),
  CONSTRAINT ck_guest_customer_money CHECK (total_spend_minor IS NULL AND refunded_volume_minor IS NULL AND dispute_losses_minor IS NULL OR summary_currency IS NOT NULL),
  CONSTRAINT ck_guest_customer_currency CHECK (summary_currency IS NULL OR summary_currency REGEXP '^[a-z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version, checksum) VALUES ('008_stripe_guest_customers', '16d47a6619761b66a526e9aee03ad81e629b093e797863af96b03f6199c30d7a');

INSERT INTO schema_migrations (version, checksum) VALUES ('009_cognito_native_export', 'f4245b7dfe9d376ca71425f25406377146b3f3ef1368fd12d700bf5f3228b397');
