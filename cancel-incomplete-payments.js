"use strict";

require("dotenv").config();
const Stripe = require("stripe");

const secretKey = process.env.STRIPE_SECRET_KEY;

if (!secretKey) {
  console.error("Missing STRIPE_SECRET_KEY.");
  process.exit(1);
}

const stripe = new Stripe(secretKey);

const HOURS_BACK = 48;

const CANCELABLE_STATUSES = new Set([
  "requires_payment_method",
  "requires_confirmation",
  "requires_action",
  "requires_capture",
]);

// Processing can sometimes be canceled, but only for certain payment methods.
// Keep it excluded by default to avoid interfering with an active payment.
const INCLUDE_PROCESSING = false;

function formatAmount(amount, currency) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: String(currency).toUpperCase(),
  }).format(amount / 100);
}

async function getIncompletePaymentIntents() {
  const createdAfter = Math.floor(
    (Date.now() - HOURS_BACK * 60 * 60 * 1000) / 1000
  );

  const results = [];

  // Stripe auto-pagination retrieves more than the first 100 results if needed.
  for await (const paymentIntent of stripe.paymentIntents.list({
    created: { gte: createdAfter },
    limit: 100,
  })) {
    const isCancelable =
      CANCELABLE_STATUSES.has(paymentIntent.status) ||
      (INCLUDE_PROCESSING && paymentIntent.status === "processing");

    if (isCancelable) {
      results.push(paymentIntent);
    }
  }

  return results;
}

async function main() {
  const shouldCancel = process.argv.includes("--cancel");
  const paymentIntents = await getIncompletePaymentIntents();

  if (paymentIntents.length === 0) {
    console.log(
      `No cancelable incomplete PaymentIntents found from the last ${HOURS_BACK} hours.`
    );
    return;
  }

  console.log(
    `Found ${paymentIntents.length} cancelable incomplete PaymentIntent(s):\n`
  );

  for (const paymentIntent of paymentIntents) {
    console.log({
      id: paymentIntent.id,
      created: new Date(paymentIntent.created * 1000).toLocaleString(),
      amount: formatAmount(
        paymentIntent.amount,
        paymentIntent.currency
      ),
      status: paymentIntent.status,
      customer: paymentIntent.customer || null,
      email:
        paymentIntent.receipt_email ||
        paymentIntent.shipping?.name ||
        null,
      description: paymentIntent.description || null,
    });
  }

  if (!shouldCancel) {
    console.log(
      "\nPreview only. Nothing was canceled."
    );
    console.log(
      "Review the list, then run again with: node cancel-incomplete-payments.js --cancel"
    );
    return;
  }

  console.log("\nCanceling PaymentIntents...\n");

  let canceledCount = 0;
  let failedCount = 0;

  for (const paymentIntent of paymentIntents) {
    try {
      const canceled = await stripe.paymentIntents.cancel(
        paymentIntent.id,
        {
          cancellation_reason: "abandoned",
        }
      );

      canceledCount += 1;
      console.log(`Canceled ${canceled.id}`);
    } catch (error) {
      failedCount += 1;
      console.error(
        `Could not cancel ${paymentIntent.id}: ${error.message}`
      );
    }
  }

  console.log("\nFinished.");
  console.log(`Canceled: ${canceledCount}`);
  console.log(`Failed/skipped: ${failedCount}`);
}

main().catch((error) => {
  console.error("Fatal error:", error.message);
  process.exit(1);
});