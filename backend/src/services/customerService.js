const { stripe } = require("../config/stripe");
const {
  createCustomerRecord,
  getCustomerById,
  searchCustomers
} = require("../repositories/customerRepository");
const { HttpError } = require("../errors/httpError");

async function createCustomer(input) {
  const stripeCustomer = await stripe.customers.create({
    name: input.name,
    email: input.email,
    phone: input.phone,
    metadata: input.metadata || {}
  });

  return createCustomerRecord({
    stripeCustomerId: stripeCustomer.id,
    name: stripeCustomer.name,
    email: stripeCustomer.email,
    phone: stripeCustomer.phone,
    metadata: stripeCustomer.metadata
  });
}

async function readCustomer(id) {
  const customer = await getCustomerById(id);
  if (!customer) {
    throw new HttpError(404, "Customer not found");
  }

  return customer;
}

async function findCustomers(filters) {
  return searchCustomers(filters);
}

module.exports = { createCustomer, readCustomer, findCustomers };

