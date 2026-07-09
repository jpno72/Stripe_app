const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { createTerminalConnectionToken } = require("../services/paymentService");

const router = express.Router();

router.post(
  "/connection-token",
  asyncRoute(async (_req, res) => {
    const token = await createTerminalConnectionToken();
    res.json({ secret: token.secret });
  })
);

module.exports = { terminalRoutes: router };

