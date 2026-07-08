# Internal Stripe POS Backend API

Phase 1 backend for an internal POS and subscription system. This API is designed for a future Android/Kotlin app using the Stripe Terminal SDK with a Stripe Reader M2, and for a future web dashboard.

This phase does not include Android files and does not claim real Stripe Reader M2 testing has been completed.

## Stack

- Node.js 18+
- Express
- MySQL or MariaDB
- Stripe Node SDK
- Bearer-token protection for internal API routes
- Stripe webhook signature verification
- Pino structured logging

## Folder Structure

```text
backend/
  .env.example
  package.json
  sql/schema.sql
  src/
    app.js
    server.js
    config/
    db/
    errors/
    middleware/
    repositories/
    routes/
    services/
    utils/
```

## Setup

1. Install dependencies:

```bash
cd backend
npm install
```

1. Create the database:

```sql
CREATE DATABASE stripe_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stripe_pos_user'@'localhost' IDENTIFIED BY 'replace_me';
GRANT ALL PRIVILEGES ON stripe_pos.* TO 'stripe_pos_user'@'localhost';
FLUSH PRIVILEGES;
```

1. Load the schema:

```bash
mysql -u stripe_pos_user -p stripe_pos < sql/schema.sql
```

1. Configure environment variables:

```bash
cp .env.example .env
```

Edit `.env` and set:

