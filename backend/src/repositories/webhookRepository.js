const { query } = require("../db/pool");

async function createWebhookEvent(event) {
  const result = await query(
    `INSERT INTO webhook_events
      (stripe_event_id, type, api_version, livemode, payload, processed_at)
     VALUES
      (:stripeEventId, :type, :apiVersion, :livemode, :payload, CURRENT_TIMESTAMP)
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

  return result.insertId;
}

module.exports = { createWebhookEvent };
