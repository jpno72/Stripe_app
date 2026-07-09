const { query } = require("../db/pool");

async function createCustomerRecord(customer) {
  const result = await query(
    `INSERT INTO customers
      (stripe_customer_id, name, email, phone, metadata)
     VALUES
      (:stripeCustomerId, :name, :email, :phone, :metadata)`,
    {
      stripeCustomerId: customer.stripeCustomerId,
      name: customer.name || null,
      email: customer.email || null,
      phone: customer.phone || null,
      metadata: JSON.stringify(customer.metadata || {})
    }
  );

  return getCustomerById(result.insertId);
}

async function upsertCustomerRecord(customer) {
  await query(
    `INSERT INTO customers
      (stripe_customer_id, name, email, phone, metadata)
     VALUES
      (:stripeCustomerId, :name, :email, :phone, :metadata)
     ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      email = VALUES(email),
      phone = VALUES(phone),
      metadata = VALUES(metadata),
      updated_at = CURRENT_TIMESTAMP`,
    {
      stripeCustomerId: customer.stripeCustomerId,
      name: customer.name || null,
      email: customer.email || null,
      phone: customer.phone || null,
      metadata: JSON.stringify(customer.metadata || {})
    }
  );

  return getCustomerByStripeId(customer.stripeCustomerId);
}

async function getCustomerById(id) {
  const rows = await query("SELECT * FROM customers WHERE id = :id", { id });
  return rows[0] || null;
}

async function getCustomerByStripeId(stripeCustomerId) {
  const rows = await query("SELECT * FROM customers WHERE stripe_customer_id = :stripeCustomerId", { stripeCustomerId });
  return rows[0] || null;
}

async function searchCustomers(filters) {
  const where = [];
  const params = {};

  if (filters.email) {
    where.push("email = :email");
    params.email = filters.email;
  }

  if (filters.phone) {
    where.push("phone = :phone");
    params.phone = filters.phone;
  }

  if (filters.q) {
    where.push("(name LIKE :q OR email LIKE :q OR phone LIKE :q)");
    params.q = `%${filters.q}%`;
  }

  const sql = `SELECT * FROM customers ${where.length ? `WHERE ${where.join(" AND ")}` : ""} ORDER BY created_at DESC LIMIT 50`;
  return query(sql, params);
}

module.exports = {
  createCustomerRecord,
  upsertCustomerRecord,
  getCustomerById,
  getCustomerByStripeId,
  searchCustomers
};
