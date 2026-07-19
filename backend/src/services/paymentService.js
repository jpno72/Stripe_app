const { stripe } = require("../config/stripe");
const { env } = require("../config/env");
const { getCustomerById } = require("../repositories/customerRepository");
const { createPaymentRecord } = require("../repositories/paymentRepository");
const { HttpError } = require("../errors/httpError");

async function createTerminalConnectionToken() {
  if (env.localStripeMockMode) {
    return { secret: "pst_mock_phase2_connection_token_secret" };
  }

  return stripe.terminal.connectionTokens.create();
}

async function createCardPresentPaymentIntent(input) {
  if (env.localStripeMockMode) {
    return createMockCardPresentPaymentIntent(input);
  }

  let customer = null;

  if (input.customerId) {
    if (env.useStripeOnlyPersistence) {
      try {
        customer = await stripe.customers.retrieve(input.customerId);
        if (customer.deleted) {
          throw new HttpError(404, "Customer not found");
        }
      } catch (error) {
        if (error.statusCode === 404 || error.status === 404) {
          throw new HttpError(404, "Customer not found");
        }
        throw error;
      }
    } else {
      customer = await getCustomerById(input.customerId);
      if (!customer) {
        throw new HttpError(404, "Customer not found");
      }
    }
  }

  const intent = await stripe.paymentIntents.create({
    amount: input.amount,
    currency: input.currency || env.defaultCurrency,
    customer: customer ? getStripeCustomerId(customer) : undefined,
    payment_method_types: ["card_present"],
    capture_method: input.captureMethod || "automatic",
    description: input.description,
    metadata: input.metadata || {}
  });

  if (env.useStripeOnlyPersistence) {
    return {
      payment: toPaymentSummary(intent, customer),
      paymentIntent: intent
    };
  }

  const payment = await createPaymentRecord({
    stripePaymentIntentId: intent.id,
    customerId: customer ? customer.id : null,
    stripeCustomerId: customer ? getStripeCustomerId(customer) : null,
    amount: intent.amount,
    currency: intent.currency,
    status: intent.status,
    captureMethod: intent.capture_method,
    description: intent.description,
    metadata: intent.metadata
  });

  return { payment, paymentIntent: intent };
}

async function createCardPaymentIntent(input) {
  if (env.localStripeMockMode) {
    return createMockCardPaymentIntent(input);
  }

  let customer = null;

  if (input.customerId) {
    customer = await resolveCustomer(input.customerId);
  }

  const intent = await stripe.paymentIntents.create({
    amount: input.amount,
    currency: input.currency || env.defaultCurrency,
    customer: customer ? getStripeCustomerId(customer) : undefined,
    automatic_payment_methods: {
      enabled: true
    },
    description: input.description,
    metadata: input.metadata || {}
  });

  if (env.useStripeOnlyPersistence) {
    return {
      payment: toPaymentSummary(intent, customer),
      paymentIntent: intent
    };
  }

  const payment = await createPaymentRecord({
    stripePaymentIntentId: intent.id,
    customerId: customer ? customer.id : null,
    stripeCustomerId: customer ? getStripeCustomerId(customer) : null,
    amount: intent.amount,
    currency: intent.currency,
    status: intent.status,
    captureMethod: intent.capture_method,
    description: intent.description,
    metadata: intent.metadata
  });

  return { payment, paymentIntent: intent };
}

async function createReusablePaymentMethodSetupIntent(input) {
  if (env.localStripeMockMode) {
    const createdAt = Date.now();
    const setupIntentId = `seti_mock_phase4_${createdAt}`;
    return {
      id: setupIntentId,
      client_secret: `${setupIntentId}_secret_mock_phase4`,
      customer: input.customerId,
      status: "requires_payment_method",
      usage: "off_session",
      payment_method_types: ["card"],
      metadata: input.metadata || {}
    };
  }

  const customer = await resolveCustomer(input.customerId);

  return stripe.setupIntents.create({
    customer: getStripeCustomerId(customer),
    payment_method_types: ["card"],
    usage: "off_session",
    metadata: input.metadata || {}
  });
}

async function setDefaultPaymentMethodFromSetupIntent(setupIntentId) {
  if (env.localStripeMockMode) {
    return {
      setupIntent: {
        id: setupIntentId,
        status: "succeeded",
        customer: "cus_mock_phase4_default_customer",
        payment_method: "pm_mock_phase4_default_payment_method"
      },
      customer: {
        id: "cus_mock_phase4_default_customer",
        invoice_settings: {
          default_payment_method: "pm_mock_phase4_default_payment_method"
        }
      },
      defaultPaymentMethod: "pm_mock_phase4_default_payment_method"
    };
  }

  const setupIntent = await stripe.setupIntents.retrieve(setupIntentId);
  if (setupIntent.status !== "succeeded") {
    throw new HttpError(400, "SetupIntent has not succeeded", {
      setupIntentId,
      status: setupIntent.status
    });
  }

  const customerId = typeof setupIntent.customer === "string"
    ? setupIntent.customer
    : setupIntent.customer && setupIntent.customer.id;
  const paymentMethodId = typeof setupIntent.payment_method === "string"
    ? setupIntent.payment_method
    : setupIntent.payment_method && setupIntent.payment_method.id;

  if (!customerId || !paymentMethodId) {
    throw new HttpError(400, "SetupIntent is missing customer or payment method");
  }

  const customer = await stripe.customers.update(customerId, {
    invoice_settings: {
      default_payment_method: paymentMethodId
    }
  });

  return {
    setupIntent,
    customer,
    defaultPaymentMethod: paymentMethodId
  };
}

