const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { env } = require("../config/env");
const {
  createReusablePaymentMethodSetupIntent,
  setDefaultPaymentMethodFromSetupIntent
} = require("../services/paymentService");

const router = express.Router();

router.post(
  "/",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["customerId"]);

    const setupIntent = await createReusablePaymentMethodSetupIntent({
      customerId: parseCustomerId(req.body.customerId),
      metadata: req.body.metadata || {}
    });

    res.status(201).json({
      setupIntent: {
        id: setupIntent.id,
        client_secret: setupIntent.client_secret,
        customer: setupIntent.customer,
        status: setupIntent.status,
        usage: setupIntent.usage,
        payment_method_types: setupIntent.payment_method_types,
        metadata: setupIntent.metadata || {}
      }
    });
  })
);

router.post(
  "/:id/default-payment-method",
  asyncRoute(async (req, res) => {
    const result = await setDefaultPaymentMethodFromSetupIntent(String(req.params.id));

    res.json({
      setupIntent: {
        id: result.setupIntent.id,
        status: result.setupIntent.status,
        customer: result.setupIntent.customer
      },
      customer: {
        id: result.customer.id,
        invoice_settings: result.customer.invoice_settings
      },
      defaultPaymentMethod: result.defaultPaymentMethod
    });
  })
);

module.exports = { setupIntentRoutes: router };

function parseCustomerId(value) {
  return env.useDatabasePersistence ? parsePositiveInteger(value, "customerId") : String(value);
}
