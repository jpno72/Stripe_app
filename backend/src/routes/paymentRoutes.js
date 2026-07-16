const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { env } = require("../config/env");
const { createCardPresentPaymentIntent } = require("../services/paymentService");

const router = express.Router();

router.post(
  "/card-present",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["amount"]);

    const result = await createCardPresentPaymentIntent({
      amount: parsePositiveInteger(req.body.amount, "amount"),
      currency: req.body.currency,
      customerId: req.body.customerId ? parseCustomerId(req.body.customerId) : null,
      captureMethod: req.body.captureMethod,
      description: req.body.description,
      metadata: req.body.metadata || {}
    });

    res.status(201).json(result);
  })
);

module.exports = { paymentRoutes: router };

function parseCustomerId(value) {
  return env.useDatabasePersistence ? parsePositiveInteger(value, "customerId") : String(value);
}
