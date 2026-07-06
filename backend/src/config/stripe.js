const Stripe = require("stripe");
const { env } = require("./env");

const stripe = new Stripe(env.stripeSecretKey, {
  apiVersion: env.stripeApiVersion,
  appInfo: {
    name: env.appName,
    version: "0.1.0"
  }
});

module.exports = { stripe };
