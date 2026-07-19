import java.util.Properties

plugins {
    id("com.android.application")
}

val localProperties = Properties().apply {
    val localPropertiesFile = rootProject.file("local.properties")
    if (localPropertiesFile.exists()) {
        localPropertiesFile.inputStream().use { load(it) }
    }
}

fun configValue(name: String, fallback: String): String {
    return localProperties.getProperty(name)
        ?: System.getenv(name.uppercase())
        ?: fallback
}

android {
    namespace = "com.example.stripepos"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.example.stripepos"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"

        buildConfigField("String", "BACKEND_BASE_URL", "\"${configValue("backendBaseUrl", "http://10.0.2.2:3000")}\"")
        buildConfigField("String", "BACKEND_API_TOKEN", "\"${configValue("backendApiToken", "replace_with_phase_1_API_TOKEN")}\"")
        buildConfigField("String", "STRIPE_PUBLISHABLE_KEY", "\"${configValue("stripePublishableKey", "pk_test_replace_with_publishable_key")}\"")
        buildConfigField("String", "STRIPE_LOCATION_ID", "\"${configValue("stripeLocationId", "tml_replace_with_test_location_id")}\"")
        buildConfigField("Boolean", "USE_SIMULATED_READER", configValue("useSimulatedReader", "true"))
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }

    buildFeatures {
        buildConfig = true
    }
}

configurations.all {
    resolutionStrategy.eachDependency {
        if (requested.group == "androidx.compose.ui" && requested.name.startsWith("ui")) {
            useVersion("1.11.4")
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.constraintlayout:constraintlayout:2.2.0")
    implementation("com.google.android.material:material:1.12.0")

    implementation("androidx.compose.ui:ui-android:1.11.4")

    implementation("com.stripe:stripe-android:20.35.0")
    implementation("com.stripe:stripeterminal:5.6.0")
    implementation("com.stripe:stripeterminal-ktx:5.6.0")
}
