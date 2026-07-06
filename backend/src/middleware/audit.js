const { createAuditLog } = require("../repositories/auditRepository");
const { logger } = require("../config/logger");

function auditRequests(req, res, next) {
  if (!req.originalUrl.startsWith("/api/")) {
    return next();
  }

  res.on("finish", async () => {
    try {
      await createAuditLog({
        actorType: "api_token",
        action: `${req.method} ${req.originalUrl.split("?")[0]}`,
        resourceType: routeResource(req.originalUrl),
        resourceId: req.params ? req.params.id || null : null,
        ipAddress: req.ip,
        userAgent: req.get("user-agent"),
        statusCode: res.statusCode,
        metadata: {
          requestId: req.id,
          query: req.query
        }
      });
    } catch (error) {
      logger.warn({ err: error }, "failed to write audit log");
    }
  });

  return next();
}

function routeResource(path) {
  if (path.includes("/customers")) return "customer";
  if (path.includes("/payments") || path.includes("/payment-intents")) return "payment";
  if (path.includes("/subscriptions")) return "subscription";
  if (path.includes("/terminal")) return "terminal";
  return "api";
}

module.exports = { auditRequests };
