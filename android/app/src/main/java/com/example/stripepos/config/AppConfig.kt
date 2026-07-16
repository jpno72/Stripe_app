package com.example.stripepos.config

import com.example.stripepos.BuildConfig

object AppConfig {
    val backendBaseUrl: String = BuildConfig.BACKEND_BASE_URL.trimEnd('/')
    val backendApiToken: String = BuildConfig.BACKEND_API_TOKEN
    val stripePublishableKey: String = BuildConfig.STRIPE_PUBLISHABLE_KEY
    val stripeLocationId: String = BuildConfig.STRIPE_LOCATION_ID
    val useSimulatedReader: Boolean = BuildConfig.USE_SIMULATED_READER
}
