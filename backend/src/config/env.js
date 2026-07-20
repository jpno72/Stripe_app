const path = require("path");

require("dotenv").config({
  path: path.resolve(__dirname, "../../.env"),
  override: true
});

const baseRequired = [
  "API_TOKEN",
  "STRIPE_SECRET_KEY",
  "STRIPE_WEBHOOK_SECRET"
];

const databaseRequired = [
  "DB_HOST",
  "DB_USER",
  "DB_PASSWORD",
  "DB_NAME"
];

function requireEnv() {
  const required = env.useDatabasePersistence
    ? baseRequired.concat(databaseRequired)
    : baseRequired;
  const missing = required.filter((key) => !process.env[key]);
  if (missing.length > 0) {
    throw new Error(`Missing required environment variables: ${missing.join(", ")}`);
  }
}

function parseMembershipPlans() {
  if (!process.env.MEMBERSHIP_PLANS_JSON) {
    return [];
  }

  try {
    const plans = JSON.parse(process.env.MEMBERSHIP_PLANS_JSON);
    if (!Array.isArray(plans)) {
      throw new Error("MEMBERSHIP_PLANS_JSON must be an array");
    }
    return plans.map((plan) => ({
      id: String(plan.id),
      name: String(plan.name),
      stripePriceId: String(plan.stripePriceId),
      description: plan.description ? String(plan.description) : null
    }));
  } catch (error) {
    throw new Error(`Invalid MEMBERSHIP_PLANS_JSON: ${error.message}`);
  }
}

const env = {
  nodeEnv: process.env.NODE_ENV || "development",
  port: Number(process.env.PORT || 3000),
  appName: process.env.APP_NAME || "Internal Stripe POS Backend",
  appBasePath: (process.env.APP_BASE_PATH || "").trim().replace(/\/+$/, ""),
  defaultCurrency: (process.env.DEFAULT_CURRENCY || "usd").toLowerCase(),
  localStripeMockMode: process.env.LOCAL_STRIPE_MOCK_MODE === "true",
  persistenceMode: (process.env.PERSISTENCE_MODE || "stripe").trim().toLowerCase(),
  apiToken: process.env.API_TOKEN,
  stripeSecretKey: process.env.STRIPE_SECRET_KEY,
  stripeWebhookSecret: process.env.STRIPE_WEBHOOK_SECRET,
  stripeApiVersion: process.env.STRIPE_API_VERSION || "2026-02-25.clover",
  db: {
    host: process.env.DB_HOST || "127.0.0.1",
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    connectionLimit: Number(process.env.DB_CONNECTION_LIMIT || 10)
  },
  membershipPlans: parseMembershipPlans()
};

env.useDatabasePersistence = env.persistenceMode === "database";
env.useStripeOnlyPersistence = !env.useDatabasePersistence;

module.exports = { env, requireEnv };
