# Android Stripe Terminal POS Skeleton

Phase 2 adds an Android/Kotlin app skeleton for a future Stripe Reader M2 POS workflow.

This app has not been tested with a real Stripe Reader M2. Reader discovery, connection, card collection, reader updates, and payment completion are device-dependent manual testing items.

## What This Includes

- Android/Kotlin project in `/android`
- Stripe Terminal Android SDK dependency
- `Application` setup with `TerminalApplicationDelegate`
- Terminal initialization from the main screen
- Backend connection-token provider
- Bluetooth reader discovery flow
- Stripe Reader M2 connection flow using Bluetooth connection configuration
- Simple card-present payment screen
- Backend call to create a card-present PaymentIntent
- Terminal collect payment method and confirm payment flow
- Basic success/failure UI state
- Basic customer search/create UI
- Backend base URL, API token, and Stripe Terminal location configuration
- Bluetooth/location permissions with comments in the manifest
- USB fallback placeholders/comments

## Project Structure

```text
android/
  build.gradle.kts
  settings.gradle.kts
  local.properties.example
  app/
    build.gradle.kts
    src/main/
      AndroidManifest.xml
      java/com/example/stripepos/
        MainActivity.kt
        StripePosApplication.kt
        config/AppConfig.kt
        data/ApiClient.kt
        terminal/BackendConnectionTokenProvider.kt
        terminal/TerminalManager.kt
      res/
```

## Android Studio Setup

1. Open the `/android` folder in Android Studio.
1. Let Android Studio sync Gradle.
1. Copy `local.properties.example` to `local.properties`.
1. Keep the `sdk.dir` line that Android Studio manages.
1. Set:

```properties
backendBaseUrl=http://10.0.2.2:3000
backendApiToken=replace_with_phase_1_API_TOKEN
stripeLocationId=tml_replace_with_test_location_id
```

Use `10.0.2.2` when the Android emulator needs to call a backend running on your development machine. Use the machine LAN IP or HTTPS domain for a physical Android device.

## Backend Requirements

Start the Phase 1 backend first:

```bash
cd backend
npm install
npm run dev
```

The Android app expects these existing backend routes:

```text
POST /api/v1/terminal/connection-token
POST /api/v1/payment-intents/card-present
GET  /api/v1/customers?q=...
POST /api/v1/customers
```

No backend route changes were made for Phase 2.

## Stripe Terminal Configuration

Create or choose a Stripe Terminal Location in the Stripe Dashboard or API, then put that ID in `local.properties`:

```properties
stripeLocationId=tml_...
```

The app uses that location when connecting to a Bluetooth reader.

## Permissions

The manifest includes:

- `INTERNET` for backend and Stripe communication
- `ACCESS_FINE_LOCATION` because Stripe Terminal requires location for reader discovery/payment location
- `BLUETOOTH_SCAN` and `BLUETOOTH_CONNECT` for Android 12+
- legacy `BLUETOOTH` and `BLUETOOTH_ADMIN` for older Android versions

The app requests runtime permissions before initializing reader discovery.

Also make sure device Location and Bluetooth services are enabled in Android settings.

## Manual Testing Checklist

These steps require a configured Stripe account, backend, Android device, and reader. They have not been completed in this repository.

- Sync the Gradle project in Android Studio.
- Build and install the app on an Android device or emulator.
- Confirm the app can call `GET /health` indirectly by verifying backend connectivity through customer search/create.
- Confirm requests without the correct API token fail, then set the correct token.
- Tap **Initialize Terminal** and confirm status changes to initialized.
- Power on a Stripe Reader M2 near the Android device.
- Tap **Discover Bluetooth Readers**.
- Select the Reader M2 from the discovered list.
- Confirm the app connects using the configured `stripeLocationId`.
- Create or select a customer.
- Enter an amount in cents.
- Tap **Collect Payment**.
- Present a Stripe Terminal test card to the reader.
- Confirm the app shows success or failure.
- Confirm the backend records the PaymentIntent/payment row.
- Confirm webhook handling separately with Stripe CLI or Dashboard test events.

## USB Fallback

This phase is focused on Bluetooth reader discovery and connection for Stripe Reader M2. USB support is intentionally left as comments/placeholders in the manifest and `TerminalManager`.

## Notes for Future Phases

- Move API token storage to a secure device-management or login-based flow before production use.
- Add proper staff authentication and roles.
- Add a durable local UI state model instead of direct view updates.
- Add retry paths for declined cards using the same PaymentIntent.
- Add reader update UI that blocks payment actions while updates install.
- Add receipt, refund, daily reconciliation, and subscription management screens.

