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
    val id: Long,
    val stripeCustomerId: String,
    val name: String?,
    val email: String?,
    val phone: String?
) {
    override fun toString(): String {
        return listOfNotNull(name, email, phone).joinToString(" | ").ifBlank { "Customer #$id" }
    }
}

class ApiClient {
    fun fetchConnectionToken(): String {
        val json = request("POST", "/api/v1/terminal/connection-token")
        return json.getString("secret")
    }

    fun createCardPresentPaymentIntent(
        amount: Int,
        currency: String,
        customerId: Long?
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

    private fun request(method: String, path: String, body: JSONObject? = null): JSONObject {
        val url = URL("${AppConfig.backendBaseUrl}$path")
        val connection = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15_000
            readTimeout = 30_000
            setRequestProperty("Authorization", "Bearer ${AppConfig.backendApiToken}")
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
            throw IllegalStateException("Backend request failed ($status): $responseBody")
        }

        return if (responseBody.isBlank()) JSONObject() else JSONObject(responseBody)
    }

    private fun JSONArray.toCustomerSummaries(): List<CustomerSummary> {
        return (0 until length()).map { index ->
            getJSONObject(index).toCustomerSummary()
        }
    }

    private fun JSONObject.toCustomerSummary(): CustomerSummary {
        return CustomerSummary(
            id = getLong("id"),
            stripeCustomerId = getString("stripe_customer_id"),
            name = optStringOrNull("name"),
            email = optStringOrNull("email"),
            phone = optStringOrNull("phone")
        )
    }

    private fun JSONObject.optStringOrNull(name: String): String? {
        return if (isNull(name)) null else optString(name).takeIf { it.isNotBlank() }
    }

    private fun String.urlEncode(): String {
        return java.net.URLEncoder.encode(this, Charsets.UTF_8.name())
    }
}

