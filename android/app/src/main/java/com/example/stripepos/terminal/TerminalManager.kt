package com.example.stripepos.terminal

import android.content.Context
import android.os.Handler
import android.os.Looper
import com.example.stripepos.config.AppConfig
import com.example.stripepos.data.ApiClient
import com.stripe.stripeterminal.Terminal
import com.stripe.stripeterminal.external.callable.Callback
import com.stripe.stripeterminal.external.callable.Cancelable
import com.stripe.stripeterminal.external.callable.DiscoveryListener
import com.stripe.stripeterminal.external.callable.MobileReaderListener
import com.stripe.stripeterminal.external.callable.PaymentIntentCallback
import com.stripe.stripeterminal.external.callable.ReaderCallback
import com.stripe.stripeterminal.external.callable.TerminalListener
import com.stripe.stripeterminal.external.models.ConnectionStatus
import com.stripe.stripeterminal.external.models.DisconnectReason
import com.stripe.stripeterminal.external.models.PaymentIntent
import com.stripe.stripeterminal.external.models.PaymentStatus
import com.stripe.stripeterminal.external.models.Reader
import com.stripe.stripeterminal.external.models.ReaderDisplayMessage
import com.stripe.stripeterminal.external.models.ReaderInputOptions
import com.stripe.stripeterminal.external.models.ReaderSoftwareUpdate
import com.stripe.stripeterminal.external.models.TerminalException
import com.stripe.stripeterminal.external.models.ConnectionConfiguration.BluetoothConnectionConfiguration
import com.stripe.stripeterminal.external.models.DiscoveryConfiguration.BluetoothDiscoveryConfiguration
import com.stripe.stripeterminal.log.LogLevel

