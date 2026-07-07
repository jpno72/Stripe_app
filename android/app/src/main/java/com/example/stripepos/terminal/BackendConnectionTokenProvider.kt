package com.example.stripepos.terminal

import com.example.stripepos.data.ApiClient
import com.stripe.stripeterminal.external.callable.ConnectionTokenCallback
import com.stripe.stripeterminal.external.callable.ConnectionTokenProvider
import com.stripe.stripeterminal.external.models.ConnectionTokenException
import java.util.concurrent.Executors

class BackendConnectionTokenProvider(
    private val apiClient: ApiClient
) : ConnectionTokenProvider {
    private val executor = Executors.newSingleThreadExecutor()

    override fun fetchConnectionToken(callback: ConnectionTokenCallback) {
        executor.execute {
            try {
                callback.onSuccess(apiClient.fetchConnectionToken())
            } catch (error: Exception) {
                callback.onFailure(ConnectionTokenException("Unable to fetch backend connection token", error))
            }
        }
    }
}

