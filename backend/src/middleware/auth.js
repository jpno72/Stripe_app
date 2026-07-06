const { env } = require("../config/env");
const { HttpError } = require("../errors/httpError");

function requireApiToken(req, _res, next) {
  const header = req.get("authorization") || "";
  const token = header.startsWith("Bearer ") ? header.slice("Bearer ".length) : "";

  if (!token || token !== env.apiToken) {
    return next(new HttpError(401, "Missing or invalid API token"));
  }

  return next();
}

module.exports = { requireApiToken };

