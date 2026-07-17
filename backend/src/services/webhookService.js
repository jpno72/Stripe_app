const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { logger } = require("../config/logger");
const {
  createWebhookEvent,
  markWebhookEventProcessed
} = require("../repositories/webhookRepository");
const { updatePaymentFromIntent } = require("../repositories/paymentRepository");
const { upsertInvoiceRecord } = require("../repositories/invoiceRepository");
const { upsertCustomerRecord, getCustomerByStripeId } = require("../repositories/customerRepository");
const { saveStripeSubscription } = require("./subscriptionService");

function constructStripeEvent(rawBody, signature) {
  return stripe.webhooks.constructEvent(rawBody, signature, env.stripeWebhookSecret);
}

async function processStripeEvent(event) {
  if (env.useStripeOnlyPersistence) {
    logger.info({ stripeEventId: event.id, type: event.type }, "stripe event verified");
    return;
  }

  const webhookEvent = await createWebhookEvent(event);
  if (!webhookEvent.shouldProcess) {
    logger.info({ stripeEventId: event.id, type: event.type }, "stripe event already processed");
    return;
  }

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
    case "invoice.created":
    case "invoice.finalized":
    case "invoice.paid":
    case "invoice.payment_succeeded":
    case "invoice.payment_failed":
    case "invoice.voided":
    case "invoice.marked_uncollectible": {
      await saveStripeInvoice(event.data.object);
      break;
    }
    default:
      logger.info({ stripeEventId: event.id, type: event.type }, "stripe event stored without handler");
      break;
  }

  await markWebhookEventProcessed(event.id);
}

async function saveStripeInvoice(invoice) {
  await upsertInvoiceRecord({
    stripeInvoiceId: invoice.id,
    stripeSubscriptionId: typeof invoice.subscription === "string" ? invoice.subscription : null,
    stripeCustomerId: typeof invoice.customer === "string" ? invoice.customer : null,
    status: invoice.status,
    amountDue: invoice.amount_due,
    amountPaid: invoice.amount_paid,
    currency: invoice.currency,
    hostedInvoiceUrl: invoice.hosted_invoice_url,
    invoicePdf: invoice.invoice_pdf
  });
}

module.exports = { constructStripeEvent, processStripeEvent };
