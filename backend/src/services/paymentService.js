const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { getCustomerById } = require("../repositories/customerRepository");
const { createPaymentRecord } = require("../repositories/paymentRepository");
const { HttpError } = require("../errors/httpError");

async function createTerminalConnectionToken() {
  if (env.localStripeMockMode) {
    return { secret: "pst_mock_phase2_connection_token_secret" };
  }

  return stripe.terminal.connectionTokens.create();
}

async function createCardPresentPaymentIntent(input) {
  if (env.localStripeMockMode) {
    return createMockCardPresentPaymentIntent(input);
  }

  let customer = null;

  if (input.customerId) {
    customer = await getCustomerById(input.customerId);
    if (!customer) {
      throw new HttpError(404, "Customer not found");
    }
  }

  const intent = await stripe.paymentIntents.create({
    amount: input.amount,
    currency: input.currency || env.defaultCurrency,
    customer: customer ? customer.stripe_customer_id : undefined,
    payment_method_types: ["card_present"],
    capture_method: input.captureMethod || "automatic",
    description: input.description,
    metadata: input.metadata || {}
  });

  const payment = await createPaymentRecord({
    stripePaymentIntentId: intent.id,
    customerId: customer ? customer.id : null,
    stripeCustomerId: customer ? customer.stripe_customer_id : null,
    amount: intent.amount,
    currency: intent.currency,
    status: intent.status,
    captureMethod: intent.capture_method,
    description: intent.description,
    metadata: intent.metadata
  });

  return { payment, paymentIntent: intent };
}

module.exports = { createTerminalConnectionToken, createCardPresentPaymentIntent };

function createMockCardPresentPaymentIntent(input) {
  const currency = input.currency || env.defaultCurrency;
  const createdAt = Date.now();
  const intentId = `pi_mock_phase2_${createdAt}`;

  return {
    payment: {
      id: 0,
      stripe_payment_intent_id: intentId,
      customer_id: input.customerId || null,
      stripe_customer_id: null,
      amount: input.amount,
      amount_received: 0,
      currency,
      status: "requires_payment_method",
      capture_method: input.captureMethod || "automatic",
      latest_charge_id: null,
      description: input.description || null,
      metadata: input.metadata || {}
    },
    paymentIntent: {
      id: intentId,
      client_secret: `${intentId}_secret_mock_phase2`,
      amount: input.amount,
      currency,
      status: "requires_payment_method",
      payment_method_types: ["card_present"],
      capture_method: input.captureMethod || "automatic",
      description: input.description || null,
      metadata: input.metadata || {}
    }
  };
}
