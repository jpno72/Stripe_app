const express = require("express");
const { asyncRoute } = require("../utils/asyncRoute");
const { requireFields, parsePositiveInteger } = require("../utils/validation");
const { createCustomer, readCustomer, findCustomers } = require("../services/customerService");

const router = express.Router();

router.post(
  "/",
  asyncRoute(async (req, res) => {
    requireFields(req.body, ["name"]);
    const customer = await createCustomer({
      name: req.body.name,
      email: req.body.email,
      phone: req.body.phone,
      metadata: req.body.metadata || {}
    });
    res.status(201).json({ customer });
  })
);

router.get(
  "/",
  asyncRoute(async (req, res) => {
    const customers = await findCustomers({
      email: req.query.email,
      phone: req.query.phone,
      q: req.query.q
    });
    res.json({ customers });
  })
);

router.get(
  "/:id",
  asyncRoute(async (req, res) => {
    const customer = await readCustomer(parsePositiveInteger(req.params.id, "id"));
    res.json({ customer });
  })
);

module.exports = { customerRoutes: router };

