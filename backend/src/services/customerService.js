const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const {
  createMockCustomer,
  getMockCustomerById,
  searchMockCustomers
} = require("./mockStore");
const {
  createCustomerRecord,
  getCustomerById,
  searchCustomers
} = require("../repositories/customerRepository");
const { HttpError } = require("../errors/httpError");

async function createCustomer(input) {
  if (env.localStripeMockMode) {
    return createMockCustomer(input);
  }

  const stripeCustomer = await stripe.customers.create({
    name: input.name,
    email: input.email,
    phone: input.phone,
    metadata: input.metadata || {}
  });

  if (env.useStripeOnlyPersistence) {
    return toCustomerSummary(stripeCustomer);
  }

  return createCustomerRecord({
    stripeCustomerId: stripeCustomer.id,
    name: stripeCustomer.name,
    email: stripeCustomer.email,
    phone: stripeCustomer.phone,
    metadata: stripeCustomer.metadata
  });
}

async function readCustomer(id) {
  if (env.localStripeMockMode) {
    const customer = getMockCustomerById(id);
    if (!customer) {
      throw new HttpError(404, "Customer not found");
    }
    return customer;
  }

  if (env.useStripeOnlyPersistence) {
    try {
      const customer = await stripe.customers.retrieve(id);
      if (customer.deleted) {
        throw new HttpError(404, "Customer not found");
      }
      return toCustomerSummary(customer);
    } catch (error) {
      if (error.statusCode === 404 || error.status === 404) {
        throw new HttpError(404, "Customer not found");
      }
      throw error;
    }
  }

  const customer = await getCustomerById(id);
  if (!customer) {
    throw new HttpError(404, "Customer not found");
  }

  return customer;
}

async function findCustomers(filters) {
  if (env.localStripeMockMode) {
    return searchMockCustomers(filters);
  }

  if (env.useStripeOnlyPersistence) {
    if (filters.email) {
      const customers = await stripe.customers.list({ email: filters.email, limit: 50 });
      return customers.data.map(toCustomerSummary);
    }

    const query = filters.q ? String(filters.q).toLowerCase() : null;
    const phone = filters.phone ? String(filters.phone).toLowerCase() : null;
    if (query && !phone) {
      const searchedCustomers = await searchStripeCustomers(query);
      if (searchedCustomers.length > 0) {
        return searchedCustomers;
      }
    }

    const customers = await listStripeCustomersForLocalFilter();

    return customers.data
      .filter((customer) => {
        if (phone && String(customer.phone || "").toLowerCase() !== phone) return false;
        if (!query) return true;
        return [customer.name, customer.email, customer.phone]
          .some((value) => String(value || "").toLowerCase().includes(query));
      })
      .map(toCustomerSummary);
  }

  return searchCustomers(filters);
}

module.exports = { createCustomer, readCustomer, findCustomers };

function toCustomerSummary(customer) {
  return {
    id: customer.id,
    stripe_customer_id: customer.id,
    name: customer.name || null,
    email: customer.email || null,
    phone: customer.phone || null,
    metadata: customer.metadata || {}
  };
}

async function searchStripeCustomers(query) {
  const normalized = query.trim();
  if (!normalized) {
    return [];
  }

  const exactEmailCustomers = normalized.includes("@")
    ? await stripe.customers.list({ email: normalized, limit: 100 })
    : null;
  if (exactEmailCustomers && exactEmailCustomers.data.length > 0) {
    return exactEmailCustomers.data.map(toCustomerSummary);
  }

  const searchValue = escapeStripeSearchValue(normalized);
  const search = await stripe.customers.search({
    query: [
      `name:"${searchValue}"`,
      `email:"${searchValue}"`,
      `phone:"${searchValue}"`
    ].join(" OR "),
    limit: 100
  });

  return search.data.map(toCustomerSummary);
}

async function listStripeCustomersForLocalFilter() {
  if (typeof stripe.customers.list({ limit: 1 }).autoPagingToArray === "function") {
    const data = await stripe.customers
      .list({ limit: 100 })
      .autoPagingToArray({ limit: 500 });
    return { data };
  }

  return stripe.customers.list({ limit: 100 });
}

function escapeStripeSearchValue(value) {
  return value.replace(/\\/g, "\\\\").replace(/"/g, "\\\"");
}
