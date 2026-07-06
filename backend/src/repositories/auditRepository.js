const { query } = require("../db/pool");

async function createAuditLog(entry) {
  const result = await query(
    `INSERT INTO audit_logs
      (actor_type, action, resource_type, resource_id, ip_address, user_agent, status_code, metadata)
     VALUES
      (:actorType, :action, :resourceType, :resourceId, :ipAddress, :userAgent, :statusCode, :metadata)`,
    {
      actorType: entry.actorType,
      action: entry.action,
      resourceType: entry.resourceType,
      resourceId: entry.resourceId,
      ipAddress: entry.ipAddress,
      userAgent: entry.userAgent,
      statusCode: entry.statusCode,
      metadata: JSON.stringify(entry.metadata || {})
    }
  );

  return result.insertId;
}

module.exports = { createAuditLog };
