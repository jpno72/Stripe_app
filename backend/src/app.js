const express = require("express");
const helmet = require("helmet");
const pinoHttp = require("pino-http");
const { logger } = require("./config/logger");
const { requireApiToken } = require("./middleware/auth");
const { auditRequests } = require("./middleware/audit");
const { notFound, errorHandler } = require("./middleware/errorHandler");
const { healthRoutes } = require("./routes/healthRoutes");
const { terminalRoutes } = require("./routes/terminalRoutes");
const { paymentRoutes } = require("./routes/paymentRoutes");
const { customerRoutes } = require("./routes/customerRoutes");
const { subscriptionRoutes } = require("./routes/subscriptionRoutes");
const { webhookRoutes } = require("./routes/webhookRoutes");

function createApp() {
  const app = express();

  app.disable("x-powered-by");
  app.use(helmet());
  app.use(pinoHttp({ logger }));

  app.use(healthRoutes);
  app.use("/webhooks", webhookRoutes);

  app.use(express.json({ limit: "1mb" }));
  app.use(requireApiToken);
  app.use(auditRequests);

  app.use("/api/v1/terminal", terminalRoutes);
  app.use("/api/v1/payment-intents", paymentRoutes);
  app.use("/api/v1/customers", customerRoutes);
  app.use("/api/v1/subscriptions", subscriptionRoutes);

  app.use(notFound);
  app.use(errorHandler);

  return app;
}

module.exports = { createApp };
