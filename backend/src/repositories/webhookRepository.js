const { query } = require("../db/pool");

async function createWebhookEvent(event) {
  const existingEvent = await getWebhookEventByStripeId(event.id);
  if (existingEvent) {
    return {
      id: existingEvent.id,
      shouldProcess: !existingEvent.processed_at
    };
  }

  const result = await query(
    `INSERT INTO webhook_events
      (stripe_event_id, type, api_version, livemode, payload)
     VALUES
      (:stripeEventId, :type, :apiVersion, :livemode, :payload)
     ON DUPLICATE KEY UPDATE
      updated_at = CURRENT_TIMESTAMP`,
    {
      stripeEventId: event.id,
      type: event.type,
      apiVersion: event.api_version || null,
      livemode: event.livemode ? 1 : 0,
      payload: JSON.stringify(event)
    }
  );

  return {
    id: result.insertId,
    shouldProcess: true
  };
}

async function markWebhookEventProcessed(stripeEventId) {
  await query(
    `UPDATE webhook_events
     SET processed_at = CURRENT_TIMESTAMP,
       updated_at = CURRENT_TIMESTAMP
     WHERE stripe_event_id = :stripeEventId`,
    { stripeEventId }
  );
}

async function getWebhookEventByStripeId(stripeEventId) {
  const rows = await query(
    "SELECT id, processed_at FROM webhook_events WHERE stripe_event_id = :stripeEventId",
    { stripeEventId }
  );
  return rows[0] || null;
}

module.exports = { createWebhookEvent, markWebhookEventProcessed };
