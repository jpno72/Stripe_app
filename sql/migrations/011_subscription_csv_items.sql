-- Preserve all existing records/IDs and make CSV provenance explicit.
ALTER TABLE stripe_subscriptions
    ADD csv_state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD csv_start_at DATETIME(6) NULL,
    ADD cancel_at_period_end BOOLEAN NULL,
    ADD ended_at DATETIME(6) NULL,
    ADD CONSTRAINT ck_subscription_cancel_period CHECK (cancel_at_period_end IS NULL OR cancel_at_period_end IN (0,1));
ALTER TABLE stripe_subscription_items
    ADD legacy_plan_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD product_id BIGINT UNSIGNED NULL,
    ADD csv_state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD KEY ix_item_product (product_id),
    ADD KEY ix_item_legacy_plan (legacy_plan_id),
    ADD CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES stripe_products(id),
    ADD CONSTRAINT ck_item_legacy_plan CHECK (legacy_plan_id IS NULL OR (legacy_plan_id REGEXP '^plan_[A-Za-z0-9_]+$' AND price_id IS NULL)),
    DROP CONSTRAINT ck_item_basis,
    ADD CONSTRAINT ck_item_basis CHECK (amount_basis IN ('csv_reported','unit','csv_unresolved'));
ALTER TABLE raw_stripe_subscriptions
    ADD KEY ix_raw_subscription_group (import_batch_id,source_record_id),
    DROP CONSTRAINT ck_stripe_subscriptions_approved,
    ADD CONSTRAINT ck_stripe_subscriptions_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Customer ID","Customer Email","Plan","Product","Interval","Amount","Status","Created (UTC)","Coupon","Canceled At (UTC)","Customer Name","Product ID","Quantity","Currency","Start (UTC)","Start Date (UTC)","Current Period Start (UTC)","Current Period End (UTC)","Cancel At Period End","Ended At (UTC)"]', JSON_KEYS(raw_values)) = 1);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_subscription_item_rates AS
SELECT x.subscription_item_id, x.subscription_id, x.stripe_subscription_item_id,
       x.stripe_price_id, x.stripe_product_id, x.product_name,
       x.amount_minor, x.amount_basis, x.quantity, x.currency,
       x.billing_interval, x.billing_interval_count,
       CASE WHEN x.amount_basis = 'csv_unresolved' OR x.amount_minor IS NULL OR x.currency IS NULL OR x.billing_interval_count IS NULL
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
           p.stripe_price_id, COALESCE(pr.stripe_product_id, csv_product.stripe_product_id) AS stripe_product_id,
           COALESCE(NULLIF(i.product_name_snapshot, ''), pr.name, csv_product.name) AS product_name,
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
    LEFT JOIN stripe_products csv_product ON csv_product.id = i.product_id
) x;
