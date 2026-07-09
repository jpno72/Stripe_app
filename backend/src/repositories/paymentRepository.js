const { query } = require("../db/pool");

async function createPaymentRecord(payment) {
  const result = await query(
    `INSERT INTO payments
      (stripe_payment_intent_id, customer_id, stripe_customer_id, amount, currency, status, capture_method, description, metadata)
     VALUES
      (:stripePaymentIntentId, :customerId, :stripeCustomerId, :amount, :currency, :status, :captureMethod, :description, :metadata)`,
    {
      stripePaymentIntentId: payment.stripePaymentIntentId,
      customerId: payment.customerId || null,
      stripeCustomerId: payment.stripeCustomerId || null,
      amount: payment.amount,
      currency: payment.currency,
      status: payment.status,
      captureMethod: payment.captureMethod,
      description: payment.description || null,
      metadata: JSON.stringify(payment.metadata || {})
    }
  );

  return getPaymentById(result.insertId);
}

async function updatePaymentFromIntent(intent) {
  await query(
    `UPDATE payments
     SET status = :status,
       amount_received = :amountReceived,
       latest_charge_id = :latestChargeId,
       updated_at = CURRENT_TIMESTAMP
     WHERE stripe_payment_intent_id = :stripePaymentIntentId`,
    {
      status: intent.status,
      amountReceived: intent.amount_received || 0,
      latestChargeId: typeof intent.latest_charge === "string" ? intent.latest_charge : null,
      stripePaymentIntentId: intent.id
    }
  );

  return getPaymentByStripePaymentIntentId(intent.id);
}

async function getPaymentById(id) {
  const rows = await query("SELECT * FROM payments WHERE id = :id", { id });
  return rows[0] || null;
}

async function getPaymentByStripePaymentIntentId(stripePaymentIntentId) {
  const rows = await query(
    "SELECT * FROM payments WHERE stripe_payment_intent_id = :stripePaymentIntentId",
    { stripePaymentIntentId }
  );
  return rows[0] || null;
}

module.exports = {
  createPaymentRecord,
  updatePaymentFromIntent,
  getPaymentById,
  getPaymentByStripePaymentIntentId
};
