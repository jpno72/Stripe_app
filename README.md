# Stripe App

Internal Stripe POS and subscription backend for a small martial arts business. Stripe is the source of truth by default; local database persistence is optional for future reporting/admin history.

Phase 1 is backend only. It provides a reusable HTTP API for a future Android/Kotlin Stripe Terminal app and a future web dashboard.

See [backend/README.md](backend/README.md) for setup, API endpoints, curl examples, cPanel/Passenger notes, and manual-test items.

Stripe Terminal connection token endpoint:

```text
POST /api/v1/terminal/connection_token
```

PowerShell test command:

```powershell
Invoke-RestMethod -Method Post -Uri "http://localhost:3000/api/v1/terminal/connection_token" -Headers @{ Authorization = "Bearer replace_with_a_long_random_internal_token" }
```

Phase 2 adds an Android/Kotlin Stripe Terminal skeleton in [android](android). See [android/README.md](android/README.md) for Android Studio setup and manual Reader M2 testing notes.

Phase 3 adds a first-version membership subscription setup flow: configured Stripe recurring Price IDs, customer selection/creation, subscription creation, and Stripe-verified webhook handling.
