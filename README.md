# Stripe App

Internal Stripe POS and subscription backend for a small martial arts business.

Phase 1 is backend only. It provides a reusable HTTP API for a future Android/Kotlin Stripe Terminal app and a future web dashboard.

See [backend/README.md](backend/README.md) for setup, API endpoints, curl examples, cPanel/Passenger notes, and manual-test items.

Phase 2 adds an Android/Kotlin Stripe Terminal skeleton in [android](android). See [android/README.md](android/README.md) for Android Studio setup and manual Reader M2 testing notes.

Phase 3 adds a first-version membership subscription setup flow: configured Stripe recurring Price IDs, customer selection/creation, subscription creation, and subscription/invoice webhook storage.
