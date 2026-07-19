const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { env } = require("../config/env");
const {
  listMembershipPlans,
  createSubscription,
  readSubscription,
  findSubscriptions,
  cancelSubscription
} = require("../services/subscriptionService");

const router = express.Router();

router.get(
  "/plans",
  asyncRoute(async (_req, res) => {
    res.json({ plans: await listMembershipPlans() });
  })
);

router.post(
  "/",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["planId"]);
    const result = await createSubscription({
      customerId: req.body.customerId ? parseId(req.body.customerId, "customerId") : null,
      customer: req.body.customer || null,
      planId: req.body.planId,
      priceId: req.body.priceId,
      quantity: req.body.quantity ? parsePositiveInteger(req.body.quantity, "quantity") : 1,
      paymentBehavior: req.body.paymentBehavior,
      trialPeriodDays: req.body.trialPeriodDays
        ? parsePositiveInteger(req.body.trialPeriodDays, "trialPeriodDays")
        : undefined,
      metadata: req.body.metadata || {}
    });
    res.status(201).json(result);
  })
);

router.get(
  "/",
  asyncRoute(async (req, res) => {
    const subscriptions = await findSubscriptions({
      customerId: req.query.customerId ? parseId(req.query.customerId, "customerId") : null,
      status: req.query.status
    });
    res.json({ subscriptions });
  })
);

router.get(
  "/:id",
  asyncRoute(async (req, res) => {
    const subscription = await readSubscription(parseId(req.params.id, "id"));
    res.json({ subscription });
  })
);

router.post(
  "/:id/cancel",
  asyncRoute(async (req, res) => {
    const result = await cancelSubscription(
      parseId(req.params.id, "id"),
      req.body.cancelAtPeriodEnd === true
    );
    res.json(result);
  })
);

module.exports = { subscriptionRoutes: router };

function parseId(value, fieldName) {
  return env.useDatabasePersistence ? parsePositiveInteger(value, fieldName) : String(value);
}
