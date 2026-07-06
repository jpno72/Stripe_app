const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { getCustomerById } = require("../repositories/customerRepository");
const { createPaymentRecord } = require("../repositories/paymentRepository");
const { HttpError } = require("../errors/httpError");

async function createTerminalConnectionToken() {
  return stripe.terminal.connectionTokens.create();
}

async function createCardPresentPaymentIntent(input) {
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
