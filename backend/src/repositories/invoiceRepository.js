const { query } = require("../db/pool");

async function upsertInvoiceRecord(invoice) {
  await query(
    `INSERT INTO invoices
      (stripe_invoice_id, stripe_subscription_id, stripe_customer_id, status, amount_due, amount_paid, currency, hosted_invoice_url, invoice_pdf)
     VALUES
      (:stripeInvoiceId, :stripeSubscriptionId, :stripeCustomerId, :status, :amountDue, :amountPaid, :currency, :hostedInvoiceUrl, :invoicePdf)
     ON DUPLICATE KEY UPDATE
      stripe_subscription_id = VALUES(stripe_subscription_id),
      stripe_customer_id = VALUES(stripe_customer_id),
      status = VALUES(status),
      amount_due = VALUES(amount_due),
      amount_paid = VALUES(amount_paid),
      currency = VALUES(currency),
      hosted_invoice_url = VALUES(hosted_invoice_url),
      invoice_pdf = VALUES(invoice_pdf),
      updated_at = CURRENT_TIMESTAMP`,
    {
      stripeInvoiceId: invoice.stripeInvoiceId,
      stripeSubscriptionId: invoice.stripeSubscriptionId || null,
      stripeCustomerId: invoice.stripeCustomerId || null,
      status: invoice.status || null,
      amountDue: invoice.amountDue || 0,
      amountPaid: invoice.amountPaid || 0,
      currency: invoice.currency || null,
      hostedInvoiceUrl: invoice.hostedInvoiceUrl || null,
      invoicePdf: invoice.invoicePdf || null
    }
  );
}

module.exports = { upsertInvoiceRecord };