class TerminalManager(
    private val context: Context,
    private val listener: UiListener,
    private val apiClient: ApiClient = ApiClient()
) : TerminalListener, DiscoveryListener, MobileReaderListener {

    interface UiListener {
        fun onStatus(message: String)
        fun onReadersUpdated(readers: List<Reader>)
        fun onReaderConnected(reader: Reader)
        fun onReaderDisconnected()
        fun onPaymentSucceeded(message: String)
        fun onPaymentFailed(message: String)
    }

    private var discoverCancelable: Cancelable? = null
    private var paymentCancelable: Cancelable? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    fun initializeTerminal() {
        try {
            if (!Terminal.isInitialized()) {
                Terminal.init(
                    context.applicationContext,
                    LogLevel.VERBOSE,
                    BackendConnectionTokenProvider(apiClient),
                    this,
                    null
                )
            }
            listener.onStatus("Stripe Terminal initialized")
        } catch (error: Exception) {
            listener.onPaymentFailed("Stripe Terminal initialization failed: ${error.message}")
        }
    }

    fun discoverBluetoothReaders() {
        initializeTerminal()
        val mode = if (AppConfig.useSimulatedReader) "simulated" else "Bluetooth"
        listener.onStatus("Discovering $mode readers...")

        val config = BluetoothDiscoveryConfiguration(
            timeout = 0,
            isSimulated = AppConfig.useSimulatedReader
        )

        discoverCancelable = Terminal.getInstance().discoverReaders(
            config,
            this,
            object : Callback {
                override fun onSuccess() {
                    listener.onStatus("Reader discovery stopped")
                }

                override fun onFailure(e: TerminalException) {
                    listener.onPaymentFailed("Reader discovery failed: ${e.errorMessage}")
                }
            }
        )
    }

    fun stopDiscovery() {
        discoverCancelable?.cancel(object : Callback {
            override fun onSuccess() {
                listener.onStatus("Reader discovery canceled")
            }

            override fun onFailure(e: TerminalException) {
                listener.onStatus("Could not cancel discovery: ${e.errorMessage}")
            }
        })
    }

    fun connectReader(reader: Reader) {
        stopDiscovery()
        listener.onStatus("Connecting to ${reader.serialNumber ?: "reader"}...")

        val config = BluetoothConnectionConfiguration(
            AppConfig.stripeLocationId,
            true,
            this
        )

        Terminal.getInstance().connectReader(
            reader,
            config,
            object : ReaderCallback {
                override fun onSuccess(reader: Reader) {
                    listener.onReaderConnected(reader)
                    listener.onStatus("Connected to ${reader.serialNumber ?: "reader"}")
                }

                override fun onFailure(e: TerminalException) {
                    listener.onPaymentFailed("Reader connection failed: ${e.errorMessage}")
                }
            }
        )
    }

    fun disconnectReader() {
        initializeTerminal()
        listener.onStatus("Disconnecting reader...")
        Terminal.getInstance().disconnectReader(object : Callback {
            override fun onSuccess() {
                listener.onReaderDisconnected()
                listener.onStatus("Reader disconnected")
            }

            override fun onFailure(e: TerminalException) {
                listener.onPaymentFailed("Reader disconnect failed: ${e.errorMessage}")
            }
        })
    }

    fun collectAndProcessPayment(amount: Int, currency: String, customerId: String?) {
        initializeTerminal()
        listener.onStatus("Creating card-present PaymentIntent...")

        Thread {
            try {
                val backendIntent = apiClient.createCardPresentPaymentIntent(amount, currency, customerId)
                listener.onStatus("Retrieving PaymentIntent ${backendIntent.id}...")

                mainHandler.post {
                    Terminal.getInstance().retrievePaymentIntent(
                        backendIntent.clientSecret,
                        object : PaymentIntentCallback {
                            override fun onSuccess(paymentIntent: PaymentIntent) {
                                collectPaymentMethod(paymentIntent)
                            }

                            override fun onFailure(e: TerminalException) {
                                listener.onPaymentFailed("Could not retrieve PaymentIntent: ${e.errorMessage}")
                            }
                        }
                    )
                }
            } catch (error: Exception) {
                listener.onPaymentFailed("Backend PaymentIntent request failed: ${error.message}")
            }
        }.start()
    }

    private fun collectPaymentMethod(paymentIntent: PaymentIntent) {
        listener.onStatus("Waiting for card on reader...")

        paymentCancelable = Terminal.getInstance().collectPaymentMethod(
            paymentIntent,
            object : PaymentIntentCallback {
                override fun onSuccess(paymentIntent: PaymentIntent) {
                    confirmPaymentIntent(paymentIntent)
                }

                override fun onFailure(e: TerminalException) {
                    listener.onPaymentFailed("Card collection failed: ${e.errorMessage}")
                }
            }
        )
    }

    private fun confirmPaymentIntent(paymentIntent: PaymentIntent) {
        listener.onStatus("Processing payment...")

        paymentCancelable = Terminal.getInstance().confirmPaymentIntent(
            paymentIntent,
            object : PaymentIntentCallback {
                override fun onSuccess(paymentIntent: PaymentIntent) {
                    listener.onPaymentSucceeded("Payment complete: ${paymentIntent.status}")
                }

                override fun onFailure(e: TerminalException) {
                    listener.onPaymentFailed("Payment processing failed: ${e.errorMessage}")
                }
            }
        )
    }

    fun cancelActivePayment() {
        paymentCancelable?.cancel(object : Callback {
            override fun onSuccess() {
                listener.onStatus("Payment canceled")
            }

            override fun onFailure(e: TerminalException) {
                listener.onStatus("Could not cancel payment: ${e.errorMessage}")
            }
        })
    }

    override fun onUpdateDiscoveredReaders(readers: List<Reader>) {
        listener.onReadersUpdated(readers)
        listener.onStatus("Found ${readers.size} reader(s)")
    }

    override fun onConnectionStatusChange(status: ConnectionStatus) {
        listener.onStatus("Connection status: $status")
    }

    override fun onPaymentStatusChange(status: PaymentStatus) {
        listener.onStatus("Payment status: $status")
    }

    override fun onRequestReaderInput(options: ReaderInputOptions) {
        listener.onStatus("Reader input requested: $options")
    }

    override fun onRequestReaderDisplayMessage(message: ReaderDisplayMessage) {
        listener.onStatus("Reader display message: $message")
    }

    override fun onDisconnect(reason: DisconnectReason) {
        listener.onReaderDisconnected()
        listener.onStatus("Reader disconnected: $reason")
    }

    override fun onReportAvailableUpdate(update: ReaderSoftwareUpdate) {
        listener.onStatus("Reader update available. Manual test should cover update flow.")
    }

    override fun onStartInstallingUpdate(update: ReaderSoftwareUpdate, cancelable: Cancelable?) {
        listener.onStatus("Installing reader update...")
    }

    override fun onReportReaderSoftwareUpdateProgress(progress: Float) {
        listener.onStatus("Reader update progress: ${(progress * 100).toInt()}%")
    }

    override fun onFinishInstallingUpdate(update: ReaderSoftwareUpdate?, e: TerminalException?) {
        listener.onStatus(if (e == null) "Reader update finished" else "Reader update failed: ${e.errorMessage}")
    }

    // USB fallback placeholder:
    // A future phase can add a USB discovery/connection path beside the Bluetooth flow when the
    // selected Stripe reader and deployment device require it.
}
