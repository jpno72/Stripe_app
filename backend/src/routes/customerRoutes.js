const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { env } = require("../config/env");
const { createCustomer, readCustomer, findCustomers } = require("../services/customerService");
const { listCustomerPaymentMethods } = require("../services/paymentService");

const router = express.Router();

router.post(
  "/",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["name"]);
    const customer = await createCustomer({
      name: req.body.name,
      email: req.body.email,
      phone: req.body.phone,
      metadata: req.body.metadata || {}
    });
    res.status(201).json({ customer });
  })
);

router.get(
  "/",
  asyncRoute(async (req, res) => {
    const customers = await findCustomers({
      email: req.query.email,
      phone: req.query.phone,
      q: req.query.q
    });
    res.json({ customers });
  })
);

router.get(
  "/:id",
  asyncRoute(async (req, res) => {
    const customer = await readCustomer(parseId(req.params.id, "id"));
    res.json({ customer });
  })
);

router.get(
  "/:id/payment-methods",
  asyncRoute(async (req, res) => {
    const paymentMethods = await listCustomerPaymentMethods(parseId(req.params.id, "id"));
    res.json({ paymentMethods });
  })
);

module.exports = { customerRoutes: router };

function parseId(value, fieldName) {
  return env.useDatabasePersistence ? parsePositiveInteger(value, fieldName) : value;
}

