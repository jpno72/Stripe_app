const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { getCustomerById } = require("../repositories/customerRepository");
const { createCustomer } = require("./customerService");
const {
  getMockCustomerById,
  createMockSubscriptionRecord,
  getMockSubscriptionById,
  listMockSubscriptions
} = require("./mockStore");
const {
  upsertSubscriptionRecord,
  getSubscriptionById,
  listSubscriptions
} = require("../repositories/subscriptionRepository");
const { HttpError } = require("../errors/httpError");

async function createSubscription(input) {
  const plan = getMembershipPlan(input.planId, input.priceId);
  const customer = await resolveCustomer(input);
  if (!customer) {
    throw new HttpError(404, "Customer not found");
  }

  if (env.localStripeMockMode) {
    const mockSubscription = createMockSubscription(customer, plan, input.metadata || {});
    const record = createMockSubscriptionRecord(mockSubscription, customer.id);
    return { subscription: record, stripeSubscription: mockSubscription, plan };
  }

  // Recurring subscriptions require a reusable customer payment method.
  // A card-present Terminal payment does not automatically create one for future off-session billing.
  const subscription = await stripe.subscriptions.create({
    customer: customer.stripe_customer_id,
    items: [
      {
        price: plan.stripePriceId,
        quantity: input.quantity || 1
      }
    ],
    payment_behavior: input.paymentBehavior || "default_incomplete",
    trial_period_days: input.trialPeriodDays,
    metadata: input.metadata || {},
    expand: ["latest_invoice.payment_intent"]
  });

  const record = await saveStripeSubscription(subscription, customer.id);
  return { subscription: record, stripeSubscription: subscription, plan };
}

function listMembershipPlans() {
  return env.membershipPlans;
}

async function readSubscription(id) {
  if (env.localStripeMockMode) {
    const subscription = getMockSubscriptionById(id);
    if (!subscription) {
      throw new HttpError(404, "Subscription not found");
    }
    return subscription;
  }

  const subscription = await getSubscriptionById(id);
  if (!subscription) {
    throw new HttpError(404, "Subscription not found");
  }

  return subscription;
}

async function findSubscriptions(filters) {
  if (env.localStripeMockMode) {
    return listMockSubscriptions(filters);
  }

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

async function resolveCustomer(input) {
  if (input.customerId) {
    if (env.localStripeMockMode) {
      return getMockCustomerById(input.customerId);
    }
    return getCustomerById(input.customerId);
  }

  if (input.customer) {
    return createCustomer({
      name: input.customer.name,
      email: input.customer.email,
      phone: input.customer.phone,
      metadata: input.customer.metadata || {}
    });
  }

  throw new HttpError(400, "customerId or customer is required");
}

function getMembershipPlan(planId, priceId) {
  if (env.membershipPlans.length === 0) {
    throw new HttpError(500, "No membership plans are configured");
  }

  const plan = env.membershipPlans.find((candidate) => {
    return (planId && candidate.id === planId) || (priceId && candidate.stripePriceId === priceId);
  });

  if (!plan) {
    throw new HttpError(400, "Membership plan is not configured");
  }

  return plan;
}

function createMockSubscription(customer, plan, metadata) {
  const now = Math.floor(Date.now() / 1000);
  const id = `sub_mock_phase3_${Date.now()}`;

  return {
    id,
    customer: customer.stripe_customer_id,
    status: "incomplete",
    current_period_start: now,
    current_period_end: now + 30 * 24 * 60 * 60,
    cancel_at_period_end: false,
    canceled_at: null,
    metadata,
    items: {
      data: [
        {
          price: {
            id: plan.stripePriceId
          }
        }
      ]
    }
  };
}

module.exports = {
  listMembershipPlans,
  createSubscription,
  readSubscription,
  findSubscriptions,
  cancelSubscription,
  saveStripeSubscription
};
