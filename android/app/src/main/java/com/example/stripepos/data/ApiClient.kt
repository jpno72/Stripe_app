package com.example.stripepos.data

import com.example.stripepos.config.AppConfig
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

data class BackendPaymentIntent(
    val id: String,
    val clientSecret: String,
    val status: String
)

data class CustomerSummary(
    val id: String,
    val stripeCustomerId: String,
    val name: String?,
    val email: String?,
    val phone: String?
) {
    override fun toString(): String {
        return listOfNotNull(name, email, phone).joinToString(" | ").ifBlank { "Customer #$id" }
    }
}

data class MembershipPlan(
    val id: String,
    val name: String,
    val stripePriceId: String,
    val description: String?,
    val unitAmount: Int,
    val currency: String,
    val interval: String?,
    val type: String
) {
    override fun toString(): String {
        val amount = formatMinorAmount(unitAmount, currency)
        val cadence = interval?.let { " / $it" } ?: " one-time"
        val base = "$name | $amount$cadence"
        return if (description.isNullOrBlank()) base else "$base | $description"
    }

    fun isRecurring(): Boolean = type == "recurring" || interval != null

    fun typeLabel(): String = if (isRecurring()) "Recurring" else "One-time"
}

data class PaymentMethodSummary(
    val id: String,
    val type: String,
    val brand: String?,
    val last4: String?,
    val expMonth: Int?,
    val expYear: Int?,
    val isDefault: Boolean
) {
    override fun toString(): String {
        val cardLabel = if (brand.isNullOrBlank() || last4.isNullOrBlank()) {
            "$type payment method"
        } else {
            "${brand.replaceFirstChar { it.uppercase() }} ending in $last4"
        }
        val expiration = if (expMonth != null && expYear != null) {
            " | exp ${expMonth.toString().padStart(2, '0')}/$expYear"
        } else {
            ""
        }
        val defaultLabel = if (isDefault) " | default" else ""
        return "$cardLabel$expiration$defaultLabel"
    }
}

data class SubscriptionSummary(
    val id: String,
    val stripeSubscriptionId: String,
    val status: String,
    val stripePriceId: String?
)

data class SetupIntentSummary(
    val id: String,
    val clientSecret: String,
    val status: String
)

data class DefaultPaymentMethodSummary(
    val setupIntentId: String,
    val customerId: String,
    val defaultPaymentMethodId: String
)

data class ActiveCustomerSummary(
    val id: String,
    val name: String?,
    val email: String?,
    val phone: String?
) {
    override fun toString(): String {
        return listOfNotNull(name, email, phone).joinToString(" | ").ifBlank { id }
    }
}

data class ActiveSubscriptionSummary(
    val id: String,
    val status: String,
    val customerName: String?,
    val customerEmail: String?,
    val productName: String,
    val unitAmount: Int,
    val currency: String,
    val interval: String?
) {
    override fun toString(): String {
        val customer = customerName ?: customerEmail ?: "Customer"
        val amount = formatMinorAmount(unitAmount, currency)
        val cadence = interval?.let { " / $it" }.orEmpty()
        return "$customer | $productName | $amount$cadence | $status"
    }
}

data class ReportTransactionSummary(
    val id: String,
    val type: String,
    val status: String,
    val description: String,
    val amount: Int,
    val currency: String,
    val created: Long?
) {
    override fun toString(): String {
        val date = created?.let { formatEpochSeconds(it) } ?: "date unavailable"
        return "$date | $type | $description | ${formatMinorAmount(amount, currency)} | $status"
    }
}

class ApiClient {
    fun checkHealth(): Boolean {
        val json = try {
            request("GET", "/health", authenticated = false)
        } catch (_: Exception) {
            request("GET", "/health")
        }
        return json.optBoolean("ok", false)
    }

    fun fetchConnectionToken(): String {
        val json = request("POST", "/api/v1/terminal/connection-token")
        return json.getString("secret")
    }

    fun createCardPresentPaymentIntent(
        amount: Int,
        currency: String,
        customerId: String?
    ): BackendPaymentIntent {
        val body = JSONObject()
            .put("amount", amount)
            .put("currency", currency)
            .put("description", "Android POS payment")
            .put("metadata", JSONObject().put("source", "android_terminal_skeleton"))

        if (customerId != null) {
            body.put("customerId", customerId)
        }

        val json = request("POST", "/api/v1/payment-intents/card-present", body)
        val paymentIntent = json.getJSONObject("paymentIntent")

        return BackendPaymentIntent(
            id = paymentIntent.getString("id"),
            clientSecret = paymentIntent.getString("client_secret"),
            status = paymentIntent.optString("status")
        )
    }

    fun searchCustomers(query: String): List<CustomerSummary> {
        if (query.isBlank()) return emptyList()

        val json = request("GET", "/api/v1/customers?q=${query.urlEncode()}")
        return json.getJSONArray("customers").toCustomerSummaries()
    }

