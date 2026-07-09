const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { HttpError } = require("../errors/httpError");
const { constructStripeEvent, processStripeEvent } = require("../services/webhookService");

const router = express.Router();

router.post(
  "/stripe",
  express.raw({ type: "application/json" }),
  asyncRoute(async (req, res) => {
    const signature = req.get("stripe-signature");
    if (!signature) {
      throw new HttpError(400, "Missing Stripe signature header");
    }

    let event;
    try {
      event = constructStripeEvent(req.body, signature);
    } catch (error) {
      throw new HttpError(400, "Invalid Stripe webhook signature");
    }

    await processStripeEvent(event);
    res.json({ received: true });
  })
);

module.exports = { webhookRoutes: router };

