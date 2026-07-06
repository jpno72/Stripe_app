const { stripe } = require("../config/stripe");
const { getCustomerById } = require("../repositories/customerRepository");
const {
  upsertSubscriptionRecord,
  getSubscriptionById,
  listSubscriptions
} = require("../repositories/subscriptionRepository");
const { HttpError } = require("../errors/httpError");

async function createSubscription(input) {
  const customer = await getCustomerById(input.customerId);
  if (!customer) {
    throw new HttpError(404, "Customer not found");
  }

  const subscription = await stripe.subscriptions.create({
    customer: customer.stripe_customer_id,
    items: [
      {
        price: input.priceId,
        quantity: input.quantity || 1
      }
    ],
    payment_behavior: input.paymentBehavior || "default_incomplete",
    trial_period_days: input.trialPeriodDays,
    metadata: input.metadata || {},
    expand: ["latest_invoice.payment_intent"]
  });

  const record = await saveStripeSubscription(subscription, customer.id);
  return { subscription: record, stripeSubscription: subscription };
}

async function readSubscription(id) {
  const subscription = await getSubscriptionById(id);
  if (!subscription) {
    throw new HttpError(404, "Subscription not found");
  }

  return subscription;
}

async function findSubscriptions(filters) {
  return listSubscriptions(filters);
}

async function cancelSubscription(id, cancelAtPeriodEnd) {
  const existing = await readSubscription(id);
  const stripeSubscription = cancelAtPeriodEnd
    ? await stripe.subscriptions.update(existing.stripe_subscription_id, { cancel_at_period_end: true })
    : await stripe.subscriptions.cancel(existing.stripe_subscription_id);

  const record = await saveStripeSubscription(stripeSubscription, existing.customer_id);
  return { subscription: record, stripeSubscription };
}

async function saveStripeSubscription(stripeSubscription, localCustomerId) {
  const firstItem = stripeSubscription.items && stripeSubscription.items.data
    ? stripeSubscription.items.data[0]
    : null;

  return upsertSubscriptionRecord({
    stripeSubscriptionId: stripeSubscription.id,
    customerId: localCustomerId,
    stripeCustomerId: typeof stripeSubscription.customer === "string"
      ? stripeSubscription.customer
      : stripeSubscription.customer.id,
    stripePriceId: firstItem && firstItem.price ? firstItem.price.id : null,
    status: stripeSubscription.status,
    currentPeriodStart: stripeSubscription.current_period_start,
    currentPeriodEnd: stripeSubscription.current_period_end,
    cancelAtPeriodEnd: stripeSubscription.cancel_at_period_end,
    canceledAt: stripeSubscription.canceled_at,
    metadata: stripeSubscription.metadata
  });
}

module.exports = {
  createSubscription,
  readSubscription,
  findSubscriptions,
  cancelSubscription,
  saveStripeSubscription
};

