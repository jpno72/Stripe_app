package com.example.stripepos

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ListView
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.example.stripepos.config.AppConfig
import com.example.stripepos.data.ApiClient
import com.example.stripepos.data.CustomerSummary
import com.example.stripepos.data.MembershipPlan
import com.example.stripepos.terminal.TerminalManager
import com.google.android.material.textfield.TextInputEditText
import com.stripe.stripeterminal.external.models.Reader

class MainActivity : AppCompatActivity(), TerminalManager.UiListener {
    private lateinit var terminalManager: TerminalManager
    private val apiClient = ApiClient()

    private lateinit var statusText: TextView
    private lateinit var selectedReaderText: TextView
    private lateinit var selectedCustomerText: TextView
    private lateinit var readersAdapter: ArrayAdapter<String>
    private lateinit var customersAdapter: ArrayAdapter<String>
    private lateinit var plansAdapter: ArrayAdapter<String>

    private var discoveredReaders: List<Reader> = emptyList()
    private var foundCustomers: List<CustomerSummary> = emptyList()
    private var membershipPlans: List<MembershipPlan> = emptyList()
    private var selectedCustomerId: Long? = null
    private var selectedPlan: MembershipPlan? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        terminalManager = TerminalManager(this, this, apiClient)
        setContentView(buildContentView())
        requestRuntimePermissions()
    }

    private fun buildContentView(): View {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 32, 32, 32)
        }

        statusText = TextView(this).apply {
            text = "Ready"
            textSize = 16f
        }

        root.addView(TextView(this).withText("Backend: ${AppConfig.backendBaseUrl}"))
        root.addView(statusText)
        root.addView(sectionTitle("Backend Smoke Tests"))
        root.addView(TextView(this).withText("Simulated reader mode: ${AppConfig.useSimulatedReader}"))
        root.addView(Button(this).withText("Check /health").withClick {
            runNetworkAction("Checking backend health...") {
                val ok = apiClient.checkHealth()
                runOnUiThread {
                    onStatus(if (ok) "Backend /health OK" else "Backend /health returned not OK")
                }
            }
        })
        root.addView(Button(this).withText("Test Connection Token Endpoint").withClick {
            runNetworkAction("Testing connection-token endpoint...") {
                apiClient.fetchConnectionToken()
                runOnUiThread {
                    onStatus("Connection-token endpoint OK")
                }
            }
        })
        root.addView(Button(this).withText("Test Card-Present Intent Endpoint").withClick {
            runNetworkAction("Testing card-present PaymentIntent endpoint...") {
                val intent = apiClient.createCardPresentPaymentIntent(
                    amount = 100,
                    currency = "usd",
                    customerId = null
                )
                runOnUiThread {
                    onStatus("PaymentIntent endpoint OK: ${intent.id}")
                }
            }
        })
        root.addView(sectionTitle("Reader"))

        selectedReaderText = TextView(this).withText("No reader connected")
        root.addView(selectedReaderText)

        root.addView(Button(this).withText("Initialize Terminal").withClick {
            if (hasRequiredPermissions()) terminalManager.initializeTerminal() else requestRuntimePermissions()
        })

        root.addView(Button(this).withText("Discover Bluetooth Readers").withClick {
            if (hasRequiredPermissions()) terminalManager.discoverBluetoothReaders() else requestRuntimePermissions()
        })

        readersAdapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, mutableListOf())
        val readersList = ListView(this).apply {
            adapter = readersAdapter
            setOnItemClickListener { _, _, position, _ ->
                discoveredReaders.getOrNull(position)?.let(terminalManager::connectReader)
            }
        }
        root.addView(readersList, LinearLayout.LayoutParams.MATCH_PARENT, 240)

        root.addView(sectionTitle("Customer"))
        selectedCustomerText = TextView(this).withText("No customer selected")
        root.addView(selectedCustomerText)

        val customerSearchInput = input("Search name, email, or phone")
        root.addView(customerSearchInput)
        root.addView(Button(this).withText("Search Customers").withClick {
            runNetworkAction("Searching customers...") {
                foundCustomers = apiClient.searchCustomers(customerSearchInput.text.toString())
                runOnUiThread {
                    customersAdapter.clear()
                    customersAdapter.addAll(foundCustomers.map(CustomerSummary::toString))
                    customersAdapter.notifyDataSetChanged()
                    onStatus("Found ${foundCustomers.size} customer(s)")
                }
            }
        })

        customersAdapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, mutableListOf())
        val customersList = ListView(this).apply {
            adapter = customersAdapter
            setOnItemClickListener { _, _, position, _ ->
                foundCustomers.getOrNull(position)?.let { customer ->
                    selectedCustomerId = customer.id
                    selectedCustomerText.text = "Selected: ${customer}"
                }
            }
        }
        root.addView(customersList, LinearLayout.LayoutParams.MATCH_PARENT, 200)

        val customerNameInput = input("New customer name")
        val customerEmailInput = input("New customer email")
        val customerPhoneInput = input("New customer phone")
        root.addView(customerNameInput)
        root.addView(customerEmailInput)
        root.addView(customerPhoneInput)
        root.addView(Button(this).withText("Create Customer").withClick {
            val name = customerNameInput.text.toString().trim()
            if (name.isBlank()) {
                Toast.makeText(this, "Customer name is required", Toast.LENGTH_SHORT).show()
                return@withClick
            }

            runNetworkAction("Creating customer...") {
                val customer = apiClient.createCustomer(
                    name = name,
                    email = customerEmailInput.text.toString(),
                    phone = customerPhoneInput.text.toString()
                )
                runOnUiThread {
                    selectedCustomerId = customer.id
                    selectedCustomerText.text = "Selected: $customer"
                    onStatus("Customer created")
                }
            }
        })

        root.addView(sectionTitle("Membership Subscription"))
        root.addView(TextView(this).withText("Recurring billing requires a reusable Stripe payment method on the customer. Terminal card-present payments do not automatically save one for future membership billing."))
        root.addView(Button(this).withText("Load Membership Plans").withClick {
            runNetworkAction("Loading membership plans...") {
                membershipPlans = apiClient.fetchMembershipPlans()
                runOnUiThread {
                    plansAdapter.clear()
                    plansAdapter.addAll(membershipPlans.map(MembershipPlan::toString))
                    plansAdapter.notifyDataSetChanged()
                    onStatus("Loaded ${membershipPlans.size} membership plan(s)")
                }
            }
        })

        plansAdapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, mutableListOf())
        val plansList = ListView(this).apply {
            adapter = plansAdapter
            setOnItemClickListener { _, _, position, _ ->
                membershipPlans.getOrNull(position)?.let { plan ->
                    selectedPlan = plan
                    onStatus("Selected membership plan: ${plan.name}")
                }
            }
        }
        root.addView(plansList, LinearLayout.LayoutParams.MATCH_PARENT, 200)

        root.addView(Button(this).withText("Create Membership Subscription").withClick {
            val customerId = selectedCustomerId
            val plan = selectedPlan

            if (customerId == null) {
                Toast.makeText(this, "Select or create a customer first", Toast.LENGTH_SHORT).show()
                return@withClick
            }

            if (plan == null) {
                Toast.makeText(this, "Select a membership plan first", Toast.LENGTH_SHORT).show()
                return@withClick
            }

            runNetworkAction("Creating subscription...") {
                val subscription = apiClient.createSubscription(customerId, plan.id)
                runOnUiThread {
                    onStatus("Subscription ${subscription.stripeSubscriptionId}: ${subscription.status}")
                }
            }
        })

        root.addView(sectionTitle("Payment"))
        val amountInput = input("Amount in cents, e.g. 2500")
        amountInput.setText("2500")
        val currencyInput = input("Currency")
        currencyInput.setText("usd")
        root.addView(amountInput)
        root.addView(currencyInput)

        root.addView(Button(this).withText("Collect Payment").withClick {
            val amount = amountInput.text.toString().toIntOrNull()
            if (amount == null || amount <= 0) {
                Toast.makeText(this, "Enter a valid amount in cents", Toast.LENGTH_SHORT).show()
                return@withClick
            }

            terminalManager.collectAndProcessPayment(
                amount = amount,
                currency = currencyInput.text.toString().ifBlank { "usd" },
                customerId = selectedCustomerId
            )
        })

        root.addView(Button(this).withText("Cancel Active Payment").withClick {
            terminalManager.cancelActivePayment()
        })

        return ScrollView(this).apply {
            addView(root)
        }
    }

    private fun requestRuntimePermissions() {
        val missing = requiredPermissions().filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }

        if (missing.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, missing.toTypedArray(), REQUEST_CODE_PERMISSIONS)
        }
    }

    private fun hasRequiredPermissions(): Boolean {
        return requiredPermissions().all {
            ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
        }
    }

    private fun requiredPermissions(): List<String> {
        return buildList {
            add(Manifest.permission.ACCESS_FINE_LOCATION)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                add(Manifest.permission.BLUETOOTH_SCAN)
                add(Manifest.permission.BLUETOOTH_CONNECT)
            }
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == REQUEST_CODE_PERMISSIONS && !hasRequiredPermissions()) {
            onStatus("Location and Bluetooth permissions are required for reader discovery")
        }
    }

    override fun onStatus(message: String) {
        runOnUiThread {
            statusText.text = message
        }
    }

    override fun onReadersUpdated(readers: List<Reader>) {
        runOnUiThread {
            discoveredReaders = readers
            readersAdapter.clear()
            readersAdapter.addAll(readers.map { reader ->
                "${reader.serialNumber ?: "Unknown serial"} ${reader.deviceType}"
            })
            readersAdapter.notifyDataSetChanged()
        }
    }

    override fun onReaderConnected(reader: Reader) {
        runOnUiThread {
            selectedReaderText.text = "Connected: ${reader.serialNumber ?: "reader"}"
        }
    }

    override fun onPaymentSucceeded(message: String) {
        runOnUiThread {
            statusText.text = message
            Toast.makeText(this, message, Toast.LENGTH_LONG).show()
        }
    }

    override fun onPaymentFailed(message: String) {
        runOnUiThread {
            statusText.text = message
            Toast.makeText(this, message, Toast.LENGTH_LONG).show()
        }
    }

    private fun runNetworkAction(status: String, action: () -> Unit) {
        onStatus(status)
        Thread {
            try {
                action()
            } catch (error: Exception) {
                onPaymentFailed(error.message ?: "Network request failed")
            }
        }.start()
    }

    private fun sectionTitle(text: String): TextView {
        return TextView(this).apply {
            this.text = text
            textSize = 20f
            setPadding(0, 28, 0, 8)
        }
    }

    private fun input(hint: String): TextInputEditText {
        return TextInputEditText(this).apply {
            this.hint = hint
            setSingleLine(true)
        }
    }

    private fun TextView.withText(value: String): TextView {
        text = value
        return this
    }

    private fun Button.withText(value: String): Button {
        text = value
        return this
    }

    private fun Button.withClick(action: () -> Unit): Button {
        setOnClickListener { action() }
        return this
    }

    companion object {
        private const val REQUEST_CODE_PERMISSIONS = 1001
    }
}
