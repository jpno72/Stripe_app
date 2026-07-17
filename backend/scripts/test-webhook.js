const { stripe } = require("../src/config/stripe");
const { env, requireEnv } = require("../src/config/env");
const {
  constructStripeEvent,
  processStripeEvent
} = require("../src/services/webhookService");

requireEnv();

async function main() {
  const payload = JSON.stringify({
    id: `evt_local_${Date.now()}`,
    object: "event",
    api_version: env.stripeApiVersion,
    created: Math.floor(Date.now() / 1000),
    livemode: false,
    pending_webhooks: 1,
    request: null,
    type: "customer.created",
    data: {
      object: {
        id: `cus_local_${Date.now()}`,
        object: "customer",
        name: "Local Webhook Test",
        email: "webhook-test@example.com",
        phone: null,
        metadata: {
          source: "local_webhook_test"
        }
      }
    }
  });

  const signature = stripe.webhooks.generateTestHeaderString({
    payload,
    secret: env.stripeWebhookSecret
  });

  const event = constructStripeEvent(Buffer.from(payload), signature);
  await processStripeEvent(event);

  console.log(`Verified and processed ${event.type} (${event.id})`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
