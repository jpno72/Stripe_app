const customers = [];
const subscriptions = [];

let nextCustomerId = 1;
let nextSubscriptionId = 1;

function createMockCustomer(input) {
  const customer = {
    id: nextCustomerId++,
    stripe_customer_id: `cus_mock_phase3_${Date.now()}`,
    name: input.name || null,
    email: input.email || null,
    phone: input.phone || null,
    metadata: input.metadata || {},
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString()
  };
  customers.unshift(customer);
  return customer;
}

function getMockCustomerById(id) {
  return customers.find((customer) => customer.id === Number(id)) || null;
}

function searchMockCustomers(filters) {
  const query = (filters.q || "").toLowerCase();
  return customers.filter((customer) => {
    if (filters.email && customer.email !== filters.email) return false;
    if (filters.phone && customer.phone !== filters.phone) return false;
    if (!query) return true;
    return [customer.name, customer.email, customer.phone]
      .filter(Boolean)
      .some((value) => value.toLowerCase().includes(query));
  }).slice(0, 50);
}

function createMockSubscriptionRecord(stripeSubscription, localCustomerId) {
  const firstItem = stripeSubscription.items && stripeSubscription.items.data
    ? stripeSubscription.items.data[0]
    : null;
  const record = {
    id: nextSubscriptionId++,
    stripe_subscription_id: stripeSubscription.id,
    customer_id: localCustomerId || null,
    stripe_customer_id: typeof stripeSubscription.customer === "string"
      ? stripeSubscription.customer
      : stripeSubscription.customer.id,
    stripe_price_id: firstItem && firstItem.price ? firstItem.price.id : null,
    status: stripeSubscription.status,
    current_period_start: new Date(stripeSubscription.current_period_start * 1000).toISOString(),
    current_period_end: new Date(stripeSubscription.current_period_end * 1000).toISOString(),
    cancel_at_period_end: stripeSubscription.cancel_at_period_end ? 1 : 0,
    canceled_at: stripeSubscription.canceled_at
      ? new Date(stripeSubscription.canceled_at * 1000).toISOString()
      : null,
    metadata: stripeSubscription.metadata || {},
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString()
  };
  subscriptions.unshift(record);
  return record;
}

function getMockSubscriptionById(id) {
  return subscriptions.find((subscription) => subscription.id === Number(id)) || null;
}

function listMockSubscriptions(filters) {
  return subscriptions.filter((subscription) => {
    if (filters.customerId && subscription.customer_id !== Number(filters.customerId)) return false;
    if (filters.status && subscription.status !== filters.status) return false;
    return true;
  }).slice(0, 50);
}

module.exports = {
  createMockCustomer,
  getMockCustomerById,
  searchMockCustomers,
  createMockSubscriptionRecord,
  getMockSubscriptionById,
  listMockSubscriptions
};

