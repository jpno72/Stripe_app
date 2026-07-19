const { stripe } = require("../config/stripe");
const { env } = require("../config/env");

async function listActiveCustomers() {
  if (env.localStripeMockMode) {
    return [];
  }

  const cutoff = Math.floor((Date.now() - 45 * 24 * 60 * 60 * 1000) / 1000);
  const [subscriptions, paymentIntents] = await Promise.all([
    stripe.subscriptions.list({ status: "active", limit: 100 }),
    stripe.paymentIntents.list({ created: { gte: cutoff }, limit: 100 })
  ]);

  const customerIds = new Set();
  subscriptions.data.forEach((subscription) => {
    const customerId = getObjectId(subscription.customer);
    if (customerId) customerIds.add(customerId);
  });
  paymentIntents.data.forEach((paymentIntent) => {
    const customerId = getObjectId(paymentIntent.customer);
    if (customerId) customerIds.add(customerId);
  });

  const customers = await Promise.all(
    Array.from(customerIds).map((id) => stripe.customers.retrieve(id))
  );

  return customers
    .filter((customer) => !customer.deleted)
    .map((customer) => ({
      id: customer.id,
      name: customer.name || null,
      email: customer.email || null,
      phone: customer.phone || null,
      metadata: customer.metadata || {}
    }));
}

async function listActiveSubscriptions() {
  if (env.localStripeMockMode) {
    return [];
  }

  const subscriptions = await stripe.subscriptions.list({
    status: "active",
    limit: 100,
    expand: ["data.customer"]
  });

  return Promise.all(subscriptions.data.map(async (subscription) => {
    const item = subscription.items.data[0] || {};
    const price = item.price || {};
    const customer = typeof subscription.customer === "object" ? subscription.customer : null;
    const productName = await resolveProductName(price);

    return {
      id: subscription.id,
      status: subscription.status,
      customerName: customer ? customer.name || null : null,
      customerEmail: customer ? customer.email || null : null,
      productName,
      unitAmount: price.unit_amount || 0,
      currency: price.currency || env.defaultCurrency,
      interval: price.recurring ? price.recurring.interval : null
    };
  }));
}

async function listRecentTransactions() {
  if (env.localStripeMockMode) {
    return [];
  }

  const [paymentIntents, subscriptions] = await Promise.all([
    stripe.paymentIntents.list({ limit: 50 }),
    stripe.subscriptions.list({ limit: 50, expand: ["data.customer"] })
  ]);

  const payments = paymentIntents.data.map((paymentIntent) => ({
    id: paymentIntent.id,
    type: "payment",
    status: paymentIntent.status,
    customerId: getObjectId(paymentIntent.customer),
    description: paymentIntent.description || "Payment",
    amount: paymentIntent.amount,
    currency: paymentIntent.currency,
    created: paymentIntent.created
  }));

  const subscriptionAttempts = await Promise.all(subscriptions.data.map(async (subscription) => {
    const item = subscription.items.data[0] || {};
    const price = item.price || {};
    const customer = typeof subscription.customer === "object" ? subscription.customer : null;
    const productName = await resolveProductName(price);

    return {
      id: subscription.id,
      type: "subscription",
      status: subscription.status,
      customerId: customer ? customer.id : getObjectId(subscription.customer),
      description: productName,
      amount: price.unit_amount || 0,
      currency: price.currency || env.defaultCurrency,
      created: subscription.created
    };
  }));

  return payments.concat(subscriptionAttempts)
    .sort((a, b) => b.created - a.created)
    .slice(0, 50);
}

function getObjectId(value) {
  if (!value) return null;
  return typeof value === "string" ? value : value.id;
}

async function resolveProductName(price) {
  if (!price) return "Subscription";
  if (price.nickname) return price.nickname;
  if (!price.product) return price.id || "Subscription";
  if (typeof price.product === "object") return price.product.name || price.id || "Subscription";

  try {
    const product = await stripe.products.retrieve(price.product);
    return product.name || price.id || "Subscription";
  } catch (_error) {
    return price.id || "Subscription";
  }
}

module.exports = {
  listActiveCustomers,
  listActiveSubscriptions,
  listRecentTransactions
};
