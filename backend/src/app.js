const express = require("express");
const helmet = require("helmet");
const pinoHttp = require("pino-http");
const { env } = require("./config/env");
const { logger } = require("./config/logger");
const { requireApiToken } = require("./middleware/auth");
const { auditRequests } = require("./middleware/audit");
const { notFound, errorHandler } = require("./middleware/errorHandler");
const { healthRoutes } = require("./routes/healthRoutes");
const { terminalRoutes } = require("./routes/terminalRoutes");
const { paymentRoutes } = require("./routes/paymentRoutes");
const { setupIntentRoutes } = require("./routes/setupIntentRoutes");
const { customerRoutes } = require("./routes/customerRoutes");
const { subscriptionRoutes } = require("./routes/subscriptionRoutes");
const { reportRoutes } = require("./routes/reportRoutes");
const { webhookRoutes } = require("./routes/webhookRoutes");

function createApp() {
  const app = express();
  const routePrefixes = env.appBasePath ? ["", env.appBasePath] : [""];

  app.disable("x-powered-by");
  app.use(helmet());
  app.use(pinoHttp({ logger }));

  for (const prefix of routePrefixes) {
    app.use(prefix, healthRoutes);
    app.use(`${prefix}/webhooks`, webhookRoutes);
  }

  app.use(express.json({ limit: "1mb" }));
  app.use(requireApiToken);
  app.use(auditRequests);

  for (const prefix of routePrefixes) {
    app.use(`${prefix}/api/v1/terminal`, terminalRoutes);
    app.use(`${prefix}/api/v1/payment-intents`, paymentRoutes);
    app.use(`${prefix}/api/v1/setup-intents`, setupIntentRoutes);
    app.use(`${prefix}/api/v1/customers`, customerRoutes);
    app.use(`${prefix}/api/v1/subscriptions`, subscriptionRoutes);
    app.use(`${prefix}/api/v1/reports`, reportRoutes);
  }

  app.use(notFound);
  app.use(errorHandler);

  return app;
}

module.exports = { createApp };
