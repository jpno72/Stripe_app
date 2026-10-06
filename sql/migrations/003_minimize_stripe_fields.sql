-- Approved Step 1 minimization. 001 and 002 remain immutable.
-- The runner refuses this migration if historical source rows or affected values exist.
ALTER TABLE stripe_customers
    DROP COLUMN card_or_payment_method_id,
    DROP COLUMN description;

ALTER TABLE stripe_transactions
    DROP COLUMN card_or_payment_method_id,
    DROP COLUMN customer_description_snapshot;

ALTER TABLE stripe_subscriptions
    DROP COLUMN customer_description_snapshot;

ALTER TABLE raw_stripe_customers
    DROP CONSTRAINT ck_stripe_customers_approved,
    ADD CONSTRAINT ck_stripe_customers_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Email","Name","Created (UTC)","Address Line1","Address City","Address Postal Code","Address Country","Total Spend","Payment Count","Refunded Volume","Dispute Losses"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE raw_stripe_subscriptions
    DROP CONSTRAINT ck_stripe_subscriptions_approved,
    ADD CONSTRAINT ck_stripe_subscriptions_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Customer ID","Customer Email","Plan","Product","Interval","Amount","Status","Created (UTC)","Coupon","Canceled At (UTC)","Customer Name"]', JSON_KEYS(raw_values)) = 1);

ALTER TABLE raw_stripe_transactions
    DROP CONSTRAINT ck_stripe_transactions_approved,
    ADD CONSTRAINT ck_stripe_transactions_approved CHECK (JSON_TYPE(raw_values) = 'OBJECT' AND JSON_CONTAINS('["id","Created date (UTC)","Amount","Amount Refunded","Currency","Captured","Converted Amount","Converted Amount Refunded","Converted Currency","Decline Reason","Description","Fee","Refunded date (UTC)","Statement Descriptor","Status","Seller Message","Taxes On Fee","Customer ID","Customer Email","Invoice ID","Transfer"]', JSON_KEYS(raw_values)) = 1);