async function listCustomerPaymentMethods(customerId) {
  if (env.localStripeMockMode) {
    return [];
  }

  const customer = await resolveCustomer(customerId);
  const stripeCustomerId = getStripeCustomerId(customer);
  const paymentMethods = await stripe.paymentMethods.list({
    customer: stripeCustomerId,
    type: "card",
    limit: 20
  });
  const stripeCustomer = await stripe.customers.retrieve(stripeCustomerId);
  const defaultPaymentMethod = stripeCustomer.invoice_settings
    ? stripeCustomer.invoice_settings.default_payment_method
    : null;
  const defaultPaymentMethodId = typeof defaultPaymentMethod === "string"
    ? defaultPaymentMethod
    : defaultPaymentMethod && defaultPaymentMethod.id;

  return paymentMethods.data.map((paymentMethod) => ({
    id: paymentMethod.id,
    type: paymentMethod.type,
    card: paymentMethod.card
      ? {
          brand: paymentMethod.card.brand,
          last4: paymentMethod.card.last4,
          exp_month: paymentMethod.card.exp_month,
          exp_year: paymentMethod.card.exp_year
        }
      : null,
    isDefault: paymentMethod.id === defaultPaymentMethodId
  }));
}

module.exports = {
  createTerminalConnectionToken,
  createCardPresentPaymentIntent,
  createCardPaymentIntent,
  createReusablePaymentMethodSetupIntent,
  setDefaultPaymentMethodFromSetupIntent,
  listCustomerPaymentMethods
};

function createMockCardPresentPaymentIntent(input) {
  const currency = input.currency || env.defaultCurrency;
  const createdAt = Date.now();
  const intentId = `pi_mock_phase2_${createdAt}`;

  return {
    payment: {
      id: 0,
      stripe_payment_intent_id: intentId,
      customer_id: input.customerId || null,
      stripe_customer_id: null,
      amount: input.amount,
      amount_received: 0,
      currency,
      status: "requires_payment_method",
      capture_method: input.captureMethod || "automatic",
      latest_charge_id: null,
      description: input.description || null,
      metadata: input.metadata || {}
    },
    paymentIntent: {
      id: intentId,
      client_secret: `${intentId}_secret_mock_phase2`,
      amount: input.amount,
      currency,
      status: "requires_payment_method",
      payment_method_types: ["card_present"],
      capture_method: input.captureMethod || "automatic",
      description: input.description || null,
      metadata: input.metadata || {}
    }
  };
}

function createMockCardPaymentIntent(input) {
  const currency = input.currency || env.defaultCurrency;
  const createdAt = Date.now();
  const intentId = `pi_mock_card_${createdAt}`;

  return {
    payment: {
      id: 0,
      stripe_payment_intent_id: intentId,
      customer_id: input.customerId || null,
      stripe_customer_id: null,
      amount: input.amount,
      amount_received: 0,
      currency,
      status: "requires_payment_method",
      capture_method: "automatic",
      latest_charge_id: null,
      description: input.description || null,
      metadata: input.metadata || {}
    },
    paymentIntent: {
      id: intentId,
      client_secret: `${intentId}_secret_mock_card`,
      amount: input.amount,
      currency,
      status: "requires_payment_method",
      payment_method_types: ["card"],
      capture_method: "automatic",
      description: input.description || null,
      metadata: input.metadata || {}
    }
  };
}

function getStripeCustomerId(customer) {
  return customer.stripe_customer_id || customer.id;
}

async function resolveCustomer(customerId) {
  if (env.useStripeOnlyPersistence) {
    try {
      const customer = await stripe.customers.retrieve(customerId);
      if (customer.deleted) {
        throw new HttpError(404, "Customer not found");
      }
      return customer;
    } catch (error) {
      if (error.statusCode === 404 || error.status === 404) {
        throw new HttpError(404, "Customer not found");
      }
      throw error;
    }
  }

  const customer = await getCustomerById(customerId);
  if (!customer) {
    throw new HttpError(404, "Customer not found");
  }
  return customer;
}

function toPaymentSummary(intent, customer) {
  return {
    id: intent.id,
    stripe_payment_intent_id: intent.id,
    customer_id: customer ? getStripeCustomerId(customer) : null,
    stripe_customer_id: customer ? getStripeCustomerId(customer) : null,
    amount: intent.amount,
    amount_received: intent.amount_received || 0,
    currency: intent.currency,
    status: intent.status,
    capture_method: intent.capture_method,
    latest_charge_id: typeof intent.latest_charge === "string" ? intent.latest_charge : null,
    description: intent.description || null,
    metadata: intent.metadata || {}
  };
}
