CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_customer_id VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_stripe_customer_id (stripe_customer_id),
  KEY idx_customers_email (email),
  KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_payment_intent_id VARCHAR(255) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  stripe_customer_id VARCHAR(255) NULL,
  amount INT UNSIGNED NOT NULL,
  amount_received INT UNSIGNED NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL,
  status VARCHAR(50) NOT NULL,
  capture_method VARCHAR(50) NULL,
  latest_charge_id VARCHAR(255) NULL,
  description VARCHAR(500) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_stripe_payment_intent_id (stripe_payment_intent_id),
  KEY idx_payments_customer_id (customer_id),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_customer_id FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_subscription_id VARCHAR(255) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  stripe_customer_id VARCHAR(255) NOT NULL,
  stripe_price_id VARCHAR(255) NULL,
  status VARCHAR(50) NOT NULL,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  canceled_at DATETIME NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriptions_stripe_subscription_id (stripe_subscription_id),
  KEY idx_subscriptions_customer_id (customer_id),
  KEY idx_subscriptions_status (status),
  CONSTRAINT fk_subscriptions_customer_id FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_event_id VARCHAR(255) NOT NULL,
  type VARCHAR(255) NOT NULL,
  api_version VARCHAR(50) NULL,
  livemode TINYINT(1) NOT NULL DEFAULT 0,
  payload JSON NOT NULL,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_events_stripe_event_id (stripe_event_id),
  KEY idx_webhook_events_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type VARCHAR(50) NOT NULL,
  action VARCHAR(255) NOT NULL,
  resource_type VARCHAR(100) NULL,
  resource_id VARCHAR(255) NULL,
  ip_address VARCHAR(100) NULL,
  user_agent VARCHAR(500) NULL,
  status_code INT NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_action (action),
  KEY idx_audit_logs_resource (resource_type, resource_id),
  KEY idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