    fun createCustomer(name: String, email: String?, phone: String?): CustomerSummary {
        val body = JSONObject().put("name", name)
        if (!email.isNullOrBlank()) body.put("email", email)
        if (!phone.isNullOrBlank()) body.put("phone", phone)

        val json = request("POST", "/api/v1/customers", body)
        return json.getJSONObject("customer").toCustomerSummary()
    }

    fun fetchCustomerPaymentMethods(customerId: String): List<PaymentMethodSummary> {
        val json = request("GET", "/api/v1/customers/${customerId.urlEncode()}/payment-methods")
        return json.getJSONArray("paymentMethods").toPaymentMethodSummaries()
    }

    fun fetchMembershipPlans(): List<MembershipPlan> {
        val json = request("GET", "/api/v1/subscriptions/plans")
        return json.getJSONArray("plans").toMembershipPlans()
    }

    fun fetchActiveCustomers(): List<ActiveCustomerSummary> {
        val json = request("GET", "/api/v1/reports/active-customers")
        return json.getJSONArray("customers").toActiveCustomers()
    }

    fun fetchActiveSubscriptions(): List<ActiveSubscriptionSummary> {
        val json = request("GET", "/api/v1/reports/active-subscriptions")
        return json.getJSONArray("subscriptions").toActiveSubscriptions()
    }

    fun fetchRecentTransactions(): List<ReportTransactionSummary> {
        val json = request("GET", "/api/v1/reports/recent-transactions")
        return json.getJSONArray("transactions").toReportTransactions()
    }

    fun createSubscription(customerId: String, planId: String): SubscriptionSummary {
        // Recurring billing requires the Stripe Customer to have a reusable payment method.
        // A Terminal card-present payment does not by itself save a reusable subscription payment method.
        val body = JSONObject()
            .put("customerId", customerId)
            .put("planId", planId)
            .put("metadata", JSONObject().put("source", "android_membership_setup"))

        val json = request("POST", "/api/v1/subscriptions", body)
        return json.getJSONObject("subscription").toSubscriptionSummary()
    }

    fun createSetupIntent(customerId: String): SetupIntentSummary {
        val body = JSONObject()
            .put("customerId", customerId)
            .put("metadata", JSONObject().put("source", "android_membership_setup"))

        val json = request("POST", "/api/v1/setup-intents", body)
        val setupIntent = json.getJSONObject("setupIntent")

        return SetupIntentSummary(
            id = setupIntent.getString("id"),
            clientSecret = setupIntent.getString("client_secret"),
            status = setupIntent.getString("status")
        )
    }

    fun createCardPaymentIntent(
        amount: Int,
        currency: String,
        customerId: String?,
        description: String
    ): BackendPaymentIntent {
        val body = JSONObject()
            .put("amount", amount)
            .put("currency", currency)
            .put("description", description)
            .put("metadata", JSONObject().put("source", "android_cart_checkout"))

        if (customerId != null) {
            body.put("customerId", customerId)
        }

        val json = request("POST", "/api/v1/payment-intents/card", body)
        val paymentIntent = json.getJSONObject("paymentIntent")

        return BackendPaymentIntent(
            id = paymentIntent.getString("id"),
            clientSecret = paymentIntent.getString("client_secret"),
            status = paymentIntent.optString("status")
        )
    }

    fun setDefaultPaymentMethodFromSetupIntent(setupIntentId: String): DefaultPaymentMethodSummary {
        val json = request("POST", "/api/v1/setup-intents/$setupIntentId/default-payment-method")
        val setupIntent = json.getJSONObject("setupIntent")
        val customer = json.getJSONObject("customer")

        return DefaultPaymentMethodSummary(
            setupIntentId = setupIntent.getString("id"),
            customerId = customer.getString("id"),
            defaultPaymentMethodId = json.getString("defaultPaymentMethod")
        )
    }

