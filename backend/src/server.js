const { env, requireEnv } = require("./config/env");
const { logger } = require("./config/logger");

requireEnv();

const { createApp } = require("./app");

const app = createApp();

const server = app.listen(env.port, () => {
  logger.info({ port: env.port, nodeEnv: env.nodeEnv }, "stripe pos backend listening");
});

function shutdown(signal) {
  logger.info({ signal }, "shutting down");
  server.close(() => process.exit(0));
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
