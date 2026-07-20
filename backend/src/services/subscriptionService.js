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
  const plan = await getMembershipPlan(input.planId, input.priceId);
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
    customer: getStripeCustomerId(customer),
    items: [
      {
        price: plan.stripePriceId,
        quantity: input.quantity || 1
      }
    ],
    payment_behavior: input.paymentBehavior || "allow_incomplete",
    trial_period_days: input.trialPeriodDays,
    metadata: input.metadata || {},
    expand: ["latest_invoice.payment_intent"]
  });

  if (env.useStripeOnlyPersistence) {
    return { subscription: toSubscriptionSummary(subscription, null), stripeSubscription: subscription, plan };
  }

  const record = await saveStripeSubscription(subscription, customer.id);
  return { subscription: record, stripeSubscription: subscription, plan };
}

async function listMembershipPlans() {
  if (env.localStripeMockMode) {
    return env.membershipPlans.map((plan) => ({
      ...plan,
      unitAmount: 0,
      currency: env.defaultCurrency,
      interval: "month"
    }));
  }

  if (env.membershipPlans.length === 0) {
    return listActiveStripeRecurringPrices();
  }

  return Promise.all(env.membershipPlans.map(async (plan) => {
    try {
      const price = await stripe.prices.retrieve(plan.stripePriceId);
      return {
        ...plan,
        unitAmount: price.unit_amount || 0,
        currency: price.currency || env.defaultCurrency,
        interval: price.recurring ? price.recurring.interval : null
      };
    } catch (_error) {
      return {
        ...plan,
        unitAmount: 0,
        currency: env.defaultCurrency,
        interval: null
      };
    }
  }));
}

async function readSubscription(id) {
  if (env.localStripeMockMode) {
    const subscription = getMockSubscriptionById(id);
    if (!subscription) {
      throw new HttpError(404, "Subscription not found");
    }
    return subscription;
  }

  if (env.useStripeOnlyPersistence) {
    try {
      const subscription = await stripe.subscriptions.retrieve(id, {
        expand: ["latest_invoice.payment_intent"]
      });
      return toSubscriptionSummary(subscription, null);
    } catch (error) {
      if (error.statusCode === 404 || error.status === 404) {
        throw new HttpError(404, "Subscription not found");
      }
      throw error;
    }
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

  if (env.useStripeOnlyPersistence) {
    const params = { limit: 50 };
    if (filters.customerId) {
      params.customer = filters.customerId;
    }
    if (filters.status) {
      params.status = filters.status;
    }

    const subscriptions = await stripe.subscriptions.list(params);
    return subscriptions.data.map((subscription) => toSubscriptionSummary(subscription, null));
  }

  return listSubscriptions(filters);
}

async function cancelSubscription(id, cancelAtPeriodEnd) {
  if (env.useStripeOnlyPersistence && !env.localStripeMockMode) {
    const stripeSubscription = cancelAtPeriodEnd
      ? await stripe.subscriptions.update(id, { cancel_at_period_end: true })
      : await stripe.subscriptions.cancel(id);
    return {
      subscription: toSubscriptionSummary(stripeSubscription, null),
      stripeSubscription
    };
  }

  const existing = await readSubscription(id);
  const stripeSubscription = cancelAtPeriodEnd
    ? await stripe.subscriptions.update(existing.stripe_subscription_id, { cancel_at_period_end: true })
    : await stripe.subscriptions.cancel(existing.stripe_subscription_id);

  const record = await saveStripeSubscription(stripeSubscription, existing.customer_id);
  return { subscription: record, stripeSubscription };
}

async function saveStripeSubscription(stripeSubscription, localCustomerId) {
  if (env.useStripeOnlyPersistence) {
    return toSubscriptionSummary(stripeSubscription, localCustomerId);
  }

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
    if (env.useStripeOnlyPersistence) {
      try {
        const customer = await stripe.customers.retrieve(input.customerId);
        if (customer.deleted) {
          throw new HttpError(404, "Customer not found");
        }
        return customer;
      } catch (error) {
        if (error.statusCode === 404 || error.status === 404) {
          throw new HttpError(404, "Customer not found");
        }
        throw error;
      }
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

async function getMembershipPlan(planId, priceId) {
  if (env.membershipPlans.length === 0 && (priceId || planId)) {
    return getStripePriceAsMembershipPlan(priceId || planId);
  }

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

async function listActiveStripeRecurringPrices() {
  const prices = await stripe.prices.list({
    active: true,
    type: "recurring",
    expand: ["data.product"],
    limit: 100
  });

  return prices.data
    .filter((price) => price.recurring)
    .filter((price) => isActiveProduct(price.product))
    .map(stripePriceToMembershipPlan)
    .sort((left, right) => left.name.localeCompare(right.name));
}

async function getStripePriceAsMembershipPlan(priceId) {
  try {
    const price = await stripe.prices.retrieve(priceId, {
      expand: ["product"]
    });
    if (!price.active || !price.recurring || !isActiveProduct(price.product)) {
      throw new HttpError(400, "Stripe price is not an active recurring membership price");
    }
    return stripePriceToMembershipPlan(price);
  } catch (error) {
    if (error instanceof HttpError) {
      throw error;
    }
    if (error.statusCode === 404 || error.status === 404) {
      throw new HttpError(400, "Membership price was not found in Stripe");
    }
    throw error;
  }
}

function stripePriceToMembershipPlan(price) {
  const product = typeof price.product === "object" && price.product ? price.product : null;
  const productName = product && product.name ? product.name : price.nickname || price.id;
  const description = product && product.description ? product.description : price.nickname || null;

  return {
    id: price.id,
    name: productName,
    stripePriceId: price.id,
    description,
    unitAmount: price.unit_amount || 0,
    currency: price.currency || env.defaultCurrency,
    interval: price.recurring ? price.recurring.interval : null
  };
}

function isActiveProduct(product) {
  return !(typeof product === "object" && product && product.active === false);
}

function getStripeCustomerId(customer) {
  return customer.stripe_customer_id || customer.id;
}

function toSubscriptionSummary(stripeSubscription, localCustomerId) {
  const firstItem = stripeSubscription.items && stripeSubscription.items.data
    ? stripeSubscription.items.data[0]
    : null;
  const stripeCustomerId = typeof stripeSubscription.customer === "string"
    ? stripeSubscription.customer
    : stripeSubscription.customer.id;

  return {
    id: stripeSubscription.id,
    stripe_subscription_id: stripeSubscription.id,
    customer_id: localCustomerId || stripeCustomerId,
    stripe_customer_id: stripeCustomerId,
    stripe_price_id: firstItem && firstItem.price ? firstItem.price.id : null,
    status: stripeSubscription.status,
    current_period_start: stripeSubscription.current_period_start || null,
    current_period_end: stripeSubscription.current_period_end || null,
    cancel_at_period_end: stripeSubscription.cancel_at_period_end ? 1 : 0,
    canceled_at: stripeSubscription.canceled_at || null,
    metadata: stripeSubscription.metadata || {}
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
