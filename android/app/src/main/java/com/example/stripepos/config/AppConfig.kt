package com.example.stripepos.config

import com.example.stripepos.BuildConfig

object AppConfig {
    val backendBaseUrl: String = BuildConfig.BACKEND_BASE_URL.trimEnd('/')
    val backendApiToken: String = BuildConfig.BACKEND_API_TOKEN
    val stripeLocationId: String = BuildConfig.STRIPE_LOCATION_ID
}

