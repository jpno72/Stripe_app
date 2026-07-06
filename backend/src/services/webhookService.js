const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { logger } = require("../config/logger");
const { createWebhookEvent } = require("../repositories/webhookRepository");
const { updatePaymentFromIntent } = require("../repositories/paymentRepository");
const { upsertCustomerRecord, getCustomerByStripeId } = require("../repositories/customerRepository");
const { saveStripeSubscription } = require("./subscriptionService");

function constructStripeEvent(rawBody, signature) {
  return stripe.webhooks.constructEvent(rawBody, signature, env.stripeWebhookSecret);
}

async function processStripeEvent(event) {
  await createWebhookEvent(event);

  switch (event.type) {
    case "payment_intent.succeeded":
    case "payment_intent.payment_failed":
    case "payment_intent.canceled":
    case "payment_intent.processing":
      await updatePaymentFromIntent(event.data.object);
      break;
    case "customer.created":
    case "customer.updated":
      await upsertCustomerRecord({
        stripeCustomerId: event.data.object.id,
        name: event.data.object.name,
        email: event.data.object.email,
        phone: event.data.object.phone,
        metadata: event.data.object.metadata
      });
      break;
    case "customer.subscription.created":
    case "customer.subscription.updated":
    case "customer.subscription.deleted": {
      const stripeSubscription = event.data.object;
      const localCustomer = await getCustomerByStripeId(
        typeof stripeSubscription.customer === "string"
          ? stripeSubscription.customer
          : stripeSubscription.customer.id
      );
      await saveStripeSubscription(stripeSubscription, localCustomer ? localCustomer.id : null);
      break;
    }
    default:
      logger.info({ stripeEventId: event.id, type: event.type }, "stripe event stored without handler");
      break;
  }
}

module.exports = { constructStripeEvent, processStripeEvent };

