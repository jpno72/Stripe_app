require("dotenv").config();

const Stripe = require("stripe");

const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

function makeId(name, priceId) {
  const base = String(name || "membership")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

  return `${base}-${priceId.slice(-6).toLowerCase()}`;
}

async function main() {
  const plans = [];

  for await (const price of stripe.prices.list({
    active: true,
    type: "recurring",
    limit: 100,
    expand: ["data.product"],
  })) {
    const product = price.product;

    if (!product || typeof product === "string" || product.deleted) {
      continue;
    }

    plans.push({
      id: makeId(product.name, price.id),
      name: product.name,
      stripePriceId: price.id,
      description:
        product.description ||
        `${price.recurring.interval_count || 1} ${price.recurring.interval} recurring membership`,
    });
  }

  plans.sort((a, b) => a.name.localeCompare(b.name));

  console.log("\nReadable version:\n");
  console.log(JSON.stringify(plans, null, 2));

  console.log("\nOne-line cPanel value:\n");
  console.log(JSON.stringify(plans));
}

main().catch((error) => {
  console.error("Unable to retrieve Stripe plans:", error.message);
  process.exit(1);
});