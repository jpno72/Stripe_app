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
