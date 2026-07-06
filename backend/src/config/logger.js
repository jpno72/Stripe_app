const pino = require("pino");

const logger = pino({
  level: process.env.LOG_LEVEL || "info",
  redact: {
    paths: [
      "req.headers.authorization",
      "req.headers[\"stripe-signature\"]",
      "authorization",
      "stripe-signature",
      "apiToken",
      "stripeSecretKey",
      "stripeWebhookSecret"
    ],
    censor: "[redacted]"
  }
});

module.exports = { logger };
