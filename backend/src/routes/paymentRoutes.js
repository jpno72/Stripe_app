const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { createCardPresentPaymentIntent } = require("../services/paymentService");

const router = express.Router();

router.post(
  "/card-present",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["amount"]);

    const result = await createCardPresentPaymentIntent({
      amount: parsePositiveInteger(req.body.amount, "amount"),
      currency: req.body.currency,
      customerId: req.body.customerId ? parsePositiveInteger(req.body.customerId, "customerId") : null,
      captureMethod: req.body.captureMethod,
      description: req.body.description,
      metadata: req.body.metadata || {}
    });

    res.status(201).json(result);
  })
);

module.exports = { paymentRoutes: router };
