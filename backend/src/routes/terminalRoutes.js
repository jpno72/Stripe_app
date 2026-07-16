const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { createTerminalConnectionToken } = require("../services/paymentService");

const router = express.Router();

const createConnectionToken = asyncRoute(async (_req, res) => {
  const token = await createTerminalConnectionToken();
  res.json({ secret: token.secret });
});

router.post(
  "/connection_token",
  createConnectionToken
);

router.post(
  "/connection-token",
  createConnectionToken
);

module.exports = { terminalRoutes: router };