    private fun request(
        method: String,
        path: String,
        body: JSONObject? = null,
        authenticated: Boolean = true
    ): JSONObject {
        val url = URL("${AppConfig.backendBaseUrl}$path")
        val connection = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15_000
            readTimeout = 30_000
            if (authenticated) {
                setRequestProperty("Authorization", "Bearer ${AppConfig.backendApiToken}")
            }
            setRequestProperty("Accept", "application/json")
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
            }
        }

        if (body != null) {
            OutputStreamWriter(connection.outputStream).use { writer ->
                writer.write(body.toString())
            }
        }

        val status = connection.responseCode
        val stream = if (status in 200..299) connection.inputStream else connection.errorStream
        val responseBody = stream?.bufferedReader()?.use(BufferedReader::readText).orEmpty()

        if (status !in 200..299) {
            throw IllegalStateException("Backend request failed ($status) for $path: $responseBody")
        }

        return if (responseBody.isBlank()) JSONObject() else JSONObject(responseBody)
    }

    private fun JSONArray.toCustomerSummaries(): List<CustomerSummary> {
        return (0 until length()).map { index ->
            getJSONObject(index).toCustomerSummary()
        }
    }

    private fun JSONArray.toMembershipPlans(): List<MembershipPlan> {
        return (0 until length()).map { index ->
            val plan = getJSONObject(index)
            MembershipPlan(
                id = plan.getString("id"),
                name = plan.getString("name"),
                stripePriceId = plan.getString("stripePriceId"),
                description = plan.optStringOrNull("description"),
                unitAmount = plan.optInt("unitAmount", 0),
                currency = plan.optString("currency", "usd"),
                interval = plan.optStringOrNull("interval"),
                type = plan.optString("type", if (plan.optStringOrNull("interval") == null) "one_time" else "recurring")
            )
        }
    }

    private fun JSONArray.toPaymentMethodSummaries(): List<PaymentMethodSummary> {
        return (0 until length()).map { index ->
            val paymentMethod = getJSONObject(index)
            val card = paymentMethod.optJSONObject("card")
            PaymentMethodSummary(
                id = paymentMethod.getString("id"),
                type = paymentMethod.optString("type", "card"),
                brand = card?.optStringOrNull("brand"),
                last4 = card?.optStringOrNull("last4"),
                expMonth = card?.optIntOrNull("exp_month"),
                expYear = card?.optIntOrNull("exp_year"),
                isDefault = paymentMethod.optBoolean("isDefault", false)
            )
        }
    }

    private fun JSONArray.toActiveCustomers(): List<ActiveCustomerSummary> {
        return (0 until length()).map { index ->
            val customer = getJSONObject(index)
            ActiveCustomerSummary(
                id = customer.getString("id"),
                name = customer.optStringOrNull("name"),
                email = customer.optStringOrNull("email"),
                phone = customer.optStringOrNull("phone")
            )
        }
    }

    private fun JSONArray.toActiveSubscriptions(): List<ActiveSubscriptionSummary> {
        return (0 until length()).map { index ->
            val subscription = getJSONObject(index)
            ActiveSubscriptionSummary(
                id = subscription.getString("id"),
                status = subscription.getString("status"),
                customerName = subscription.optStringOrNull("customerName"),
                customerEmail = subscription.optStringOrNull("customerEmail"),
                productName = subscription.optString("productName", "Subscription"),
                unitAmount = subscription.optInt("unitAmount", 0),
                currency = subscription.optString("currency", "usd"),
                interval = subscription.optStringOrNull("interval")
            )
        }
    }

    private fun JSONArray.toReportTransactions(): List<ReportTransactionSummary> {
        return (0 until length()).map { index ->
            val transaction = getJSONObject(index)
            ReportTransactionSummary(
                id = transaction.getString("id"),
                type = transaction.optString("type", "transaction"),
                status = transaction.optString("status", "unknown"),
                description = transaction.optString("description", "Transaction"),
                amount = transaction.optInt("amount", 0),
                currency = transaction.optString("currency", "usd"),
                created = transaction.optLongOrNull("created")
            )
        }
    }

    private fun JSONObject.toCustomerSummary(): CustomerSummary {
        return CustomerSummary(
            id = getString("id"),
            stripeCustomerId = getString("stripe_customer_id"),
            name = optStringOrNull("name"),
            email = optStringOrNull("email"),
            phone = optStringOrNull("phone")
        )
    }

    private fun JSONObject.toSubscriptionSummary(): SubscriptionSummary {
        return SubscriptionSummary(
            id = getString("id"),
            stripeSubscriptionId = getString("stripe_subscription_id"),
            status = getString("status"),
            stripePriceId = optStringOrNull("stripe_price_id")
        )
    }

    private fun JSONObject.optStringOrNull(name: String): String? {
        return if (isNull(name)) null else optString(name).takeIf { it.isNotBlank() }
    }

    private fun JSONObject.optIntOrNull(name: String): Int? {
        return if (isNull(name) || !has(name)) null else optInt(name)
    }

    private fun JSONObject.optLongOrNull(name: String): Long? {
        return if (isNull(name) || !has(name)) null else optLong(name)
    }

    private fun String.urlEncode(): String {
        return java.net.URLEncoder.encode(this, Charsets.UTF_8.name())
    }
}

fun formatMinorAmount(amount: Int, currency: String): String {
    return "${currency.uppercase()} ${"%.2f".format(amount / 100.0)}"
}

fun formatEpochSeconds(seconds: Long): String {
    val formatter = java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm")
    return java.time.Instant.ofEpochSecond(seconds)
        .atZone(java.time.ZoneId.systemDefault())
        .format(formatter)
}
