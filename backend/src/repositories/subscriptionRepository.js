const { query } = require("../db/pool");

async function upsertSubscriptionRecord(subscription) {
  await query(
    `INSERT INTO subscriptions
      (stripe_subscription_id, customer_id, stripe_customer_id, stripe_price_id, status, current_period_start, current_period_end, cancel_at_period_end, canceled_at, metadata)
     VALUES
      (:stripeSubscriptionId, :customerId, :stripeCustomerId, :stripePriceId, :status, FROM_UNIXTIME(:currentPeriodStart), FROM_UNIXTIME(:currentPeriodEnd), :cancelAtPeriodEnd, FROM_UNIXTIME(:canceledAt), :metadata)
     ON DUPLICATE KEY UPDATE
      customer_id = VALUES(customer_id),
      stripe_customer_id = VALUES(stripe_customer_id),
      stripe_price_id = VALUES(stripe_price_id),
      status = VALUES(status),
      current_period_start = VALUES(current_period_start),
      current_period_end = VALUES(current_period_end),
      cancel_at_period_end = VALUES(cancel_at_period_end),
      canceled_at = VALUES(canceled_at),
      metadata = VALUES(metadata),
      updated_at = CURRENT_TIMESTAMP`,
    {
      stripeSubscriptionId: subscription.stripeSubscriptionId,
      customerId: subscription.customerId || null,
      stripeCustomerId: subscription.stripeCustomerId,
      stripePriceId: subscription.stripePriceId,
      status: subscription.status,
      currentPeriodStart: subscription.currentPeriodStart || null,
      currentPeriodEnd: subscription.currentPeriodEnd || null,
      cancelAtPeriodEnd: subscription.cancelAtPeriodEnd ? 1 : 0,
      canceledAt: subscription.canceledAt || null,
      metadata: JSON.stringify(subscription.metadata || {})
    }
  );

  return getSubscriptionByStripeId(subscription.stripeSubscriptionId);
}

async function getSubscriptionById(id) {
  const rows = await query("SELECT * FROM subscriptions WHERE id = :id", { id });
  return rows[0] || null;
}

async function getSubscriptionByStripeId(stripeSubscriptionId) {
  const rows = await query(
    "SELECT * FROM subscriptions WHERE stripe_subscription_id = :stripeSubscriptionId",
    { stripeSubscriptionId }
  );
  return rows[0] || null;
}

async function listSubscriptions(filters) {
  const where = [];
  const params = {};

  if (filters.customerId) {
    where.push("customer_id = :customerId");
    params.customerId = filters.customerId;
  }

  if (filters.status) {
    where.push("status = :status");
    params.status = filters.status;
  }

  const sql = `SELECT * FROM subscriptions ${where.length ? `WHERE ${where.join(" AND ")}` : ""} ORDER BY created_at DESC LIMIT 50`;
  return query(sql, params);
}

module.exports = {
  upsertSubscriptionRecord,
  getSubscriptionById,
  getSubscriptionByStripeId,
  listSubscriptions
};
