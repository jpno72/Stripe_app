const { logger } = require("../config/logger");

function notFound(req, _res, next) {
  const error = new Error(`Route not found: ${req.method} ${req.originalUrl}`);
  error.statusCode = 404;
  next(error);
}

function errorHandler(error, req, res, _next) {
  const statusCode = error.statusCode || error.status || 500;
  const response = {
    error: {
      message: statusCode >= 500 ? "Internal server error" : error.message,
      statusCode
    }
  };

  if (error.details && statusCode < 500) {
    response.error.details = error.details;
  }

  logger.error(
    {
      err: error,
      requestId: req.id,
      method: req.method,
      path: req.originalUrl,
      statusCode
    },
    "request failed"
  );

  res.status(statusCode).json(response);
}

module.exports = { notFound, errorHandler };