- `API_TOKEN`
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `APP_NAME`
- `DEFAULT_CURRENCY`
- `LOCAL_STRIPE_MOCK_MODE`
- `MEMBERSHIP_PLANS_JSON`
- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASSWORD`
- `DB_NAME`

For Phase 2 local smoke testing, `LOCAL_STRIPE_MOCK_MODE=true` lets the connection-token and card-present PaymentIntent endpoints return mock responses without calling Stripe or writing payment rows to MySQL. Use `LOCAL_STRIPE_MOCK_MODE=false` only when you have real Stripe test keys, a database, and are ready for Stripe SDK testing.

Windows PowerShell setup from the repository root:

```powershell
Set-Location ".\backend"
Copy-Item ".\.env.example" ".\.env"
notepad ".\.env"
```

For quick Phase 2 local testing, keep these values aligned between backend `.env` and Android `local.properties`:

```text
API_TOKEN=replace_with_a_long_random_internal_token
LOCAL_STRIPE_MOCK_MODE=true
```

For Phase 3 subscription setup, configure membership plans with Stripe recurring Price IDs:

```text
MEMBERSHIP_PLANS_JSON=[{"id":"basic","name":"Basic Membership","stripePriceId":"price_123","description":"Basic monthly membership"}]
```

1. Start the API:

```bash
npm run dev
```

Check JavaScript syntax:

```bash
npm run check:all
```

For production:

```bash
npm start
```

Health check:

```bash
curl http://localhost:3000/health
```

## Authentication

All `/api/v1/*` routes require:

```text
Authorization: Bearer YOUR_API_TOKEN
```

The Stripe webhook route is not protected by this token because it uses Stripe signature verification.

## API Endpoints

### Terminal Connection Token

Creates a Stripe Terminal connection token for the Android Terminal SDK.

```http
POST /api/v1/terminal/connection-token
```

Response:

```json
{
  "secret": "pst_test_..."
}
```

### Card-Present PaymentIntent

Creates a Stripe PaymentIntent for Terminal collection.

```http
POST /api/v1/payment-intents/card-present
```

Important Stripe detail: this endpoint uses:

```js
payment_method_types: ["card_present"]
```

Body:

```json
{
  "amount": 2500,
  "currency": "usd",
  "customerId": 1,
  "description": "In-person class purchase",
  "metadata": {
    "source": "android_pos"
  }
}
```

### Customers

```http
POST /api/v1/customers
GET /api/v1/customers?email=test@example.com
GET /api/v1/customers?q=Jane
GET /api/v1/customers/:id
```

### Phase 3 Subscriptions

Subscription management starts in Phase 3. Keep it simple: select a configured membership plan, select or create a customer, and create one Stripe subscription.

Before testing subscriptions in real Stripe mode:

- Create membership Products in Stripe Dashboard.
- Add recurring Prices for those Products.
- Copy the recurring `price_...` IDs into `MEMBERSHIP_PLANS_JSON`.
- Make sure the Stripe Customer has a reusable payment method for recurring billing.
- Do not assume a card-present Stripe Terminal payment saved a recurring payment method. Saving a reusable payment method is a separate setup flow and is manual-test/device-dependent for future work.

```http
GET /api/v1/subscriptions/plans
POST /api/v1/subscriptions
GET /api/v1/subscriptions
GET /api/v1/subscriptions/:id
POST /api/v1/subscriptions/:id/cancel
```

Subscription creation requires a configured membership `planId`. Each configured plan maps to a Stripe recurring Price ID on the backend.

Note: Stripe Terminal card-present payments do not automatically create a reusable online payment method for recurring subscriptions. This endpoint assumes the Stripe Customer already has a suitable default payment method, uses a trial/incomplete flow, or will be completed by a future billing setup flow.

### Stripe Webhook

```http
POST /webhooks/stripe
```

The webhook verifies the `Stripe-Signature` header with `STRIPE_WEBHOOK_SECRET` before processing.

Handled event families:

- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `payment_intent.processing`
- `customer.created`
- `customer.updated`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.created`
- `invoice.finalized`
- `invoice.paid`
- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `invoice.voided`
- `invoice.marked_uncollectible`

All webhook payloads are stored in `webhook_events`.

## Curl Examples

Set local variables:

```bash
export API_BASE="http://localhost:3000"
export API_TOKEN="replace_with_your_token"
```

Health:

```bash
curl "$API_BASE/health"
```

Create customer:

```bash
curl -X POST "$API_BASE/api/v1/customers" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Student",
    "email": "jane@example.com",
    "phone": "+15555550100",
    "metadata": {
      "program": "martial_arts"
    }
  }'
```

Search customers:

```bash
curl "$API_BASE/api/v1/customers?q=Jane" \
  -H "Authorization: Bearer $API_TOKEN"
```

Read customer:

```bash
curl "$API_BASE/api/v1/customers/1" \
  -H "Authorization: Bearer $API_TOKEN"
```

Create Terminal connection token:

```bash
curl -X POST "$API_BASE/api/v1/terminal/connection-token" \
  -H "Authorization: Bearer $API_TOKEN"
```

Create card-present PaymentIntent:

```bash
curl -X POST "$API_BASE/api/v1/payment-intents/card-present" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 2500,
    "currency": "usd",
    "customerId": 1,
    "description": "In-person class purchase",
    "metadata": {
      "checkout_device": "future_android_app"
    }
  }'
```

Phase 3 subscription example, not part of current Phase 2 testing:

```bash
curl -X POST "$API_BASE/api/v1/subscriptions" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "customerId": 1,
    "planId": "basic",
    "quantity": 1,
    "metadata": {
      "membership": "monthly_membership"
    }
  }'
```

Phase 3 list subscriptions example:

```bash
curl "$API_BASE/api/v1/subscriptions?customerId=1" \
  -H "Authorization: Bearer $API_TOKEN"
```

Phase 3 cancel subscription at period end example:

```bash
curl -X POST "$API_BASE/api/v1/subscriptions/1/cancel" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "cancelAtPeriodEnd": true
  }'
```

Phase 3 cancel subscription immediately example:

```bash
curl -X POST "$API_BASE/api/v1/subscriptions/1/cancel" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "cancelAtPeriodEnd": false
  }'
```

Webhook local testing with the Stripe CLI:

```bash
stripe listen --forward-to localhost:3000/webhooks/stripe
```

Copy the webhook secret printed by the Stripe CLI into `STRIPE_WEBHOOK_SECRET`, then trigger events:

```bash
stripe trigger payment_intent.succeeded
stripe trigger customer.created
stripe trigger customer.subscription.created
```

## Database Tables

- `customers`
- `payments`
- `subscriptions`
- `invoices`
- `webhook_events`
- `audit_logs`

The schema is in [sql/schema.sql](sql/schema.sql).

## cPanel / Passenger Deployment Notes

These notes are practical starting points because cPanel setups vary by host.

1. In cPanel, create a Node.js app and point the application root to the `backend` folder.
1. Use Node.js 18 or newer.
1. Set the application startup file to `src/server.js`.
1. Add the environment variables from `.env.example` through cPanel's Node.js app environment variable UI. Do not upload real secrets into Git.
1. Run `npm install` from cPanel Terminal or the Node.js app screen.
1. Import `sql/schema.sql` into the MySQL/MariaDB database through phpMyAdmin or cPanel Terminal.
1. Make sure the public URL routes `/webhooks/stripe` to this Node app.
1. In Stripe Dashboard, create a webhook endpoint pointing to `https://your-domain.com/webhooks/stripe` and copy its signing secret into `STRIPE_WEBHOOK_SECRET`.
1. If the app is mounted under a subpath, configure cPanel routing or a reverse proxy so `/api/v1/*` and `/webhooks/stripe` reach this Express app unchanged.

## Manual Test Checklist

- Start the server with valid `.env` values.
- Confirm `GET /health` returns `{ "ok": true }`.
- For Phase 2 smoke testing, confirm `LOCAL_STRIPE_MOCK_MODE=true` is set.
- Confirm `/api/v1/*` requests without `Authorization` return `401`.
- Create a customer and confirm a row exists in `customers`.
- Search and read that customer.
- Create a Terminal connection token with a Stripe test secret key.
- Create a card-present PaymentIntent and confirm `payment_method_types` is `["card_present"]` in Stripe Dashboard.
- Confirm a row exists in `payments`.
- Leave subscription create/read/cancel testing for Phase 3.
- Use Stripe CLI to forward webhooks and confirm verified events are stored in `webhook_events`.
- Confirm webhook calls with an invalid signature are rejected.
- Confirm API calls create rows in `audit_logs`.
- Before live use, test with the real Android app, Stripe Terminal SDK, and Reader M2 in Stripe test mode.

## Phase 2 Local Smoke Test Commands

From PowerShell in the repository root:

```powershell
Set-Location ".\backend"
Copy-Item ".\.env.example" ".\.env" -Force
$env:PORT = "3000"
npm start
```

In another PowerShell window:

```powershell
$base = "http://localhost:3000"
$token = "replace_with_a_long_random_internal_token"

Invoke-RestMethod "$base/health"

Invoke-RestMethod `
  -Method Post `
  -Uri "$base/api/v1/terminal/connection-token" `
  -Headers @{ Authorization = "Bearer $token" }

Invoke-RestMethod `
  -Method Post `
  -Uri "$base/api/v1/payment-intents/card-present" `
  -Headers @{ Authorization = "Bearer $token" } `
  -ContentType "application/json" `
  -Body '{"amount":100,"currency":"usd","description":"Phase 2 smoke test"}'
```

## Production Notes

- Keep `API_TOKEN`, Stripe keys, and database credentials out of Git.
- Use HTTPS for all deployed API traffic.
- Rotate `API_TOKEN` if it is ever exposed.
- Use restricted Stripe keys where possible.
- Add rate limiting before exposing this beyond trusted internal networks.
- Add role-based users before building a web dashboard for multiple staff members.
