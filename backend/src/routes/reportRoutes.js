const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const {
  listActiveCustomers,
  listActiveSubscriptions,
  listRecentTransactions
} = require("../services/reportService");

const router = express.Router();

router.get(
  "/active-customers",
  asyncRoute(async (_req, res) => {
    res.json({ customers: await listActiveCustomers() });
  })
);

router.get(
  "/active-subscriptions",
  asyncRoute(async (_req, res) => {
    res.json({ subscriptions: await listActiveSubscriptions() });
  })
);

router.get(
  "/recent-transactions",
  asyncRoute(async (_req, res) => {
    res.json({ transactions: await listRecentTransactions() });
  })
);

module.exports = { reportRoutes: router };
