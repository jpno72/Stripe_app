package com.example.stripepos

import android.Manifest
import android.app.AlertDialog
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.print.PrintAttributes
import android.print.PrintManager
import android.util.Log
import android.view.View
import android.view.ViewGroup
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ListView
import android.widget.ScrollView
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.example.stripepos.config.AppConfig
import com.example.stripepos.data.ApiClient
import com.example.stripepos.data.CustomerSummary
import com.example.stripepos.data.MembershipPlan
import com.example.stripepos.data.ReportTransactionSummary
import com.example.stripepos.data.formatEpochSeconds
import com.example.stripepos.data.formatMinorAmount
import com.example.stripepos.terminal.TerminalManager
import com.google.android.material.textfield.TextInputEditText
import com.stripe.android.PaymentConfiguration
import com.stripe.android.paymentsheet.PaymentSheet
import com.stripe.android.paymentsheet.PaymentSheetResult
import com.stripe.stripeterminal.external.models.Reader

class MainActivity : AppCompatActivity(), TerminalManager.UiListener {
    private lateinit var terminalManager: TerminalManager
    private lateinit var paymentSheet: PaymentSheet
    private val apiClient = ApiClient()
    private val handler = Handler(Looper.getMainLooper())

    private lateinit var statusText: TextView
    private var connectedReaderText: TextView? = null
    private var readersListView: ListView? = null
    private var readersAdapter: ArrayAdapter<String>? = null
    private var discoveredReaders: List<Reader> = emptyList()
    private var connectedReaderLabel: String? = null

    private var selectedCustomer: CustomerSummary? = null
    private var selectedPlan: MembershipPlan? = null
    private var foundCustomers: List<CustomerSummary> = emptyList()
    private var loadedPlans: List<MembershipPlan> = emptyList()
    private val cartItems = mutableListOf<CartItem>()
    private val recentAppTransactions = mutableListOf<AppTransaction>()

    private var pendingCheckoutItem: CartItem? = null
    private var pendingSetupIntentId: String? = null
    private var pendingPaymentIntentId: String? = null
    private var pendingReaderCheckoutItem: CartItem.OneTime? = null
    private var readerPaymentDialog: AlertDialog? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        installCrashLogger()
        PaymentConfiguration.init(applicationContext, AppConfig.stripePublishableKey)
        paymentSheet = PaymentSheet(this, ::onSetupPaymentSheetResult)
        terminalManager = TerminalManager(this, this, apiClient)
        showMainMenu()
        showPreviousCrashIfPresent()
        requestRuntimePermissions()
    }

    private fun showMainMenu() {
        setContentView(R.layout.screen_main_menu)
        findViewById<TextView>(R.id.mainBackendText).text = "Backend: ${AppConfig.backendBaseUrl}"
        statusText = findViewById<TextView>(R.id.mainStatusText).apply {
            text = "Ready"
        }
        findViewById<Button>(R.id.testingConfigurationButton).setOnClickListener { showTestingScreen() }
        findViewById<Button>(R.id.reportingButton).setOnClickListener { showReportingScreen() }
        findViewById<Button>(R.id.takePaymentButton).setOnClickListener { showTakePaymentScreen() }
        findViewById<Button>(R.id.cartButton).apply {
            text = "Cart (${cartItems.size})"
            setOnClickListener { showCartScreen() }
        }
        findViewById<Button>(R.id.recentTransactionsButton).setOnClickListener { showRecentTransactionsScreen() }
    }

    private fun showTestingScreen() {
        setContentView(R.layout.screen_testing_configuration)
        findViewById<Button>(R.id.testingBackButton).setOnClickListener {
            clearTakePaymentSelection()
            showMainMenu()
        }
        findViewById<TextView>(R.id.testingSimulatedReaderText).text =
            "Simulated reader mode: ${AppConfig.useSimulatedReader}"
        statusText = findViewById<TextView>(R.id.testingStatusText).apply {
            text = "Ready"
        }

        findViewById<Button>(R.id.checkHealthButton).setOnClickListener {
            runNetworkAction("Checking backend health...") {
                val ok = apiClient.checkHealth()
                onStatus(if (ok) "Backend /health OK" else "Backend /health returned not OK")
            }
        }
        findViewById<Button>(R.id.testConnectionButton).setOnClickListener {
            runNetworkAction("Testing connection endpoint...") {
                apiClient.fetchConnectionToken()
                onStatus("Connection endpoint OK")
            }
        }
        findViewById<Button>(R.id.testCardPresentButton).setOnClickListener {
            runNetworkAction("Testing card-present PaymentIntent endpoint...") {
                val intent = apiClient.createCardPresentPaymentIntent(100, "usd", null)
                onStatus("PaymentIntent endpoint OK: ${intent.id}")
            }
        }

        connectedReaderText = findViewById<TextView>(R.id.connectedReaderText).apply {
            text = connectedReaderDisplayText()
        }
        findViewById<Button>(R.id.disconnectReaderButton).setOnClickListener {
            if (connectedReaderLabel == null) {
                Toast.makeText(this, "No reader is connected", Toast.LENGTH_SHORT).show()
            } else {
                terminalManager.disconnectReader()
            }
        }
        findViewById<Button>(R.id.initializeTerminalButton).setOnClickListener {
            if (hasRequiredPermissions()) terminalManager.initializeTerminal() else requestRuntimePermissions()
        }
        findViewById<Button>(R.id.discoverReadersButton).setOnClickListener {
            if (hasRequiredPermissions()) terminalManager.discoverBluetoothReaders() else requestRuntimePermissions()
        }

        readersAdapter = multilineAdapter()
        readersListView = findViewById<ListView>(R.id.readersList).apply {
            adapter = readersAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                discoveredReaders.getOrNull(position)?.let(terminalManager::connectReader)
            }
        }
        expandListToContent(readersListView ?: return)
    }

    private fun showReportingScreen() {
        val root = screenRoot("Reporting")
        root.addView(backButton())
        root.addView(statusLabel("Ready"))
        val resultsAdapter = multilineAdapter()
        val results = ListView(this).apply {
            adapter = resultsAdapter
            isNestedScrollingEnabled = false
        }

        root.addView(menuButton("Show Active Customers") {
            runNetworkAction("Loading active customers...") {
                val customers = apiClient.fetchActiveCustomers()
                runOnUiThread {
                    resultsAdapter.clear()
                    resultsAdapter.addAll(customers.map { it.toString() }.ifEmpty { listOf("No active customers found") })
                    resultsAdapter.notifyDataSetChanged()
                    expandListToContent(results)
                    onStatus("Loaded ${customers.size} active customer(s)")
                }
            }
        })
        root.addView(menuButton("Show Active Subscriptions") {
            runNetworkAction("Loading active subscriptions...") {
                val subscriptions = apiClient.fetchActiveSubscriptions()
                runOnUiThread {
                    resultsAdapter.clear()
                    resultsAdapter.addAll(subscriptions.map { it.toString() }.ifEmpty { listOf("No active subscriptions found") })
                    resultsAdapter.notifyDataSetChanged()
                    expandListToContent(results)
                    onStatus("Loaded ${subscriptions.size} active subscription(s)")
                }
            }
        })
        root.addView(results, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        setScrollableContent(root)
    }

    private fun showTakePaymentScreen() {
        val root = screenRoot("Take Payment")
        root.addView(statusLabel("Select a customer and product"))

        val bucketText = selectionPanel(bucketText())
        root.addView(bucketText)
        root.addView(
            buttonRow(
                compactButton("Back to Main Screen") {
                    clearTakePaymentSelection()
                    showMainMenu()
                },
                compactButton("Go to Checkout") {
                    clearTakePaymentSelection()
                    showCartScreen()
                }
            )
        )

        val customerToggleRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
        }
        val customerLookupSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        val newCustomerSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }

        lateinit var lookupToggleButton: Button
        lateinit var newCustomerToggleButton: Button
        fun showCustomerToggleButtons() {
            lookupToggleButton.visibility = View.VISIBLE
            newCustomerToggleButton.visibility = View.VISIBLE
            customerLookupSection.visibility = View.GONE
            newCustomerSection.visibility = View.GONE
        }
        fun showOnlyCustomerLookup() {
            lookupToggleButton.visibility = View.VISIBLE
            newCustomerToggleButton.visibility = View.GONE
            customerLookupSection.visibility = View.VISIBLE
            newCustomerSection.visibility = View.GONE
        }
        fun showOnlyNewCustomer() {
            lookupToggleButton.visibility = View.GONE
            newCustomerToggleButton.visibility = View.VISIBLE
            customerLookupSection.visibility = View.GONE
            newCustomerSection.visibility = View.VISIBLE
        }

        lookupToggleButton = compactButton("Customer Lookup") {
            if (customerLookupSection.visibility == View.VISIBLE) {
                showCustomerToggleButtons()
            } else {
                showOnlyCustomerLookup()
            }
        }
        newCustomerToggleButton = compactButton("New Customer") {
            if (newCustomerSection.visibility == View.VISIBLE) {
                showCustomerToggleButtons()
            } else {
                showOnlyNewCustomer()
            }
        }
        customerToggleRow.addView(lookupToggleButton, twoColumnButtonParams())
        customerToggleRow.addView(newCustomerToggleButton, twoColumnButtonParams())
        root.addView(customerToggleRow)

        val customerSearchInput = input("Search name, email, or phone")
        customerLookupSection.addView(customerSearchInput)
        val customersAdapter = multilineAdapter()
        lateinit var customersList: ListView
        customerLookupSection.addView(menuButton("Search Customers") {
            runNetworkAction("Searching customers...") {
                foundCustomers = apiClient.searchCustomers(customerSearchInput.text.toString())
                runOnUiThread {
                    customersAdapter.clear()
                    customersAdapter.addAll(foundCustomers.map(CustomerSummary::toString).ifEmpty { listOf("No customers found") })
                    customersAdapter.notifyDataSetChanged()
                    expandListToContent(customersList)
                    onStatus("Found ${foundCustomers.size} customer(s)")
                }
            }
        })
        customersList = ListView(this).apply {
            adapter = customersAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                foundCustomers.getOrNull(position)?.let {
                    selectedCustomer = it
                    bucketText.text = bucketText()
                    onStatus("Selected customer: ${it.name ?: it.email ?: it.id}")
                }
            }
        }
        customerLookupSection.addView(customersList, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        root.addView(customerLookupSection)

        val customerNameInput = input("Name")
        val customerEmailInput = input("Email")
        val customerPhoneInput = input("Phone")
        newCustomerSection.addView(customerNameInput)
        newCustomerSection.addView(customerEmailInput)
        newCustomerSection.addView(customerPhoneInput)
        newCustomerSection.addView(menuButton("Use Entered Customer") {
            val name = customerNameInput.text.toString().trim()
            if (name.isBlank()) {
                Toast.makeText(this, "Customer name is required", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            runNetworkAction("Creating customer...") {
                val customer = apiClient.createCustomer(
                    name,
                    customerEmailInput.text.toString(),
                    customerPhoneInput.text.toString()
                )
                runOnUiThread {
                    selectedCustomer = customer
                    bucketText.text = bucketText()
                    onStatus("Customer ready")
                }
            }
        })
        root.addView(newCustomerSection)

        root.addView(sectionTitle("Single Payment"))
        val oneTimeNameInput = input("Payment name")
        val oneTimeAmountInput = input("Dollar amount, e.g. 25.00")
        root.addView(oneTimeNameInput)
        root.addView(oneTimeAmountInput)
        root.addView(menuButton("Add Single Payment to Cart") {
            val customer = selectedCustomer
            if (customer == null) {
                Toast.makeText(this, "Select or create a customer first", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            val name = oneTimeNameInput.text.toString().trim()
            if (name.isBlank()) {
                Toast.makeText(this, "Payment name is required", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            val amount = parseDollarAmountToCents(oneTimeAmountInput.text.toString())
            if (amount == null || amount <= 0) {
                Toast.makeText(this, "Enter a valid dollar amount", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            cartItems.add(CartItem.OneTime(customer, name, amount, "usd"))
            recentAppTransactions.add(AppTransaction("cart", customer.toString(), name, "added_to_cart"))
            Toast.makeText(this, "Single payment added to cart", Toast.LENGTH_SHORT).show()
            showCartScreen()
        })

        root.addView(sectionTitle("Product Lookup"))
        val productSearchInput = input("Search product")
        root.addView(productSearchInput)
        val productsAdapter = multilineAdapter()
        var productsList: ListView? = null
        val productTypeOptions = listOf("All", "One-time", "Recurring")
        var selectedProductTypeFilter = productTypeOptions.first()
        root.addView(TextView(this).withText("Product type"))
        val productTypeSpinner = filterDropdown(productTypeOptions) { selectedFilter ->
            selectedProductTypeFilter = selectedFilter
            val listView = productsList
            if (loadedPlans.isNotEmpty() && listView != null) {
                applyProductFilter(
                    productsAdapter,
                    listView,
                    productSearchInput.text.toString(),
                    selectedProductTypeFilter
                )
            }
        }
        root.addView(productTypeSpinner)
        fun loadProducts(filter: String = "") {
            runNetworkAction("Loading products...") {
                loadedPlans = apiClient.fetchMembershipPlans()
                runOnUiThread {
                    val filtered = applyProductFilter(
                        productsAdapter,
                        productsList ?: return@runOnUiThread,
                        filter,
                        selectedProductTypeFilter
                    )
                    onStatus("Loaded ${filtered.size} product(s)")
                }
            }
        }
        root.addView(
            buttonRow(
                compactButton("Search Products") { loadProducts(productSearchInput.text.toString()) },
                compactButton("List All Products") { loadProducts() }
            )
        )
        productsList = ListView(this).apply {
            adapter = productsAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                val visiblePlans = filteredProducts(productSearchInput.text.toString(), selectedProductTypeFilter)
                visiblePlans.getOrNull(position)?.let {
                    selectedPlan = it
                    bucketText.text = bucketText()
                    onStatus("Selected product: ${it.name}")
                }
            }
        }
        root.addView(productsList, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)

        root.addView(menuButton("Add Selected Product to Cart") {
            val customer = selectedCustomer
            val plan = selectedPlan
            if (customer == null) {
                Toast.makeText(this, "Select or create a customer first", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            if (plan == null) {
                Toast.makeText(this, "Select a product first", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            if (plan.isRecurring()) {
                cartItems.add(CartItem.Recurring(customer, plan))
            } else {
                cartItems.add(CartItem.OneTime(customer, plan.name, plan.unitAmount, plan.currency))
            }
            recentAppTransactions.add(AppTransaction("cart", customer.toString(), plan.name, "added_to_cart"))
            selectedPlan = null
            bucketText.text = bucketText()
            Toast.makeText(this, "Added to cart", Toast.LENGTH_SHORT).show()
            showCartScreen()
        })

        setScrollableContent(root)
    }

    private fun showCartScreen() {
        clearTakePaymentSelection()
        val root = screenRoot("Cart")
        root.addView(backButton())
        root.addView(statusLabel("Ready"))
        val cartAdapter = multilineAdapter(cartItems.map(::cartLabel).toMutableList())
        val cartList = ListView(this).apply {
            adapter = cartAdapter
            isNestedScrollingEnabled = false
        }
        root.addView(cartList, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        expandListToContent(cartList)
        root.addView(menuButton("Checkout") {
            if (cartItems.isEmpty()) {
                Toast.makeText(this, "Cart is empty", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            checkoutCartItem(cartItems.first())
        })
        addCheckoutReaderControls(root)
        root.addView(menuButton("Pay with Card Reader") {
            if (cartItems.isEmpty()) {
                Toast.makeText(this, "Cart is empty", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            checkoutCartItemWithReader(cartItems.first())
        })
        setScrollableContent(root)
    }

    private fun addCheckoutReaderControls(root: LinearLayout) {
        root.addView(sectionTitle("Card Reader"))
        connectedReaderText = selectionPanel(connectedReaderDisplayText())
        root.addView(connectedReaderText)

        root.addView(buttonRow(
            compactButton("Discover Bluetooth Reader") {
                if (hasRequiredPermissions()) terminalManager.discoverBluetoothReaders() else requestRuntimePermissions()
            },
            compactButton("Disconnect Reader") {
                if (connectedReaderLabel == null) {
                    Toast.makeText(this, "No reader is connected", Toast.LENGTH_SHORT).show()
                } else {
                    terminalManager.disconnectReader()
                }
            }
        ))

        readersAdapter = multilineAdapter(discoveredReaders.map { "${it.serialNumber ?: "Unknown serial"} ${it.deviceType}" }.toMutableList())
        readersListView = ListView(this).apply {
            adapter = readersAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                discoveredReaders.getOrNull(position)?.let(terminalManager::connectReader)
            }
        }
        root.addView(readersListView, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        readersListView?.let(::expandListToContent)
    }

    private fun checkoutCartItemWithReader(item: CartItem) {
        if (connectedReaderLabel == null) {
            onPaymentFailed("Connect a card reader before starting reader checkout")
            return
        }

        if (item !is CartItem.OneTime) {
            onPaymentFailed("Use regular Checkout for recurring memberships and subscriptions")
            return
        }

        pendingReaderCheckoutItem = item
        showReaderPaymentDialog(item)
        terminalManager.collectAndProcessPayment(item.amount, item.currency, item.customer.id)
    }

    private fun showReaderPaymentDialog(item: CartItem.OneTime) {
        readerPaymentDialog?.dismiss()
        readerPaymentDialog = AlertDialog.Builder(this)
            .setTitle("Card Reader Payment")
            .setMessage(
                "Amount: ${formatMinorAmount(item.amount, item.currency)}\n\n" +
                    "Follow the reader prompts.\n\n" +
                    "Accepted methods:\n" +
                    "Tap contactless card or wallet\n" +
                    "Insert chip card\n" +
                    "Swipe magnetic stripe if prompted"
            )
            .setNegativeButton("Cancel") { dialog, _ ->
                terminalManager.cancelActivePayment()
                pendingReaderCheckoutItem = null
                dialog.dismiss()
            }
            .create()
        readerPaymentDialog?.show()
    }

    private fun showRecentTransactionsScreen() {
        val root = screenRoot("Recent App Transactions")
        root.addView(backButton())
        root.addView(statusLabel("Ready"))
        val filterOptions = listOf(
            "All",
            "Succeeded",
            "Failed",
            "Canceled",
            "Added to cart",
            "Checkout",
            "Terminal",
            "App only",
            "Stripe only"
        )
        var stripeItems: List<ReportTransactionSummary> = emptyList()
        var visibleDisplayItems: List<TransactionDisplayItem> = emptyList()
        var selectedReceipt: ReceiptDetails? = null
        val selectedReceiptText = selectionPanel("Select a transaction to resend a receipt")
        val transactionsAdapter = multilineAdapter()
        val transactionsList = ListView(this).apply {
            adapter = transactionsAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                val receipt = visibleDisplayItems.getOrNull(position)?.receipt
                if (receipt == null) {
                    selectedReceipt = null
                    selectedReceiptText.text = "This transaction does not have receipt details available"
                    Toast.makeText(this@MainActivity, "No receipt available for this transaction", Toast.LENGTH_SHORT).show()
                } else {
                    selectedReceipt = receipt
                    selectedReceiptText.text = "Selected Receipt\n${receiptSummary(receipt)}"
                }
            }
        }

        fun currentDisplayItems(): List<TransactionDisplayItem> {
            return recentAppTransactions.map {
                TransactionDisplayItem(
                    label = it.toString(),
                    source = "app",
                    action = it.action,
                    result = it.result,
                    receipt = it.receipt
                )
            }.plus(
                stripeItems.map {
                    val receipt = ReceiptDetails(
                        customerName = null,
                        customerEmail = null,
                        customerPhone = null,
                        product = it.description,
                        amount = formatMinorAmount(it.amount, it.currency),
                        method = it.type,
                        status = it.status,
                        createdAt = it.created?.let(::formatEpochSeconds) ?: "date unavailable"
                    )
                    TransactionDisplayItem(
                        label = it.toString(),
                        source = "stripe",
                        action = it.type,
                        result = it.status,
                        receipt = receipt
                    )
                }
            )
        }

        fun applyFilter(filter: String) {
            visibleDisplayItems = currentDisplayItems()
                .filter { transactionMatchesFilter(it, filter) }
            selectedReceipt = null
            selectedReceiptText.text = "Select a transaction to resend a receipt"

            transactionsAdapter.clear()
            transactionsAdapter.addAll(visibleDisplayItems.map { it.label }.ifEmpty { listOf("No matching transactions") })
            transactionsAdapter.notifyDataSetChanged()
            expandListToContent(transactionsList)
        }

        val filterSpinner = filterDropdown(filterOptions) { selectedFilter ->
            applyFilter(selectedFilter)
        }
        root.addView(TextView(this).withText("Filter"))
        root.addView(filterSpinner)
        root.addView(menuButton("Refresh Stripe Recent Transactions") {
            runNetworkAction("Loading recent Stripe transactions...") {
                stripeItems = apiClient.fetchRecentTransactions()
                runOnUiThread {
                    applyFilter(filterSpinner.selectedItem?.toString() ?: "All")
                    onStatus("Loaded recent transaction history")
                }
            }
        })
        root.addView(selectedReceiptText)
        root.addView(menuButton("Resend Receipt") {
            val receipt = selectedReceipt
            if (receipt == null) {
                Toast.makeText(this, "Select a transaction first", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            showReceiptOptionsScreen(receipt)
        })
        root.addView(transactionsList, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        applyFilter("All")
        setScrollableContent(root)
    }

    private fun checkoutCartItem(item: CartItem) {
        if (!AppConfig.stripePublishableKey.startsWith("pk_test_") && !AppConfig.stripePublishableKey.startsWith("pk_live_")) {
            onPaymentFailed("Stripe publishable key is missing or invalid")
            return
        }

        pendingCheckoutItem = item
        when (item) {
            is CartItem.Recurring -> {
                runNetworkAction("Opening Stripe card payment page...") {
                    val setupIntent = apiClient.createSetupIntent(item.customer.id)
                    runOnUiThread {
                        pendingSetupIntentId = setupIntent.id
                        presentSetupIntent(setupIntent.clientSecret)
                    }
                }
            }
            is CartItem.OneTime -> {
                runNetworkAction("Opening Stripe card payment page...") {
                    val paymentIntent = apiClient.createCardPaymentIntent(
                        amount = item.amount,
                        currency = item.currency,
                        customerId = item.customer.id,
                        description = item.name
                    )
                    runOnUiThread {
                        pendingPaymentIntentId = paymentIntent.id
                        presentPaymentIntent(paymentIntent.clientSecret)
                    }
                }
            }
        }
    }

    private fun presentSetupIntent(clientSecret: String) {
        try {
            paymentSheet.presentWithSetupIntent(
                clientSecret,
                PaymentSheet.Configuration(merchantDisplayName = "Bloom MMA")
            )
        } catch (error: Exception) {
            onPaymentFailed("Could not open checkout: ${error.message}")
        }
    }

    private fun presentPaymentIntent(clientSecret: String) {
        try {
            paymentSheet.presentWithPaymentIntent(
                clientSecret,
                PaymentSheet.Configuration(merchantDisplayName = "Bloom MMA")
            )
        } catch (error: Exception) {
            onPaymentFailed("Could not open checkout: ${error.message}")
        }
    }

    private fun onSetupPaymentSheetResult(result: PaymentSheetResult) {
        when (result) {
            is PaymentSheetResult.Completed -> {
                val item = pendingCheckoutItem
                val setupIntentId = pendingSetupIntentId
                val paymentIntentId = pendingPaymentIntentId
                pendingCheckoutItem = null
                pendingSetupIntentId = null
                pendingPaymentIntentId = null
                if (item == null) {
                    onPaymentFailed("Checkout selection was lost")
                    return
                }

                when (item) {
                    is CartItem.Recurring -> {
                        if (setupIntentId == null) {
                            onPaymentFailed("Checkout setup was lost")
                            return
                        }
                        runNetworkAction("Card saved. Creating paid subscription...") {
                            apiClient.setDefaultPaymentMethodFromSetupIntent(setupIntentId)
                            val subscription = apiClient.createSubscription(item.customer.id, item.plan.id)
                            runOnUiThread {
                                val receipt = receiptForCartItem(item, "Card checkout", subscription.status)
                                cartItems.remove(item)
                                recentAppTransactions.add(
                                    AppTransaction("checkout", item.customer.toString(), item.plan.name, subscription.status, receipt = receipt)
                                )
                                showPaymentComplete(receipt)
                            }
                        }
                    }
                    is CartItem.OneTime -> {
                        val receipt = receiptForCartItem(item, "Card checkout", "succeeded")
                        cartItems.remove(item)
                        recentAppTransactions.add(
                            AppTransaction("checkout", item.customer.toString(), item.name, "succeeded ${paymentIntentId ?: ""}".trim(), receipt = receipt)
                        )
                        showPaymentComplete(receipt)
                    }
                }
            }
            is PaymentSheetResult.Canceled -> {
                pendingCheckoutItem?.let {
                    recentAppTransactions.add(AppTransaction("checkout", it.customer.toString(), cartItemName(it), "canceled"))
                }
                pendingCheckoutItem = null
                pendingSetupIntentId = null
                pendingPaymentIntentId = null
                onStatus("Checkout canceled")
            }
            is PaymentSheetResult.Failed -> {
                pendingCheckoutItem?.let {
                    recentAppTransactions.add(AppTransaction("checkout", it.customer.toString(), cartItemName(it), "failed: ${result.error.localizedMessage}"))
                }
                pendingCheckoutItem = null
                pendingSetupIntentId = null
                pendingPaymentIntentId = null
                onPaymentFailed("Checkout failed: ${result.error.localizedMessage}")
            }
        }
    }

    private fun showThankYou() {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
            background = borderedBackground()
        }
        root.addView(TextView(this).apply {
            text = "Thank You"
            textSize = 32f
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, 160, 0, 0)
        })
        setInsetContentView(root)
        handler.postDelayed({
            clearTakePaymentSelection()
            showMainMenu()
        }, 2_000)
    }

    private fun showPaymentComplete(receipt: ReceiptDetails? = null) {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
            background = borderedBackground()
        }
        root.addView(TextView(this).apply {
            text = "Payment Complete"
            textSize = 32f
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, 160, 0, 0)
        })
        if (receipt != null) {
            root.addView(TextView(this).withText(receiptSummary(receipt)))
            root.addView(menuButton("Receipt Options") {
                showReceiptOptionsScreen(receipt)
            })
            root.addView(menuButton("Done") {
                clearTakePaymentSelection()
                showMainMenu()
            })
        }
        setInsetContentView(root)
        if (receipt == null) {
            handler.postDelayed({
                clearTakePaymentSelection()
                showMainMenu()
            }, 2_000)
        }
    }

    private fun showReceiptOptionsScreen(receipt: ReceiptDetails) {
        val root = screenRoot("Receipt Options")
        root.addView(statusLabel("Choose receipt method"))
        root.addView(selectionPanel(receiptSummary(receipt)))
        root.addView(menuButton("Print Receipt") {
            showReceiptConfirmation(receipt, ReceiptAction.Print)
        })
        root.addView(menuButton("Email Receipt") {
            showEmailReceiptScreen(receipt)
        })
        root.addView(menuButton("Text Receipt") {
            showTextReceiptScreen(receipt)
        })
        root.addView(menuButton("No Receipt") {
            clearTakePaymentSelection()
            showMainMenu()
        })
        root.addView(menuButton("Back") {
            showPaymentComplete(receipt)
        })
        setScrollableContent(root)
    }

    private fun showEmailReceiptScreen(receipt: ReceiptDetails) {
        val root = screenRoot("Email Receipt")
        root.addView(statusLabel("Select or enter receipt email"))
        root.addView(selectionPanel(receiptSummary(receipt)))
        receipt.customerEmail?.takeIf { it.isNotBlank() }?.let { email ->
            root.addView(TextView(this).withText("Email on file: $email"))
            root.addView(menuButton("Use Email on File") {
                showReceiptConfirmation(receipt, ReceiptAction.Email(email))
            })
        }
        val emailInput = input("Enter receipt email")
        root.addView(emailInput)
        root.addView(menuButton("Continue") {
            val email = emailInput.text.toString().trim()
            if (!email.contains("@") || !email.contains(".")) {
                Toast.makeText(this, "Enter a valid email", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            showReceiptConfirmation(receipt, ReceiptAction.Email(email))
        })
        root.addView(menuButton("Back to Receipt Options") {
            showReceiptOptionsScreen(receipt)
        })
        setScrollableContent(root)
    }

    private fun showTextReceiptScreen(receipt: ReceiptDetails) {
        val root = screenRoot("Text Receipt")
        root.addView(statusLabel("Enter USA mobile number"))
        root.addView(selectionPanel(receiptSummary(receipt)))
        val phoneInput = input("Phone number")
        phoneInput.setText(receipt.customerPhone.orEmpty())
        root.addView(phoneInput)
        root.addView(menuButton("Continue") {
            val phone = normalizeUsPhone(phoneInput.text.toString())
            if (phone == null) {
                Toast.makeText(this, "Enter a valid USA phone number", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            showReceiptConfirmation(receipt, ReceiptAction.Text(phone))
        })
        root.addView(menuButton("Back to Receipt Options") {
            showReceiptOptionsScreen(receipt)
        })
        setScrollableContent(root)
    }

    private fun showReceiptConfirmation(receipt: ReceiptDetails, action: ReceiptAction) {
        val root = screenRoot("Confirm Receipt")
        root.addView(statusLabel("Review before sending"))
        root.addView(selectionPanel("${receiptSummary(receipt)}\n\n${receiptActionSummary(action)}"))
        root.addView(menuButton("Confirm") {
            performReceiptAction(receipt, action)
        })
        root.addView(menuButton("Change Receipt Method") {
            showReceiptOptionsScreen(receipt)
        })
        setScrollableContent(root)
    }

    private fun performReceiptAction(receipt: ReceiptDetails, action: ReceiptAction) {
        when (action) {
            is ReceiptAction.Print -> printReceipt(receipt)
            is ReceiptAction.Email -> emailReceipt(receipt, action.email)
            is ReceiptAction.Text -> textReceipt(receipt, action.phone)
        }
    }

    private fun emailReceipt(receipt: ReceiptDetails, email: String) {
        val intent = Intent(Intent.ACTION_SENDTO).apply {
            data = Uri.parse("mailto:")
            putExtra(Intent.EXTRA_EMAIL, arrayOf(email))
            putExtra(Intent.EXTRA_SUBJECT, "Bloom MMA receipt")
            putExtra(Intent.EXTRA_TEXT, receiptBody(receipt))
        }
        try {
            startActivity(intent)
            onStatus("Email receipt opened")
        } catch (_: ActivityNotFoundException) {
            onPaymentFailed("No email app is available on this device")
        }
    }

    private fun textReceipt(receipt: ReceiptDetails, phone: String) {
        val intent = Intent(Intent.ACTION_SENDTO).apply {
            data = Uri.parse("smsto:$phone")
            putExtra("sms_body", receiptBody(receipt))
        }
        try {
            startActivity(intent)
            onStatus("Text receipt opened")
        } catch (_: ActivityNotFoundException) {
            onPaymentFailed("No text messaging app is available on this device")
        }
    }

    private fun printReceipt(receipt: ReceiptDetails) {
        val webView = WebView(this)
        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                val printManager = getSystemService(Context.PRINT_SERVICE) as PrintManager
                printManager.print(
                    "Bloom MMA Receipt",
                    webView.createPrintDocumentAdapter("Bloom MMA Receipt"),
                    PrintAttributes.Builder().build()
                )
                onStatus("Choose a printer")
            }
        }
        webView.loadDataWithBaseURL(null, receiptHtml(receipt), "text/html", "UTF-8", null)
    }

    private fun receiptForCartItem(item: CartItem, method: String, status: String): ReceiptDetails {
        return when (item) {
            is CartItem.Recurring -> ReceiptDetails(
                customerName = item.customer.name,
                customerEmail = item.customer.email,
                customerPhone = item.customer.phone,
                product = item.plan.name,
                amount = formatMinorAmount(item.plan.unitAmount, item.plan.currency),
                method = method,
                status = status
            )
            is CartItem.OneTime -> ReceiptDetails(
                customerName = item.customer.name,
                customerEmail = item.customer.email,
                customerPhone = item.customer.phone,
                product = item.name,
                amount = formatMinorAmount(item.amount, item.currency),
                method = method,
                status = status
            )
        }
    }

    private fun receiptSummary(receipt: ReceiptDetails): String {
        return listOf(
            "Customer: ${receipt.customerName ?: receipt.customerEmail ?: "Customer"}",
            "Product: ${receipt.product}",
            "Amount: ${receipt.amount}",
            "Method: ${receipt.method}",
            "Status: ${receipt.status}",
            "Date: ${receipt.createdAt}"
        ).joinToString("\n")
    }

    private fun receiptBody(receipt: ReceiptDetails): String {
        return "Bloom MMA Receipt\n\n${receiptSummary(receipt)}\n\nThank you."
    }

    private fun receiptHtml(receipt: ReceiptDetails): String {
        return """
            <html>
            <body style="font-family: sans-serif; padding: 24px;">
                <h1>Bloom MMA Receipt</h1>
                <pre style="font-size: 16px;">${escapeHtml(receiptSummary(receipt))}</pre>
                <p>Thank you.</p>
            </body>
            </html>
        """.trimIndent()
    }

    private fun receiptActionSummary(action: ReceiptAction): String {
        return when (action) {
            is ReceiptAction.Print -> "Action: Print receipt\nPrinter: Select in Android print window"
            is ReceiptAction.Email -> "Action: Email receipt\nTo: ${action.email}"
            is ReceiptAction.Text -> "Action: Text receipt\nTo: ${action.phone}"
        }
    }

    private fun normalizeUsPhone(value: String): String? {
        val digits = value.filter(Char::isDigit)
        val national = when {
            digits.length == 10 -> digits
            digits.length == 11 && digits.startsWith("1") -> digits.drop(1)
            else -> return null
        }
        return "+1$national"
    }

    private fun escapeHtml(value: String): String {
        return value
            .replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
    }

    private fun bucketText(): String {
        val customer = selectedCustomer?.toString() ?: "No customer selected"
        val plan = selectedPlan
        val product = if (plan == null) {
            "No product selected"
        } else {
            val amount = formatMinorAmount(plan.unitAmount, plan.currency)
            val interval = plan.interval ?: "one time"
            "${plan.name}\nType: ${plan.typeLabel()}\nInterval: $interval\nPaid today: $amount"
        }
        return "Current Order\nCustomer: $customer\nProduct: $product"
    }

    private fun productLabel(plan: MembershipPlan): String {
        return "${plan.name} | ${plan.typeLabel()} | ${formatMinorAmount(plan.unitAmount, plan.currency)}${plan.interval?.let { " / $it" }.orEmpty()}"
    }

    private fun filteredProducts(searchText: String, typeFilter: String): List<MembershipPlan> {
        val normalizedSearch = searchText.trim()
        return loadedPlans.filter { plan ->
            val matchesText = normalizedSearch.isBlank() ||
                plan.name.contains(normalizedSearch, ignoreCase = true) ||
                (plan.description ?: "").contains(normalizedSearch, ignoreCase = true)

            val matchesType = when (typeFilter) {
                "One-time" -> !plan.isRecurring()
                "Recurring" -> plan.isRecurring()
                else -> true
            }

            matchesText && matchesType
        }
    }

    private fun applyProductFilter(
        adapter: ArrayAdapter<String>,
        listView: ListView,
        searchText: String,
        typeFilter: String
    ): List<MembershipPlan> {
        val filtered = filteredProducts(searchText, typeFilter)
        adapter.clear()
        adapter.addAll(filtered.map(::productLabel).ifEmpty { listOf("No products found") })
        adapter.notifyDataSetChanged()
        expandListToContent(listView)
        return filtered
    }

    private fun cartLabel(item: CartItem): String {
        return when (item) {
            is CartItem.Recurring -> {
                val plan = item.plan
                "${item.customer}\n${plan.name} | recurring | ${formatMinorAmount(plan.unitAmount, plan.currency)}${plan.interval?.let { " / $it" }.orEmpty()} | checkout today"
            }
            is CartItem.OneTime -> {
                "${item.customer}\n${item.name} | one time | ${formatMinorAmount(item.amount, item.currency)} | checkout today"
            }
        }
    }

    private fun cartItemName(item: CartItem): String {
        return when (item) {
            is CartItem.Recurring -> item.plan.name
            is CartItem.OneTime -> item.name
        }
    }

    private fun connectedReaderDisplayText(): String {
        return "Connected reader\n${connectedReaderLabel ?: "No reader connected"}"
    }

    private fun parseDollarAmountToCents(value: String): Int? {
        val normalized = value.trim().replace("$", "")
        val dollars = normalized.toDoubleOrNull() ?: return null
        return Math.round(dollars * 100).toInt()
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
            if (::statusText.isInitialized) {
                statusText.text = message
            }
        }
    }

    override fun onReadersUpdated(readers: List<Reader>) {
        runOnUiThread {
            discoveredReaders = readers
            readersAdapter?.clear()
            readersAdapter?.addAll(readers.map { "${it.serialNumber ?: "Unknown serial"} ${it.deviceType}" })
            readersAdapter?.notifyDataSetChanged()
            readersListView?.let(::expandListToContent)
        }
    }

    override fun onReaderConnected(reader: Reader) {
        connectedReaderLabel = "${reader.serialNumber ?: "reader"} ${reader.deviceType}"
        runOnUiThread {
            connectedReaderText?.text = connectedReaderDisplayText()
        }
        onStatus("Connected: ${reader.serialNumber ?: "reader"}")
    }

    override fun onReaderDisconnected() {
        connectedReaderLabel = null
        runOnUiThread {
            connectedReaderText?.text = connectedReaderDisplayText()
        }
    }

    override fun onPaymentSucceeded(message: String) {
        onStatus(message)
        runOnUiThread {
            val readerItem = pendingReaderCheckoutItem
            if (readerItem != null) {
                pendingReaderCheckoutItem = null
                readerPaymentDialog?.dismiss()
                readerPaymentDialog = null
                cartItems.remove(readerItem)
                val receipt = receiptForCartItem(readerItem, "Card reader", "succeeded")
                recentAppTransactions.add(
                    AppTransaction("terminal", readerItem.customer.toString(), readerItem.name, "succeeded", receipt = receipt)
                )
                showTransactionSuccessfulDialog(receipt)
                return@runOnUiThread
            }

            recentAppTransactions.add(AppTransaction("terminal", selectedCustomer?.toString() ?: "No customer", "Card-present payment", "succeeded"))
            Toast.makeText(this, message, Toast.LENGTH_LONG).show()
        }
    }

    private fun showTransactionSuccessfulDialog(receipt: ReceiptDetails) {
        AlertDialog.Builder(this)
            .setTitle("Transaction Successful")
            .setMessage("Payment complete.")
            .setCancelable(false)
            .setPositiveButton("OK") { dialog, _ ->
                dialog.dismiss()
                showPaymentComplete(receipt)
            }
            .show()
    }

    override fun onPaymentFailed(message: String) {
        onStatus(message)
        runOnUiThread {
            if (pendingReaderCheckoutItem != null) {
                recentAppTransactions.add(
                    AppTransaction("terminal", pendingReaderCheckoutItem?.customer?.toString() ?: "No customer", pendingReaderCheckoutItem?.name ?: "Card-present payment", "failed")
                )
                pendingReaderCheckoutItem = null
                readerPaymentDialog?.dismiss()
                readerPaymentDialog = null
            }
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

    private fun clearTakePaymentSelection() {
        selectedCustomer = null
        selectedPlan = null
        foundCustomers = emptyList()
        loadedPlans = emptyList()
    }

    private fun screenRoot(title: String): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(32, 32, 32, 32)
            addView(TextView(this@MainActivity).apply {
                text = title
                textSize = 26f
                setTypeface(typeface, Typeface.BOLD)
                setPadding(0, 0, 0, 20)
            })
        }
    }

    private fun setScrollableContent(root: LinearLayout) {
        val frame = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(18, 42, 18, 18)
            background = borderedBackground()
            addView(ScrollView(this@MainActivity).apply { addView(root) })
        }
        setInsetContentView(frame)
    }

    private fun setInsetContentView(content: View) {
        val outer = FrameLayout(this).apply {
            setBackgroundColor(Color.rgb(246, 248, 250))
            setPadding(dp(7), dp(8), dp(7), dp(8))
            addView(
                content,
                FrameLayout.LayoutParams(
                    FrameLayout.LayoutParams.MATCH_PARENT,
                    FrameLayout.LayoutParams.MATCH_PARENT
                )
            )
        }
        setContentView(outer)
    }

    private fun statusLabel(value: String): TextView {
        statusText = TextView(this).apply {
            text = value
            textSize = 16f
            typeface = Typeface.create("sans-serif", Typeface.NORMAL)
            setTextColor(Color.rgb(28, 38, 50))
            setPadding(0, 0, 0, 12)
        }
        return statusText
    }

    private fun sectionTitle(text: String): TextView {
        return TextView(this).apply {
            this.text = text
            textSize = 20f
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, 28, 0, 8)
        }
    }

    private fun selectionPanel(initialText: String): TextView {
        return TextView(this).apply {
            text = initialText
            textSize = 16f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.rgb(28, 38, 50))
            setPadding(24, 18, 24, 18)
            background = GradientDrawable().apply {
                cornerRadius = 12f
                setColor(Color.rgb(232, 244, 255))
                setStroke(3, Color.rgb(46, 125, 190))
            }
        }
    }

    private fun input(hint: String): TextInputEditText {
        return TextInputEditText(this).apply {
            this.hint = hint
            textSize = 16f
            typeface = Typeface.create("sans-serif", Typeface.NORMAL)
            setTextColor(Color.rgb(28, 38, 50))
            setHintTextColor(Color.rgb(96, 111, 128))
            setPadding(dp(14), 0, dp(14), 0)
            setSingleLine(true)
            minHeight = dp(52)
            background = GradientDrawable().apply {
                cornerRadius = dp(8).toFloat()
                setColor(Color.WHITE)
                setStroke(dp(1), Color.rgb(170, 184, 200))
            }
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp(56)
            ).apply {
                topMargin = dp(6)
                bottomMargin = dp(8)
            }
        }
    }

    private fun multilineAdapter(items: MutableList<String> = mutableListOf()): ArrayAdapter<String> {
        return object : ArrayAdapter<String>(this, android.R.layout.simple_list_item_1, items) {
            override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
                val view = super.getView(position, convertView, parent)
                (view as? TextView)?.apply {
                    isSingleLine = false
                    maxLines = Int.MAX_VALUE
                    textSize = 15f
                    typeface = Typeface.create("sans-serif", Typeface.NORMAL)
                    setTextColor(Color.rgb(28, 38, 50))
                    setPadding(18, 14, 18, 14)
                }
                return view
            }
        }
    }

    private fun filterDropdown(options: List<String>, onSelected: (String) -> Unit): Spinner {
        val dropdownAdapter = ArrayAdapter(this, android.R.layout.simple_spinner_item, options).apply {
            setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
        }
        return Spinner(this).apply {
            adapter = dropdownAdapter
            onItemSelectedListener = object : android.widget.AdapterView.OnItemSelectedListener {
                override fun onItemSelected(
                    parent: android.widget.AdapterView<*>?,
                    view: View?,
                    position: Int,
                    id: Long
                ) {
                    onSelected(options[position])
                }

                override fun onNothingSelected(parent: android.widget.AdapterView<*>?) = Unit
            }
        }
    }

    private fun transactionMatchesFilter(item: TransactionDisplayItem, filter: String): Boolean {
        return when (filter) {
            "Succeeded" -> item.result.contains("succeeded", ignoreCase = true)
            "Failed" -> item.result.contains("failed", ignoreCase = true)
            "Canceled" -> item.result.contains("canceled", ignoreCase = true)
            "Added to cart" -> item.result.contains("added_to_cart", ignoreCase = true)
            "Checkout" -> item.action.contains("checkout", ignoreCase = true)
            "Terminal" -> item.action.contains("terminal", ignoreCase = true)
            "App only" -> item.source == "app"
            "Stripe only" -> item.source == "stripe"
            else -> true
        }
    }

    private fun expandListToContent(listView: ListView) {
        val adapter = listView.adapter ?: return
        val width = (resources.displayMetrics.widthPixels - dp(96)).coerceAtLeast(dp(240))
        val widthSpec = View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.AT_MOST)
        var totalHeight = listView.paddingTop + listView.paddingBottom

        for (index in 0 until adapter.count) {
            val itemView = adapter.getView(index, null, listView)
            itemView.measure(widthSpec, View.MeasureSpec.UNSPECIFIED)
            totalHeight += itemView.measuredHeight
        }

        totalHeight += listView.dividerHeight * (adapter.count - 1).coerceAtLeast(0)
        val params = listView.layoutParams ?: LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.WRAP_CONTENT
        )
        params.height = totalHeight.coerceAtLeast(dp(56))
        listView.layoutParams = params
        listView.requestLayout()
    }

    private fun dp(value: Int): Int {
        return (value * resources.displayMetrics.density).toInt()
    }

    private fun buttonRow(vararg buttons: Button): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            buttons.forEach { button ->
                addView(button, twoColumnButtonParams())
            }
        }
    }

    private fun twoColumnButtonParams(): LinearLayout.LayoutParams {
        return LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f).apply {
            setMargins(dp(3), dp(5), dp(3), dp(5))
        }
    }

    private fun compactButton(text: String, action: () -> Unit): Button {
        return Button(this).apply {
            this.text = text
            setAllCaps(false)
            textSize = 13f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.WHITE)
            minHeight = dp(42)
            background = ContextCompat.getDrawable(this@MainActivity, R.drawable.button_background)
            setPadding(dp(8), 0, dp(8), 0)
            setOnClickListener { action() }
        }
    }

    private fun menuButton(text: String, action: () -> Unit): Button {
        return Button(this).apply {
            this.text = text
            setAllCaps(false)
            textSize = 15f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.WHITE)
            minHeight = dp(48)
            background = ContextCompat.getDrawable(this@MainActivity, R.drawable.button_background)
            setPadding(dp(16), 0, dp(16), 0)
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            ).apply {
                setMargins(0, dp(6), 0, dp(6))
            }
            setOnClickListener { action() }
        }
    }

    private fun backButton(): Button = menuButton("Back to Main Screen") {
        clearTakePaymentSelection()
        showMainMenu()
    }

    private fun borderedBackground(): GradientDrawable {
        return GradientDrawable().apply {
            setColor(Color.WHITE)
            cornerRadius = dp(18).toFloat()
            setStroke(5, Color.rgb(46, 125, 190))
        }
    }

    private fun TextView.withText(value: String): TextView {
        text = value
        return this
    }

    private fun installCrashLogger() {
        val previousHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, error ->
            getSharedPreferences(CRASH_PREFS, MODE_PRIVATE)
                .edit()
                .putString(LAST_CRASH_KEY, error.stackTraceToString())
                .apply()
            Log.e(TAG, "Unhandled crash", error)
            previousHandler?.uncaughtException(thread, error)
        }
    }

    private fun showPreviousCrashIfPresent() {
        val prefs = getSharedPreferences(CRASH_PREFS, MODE_PRIVATE)
        val previousCrash = prefs.getString(LAST_CRASH_KEY, null) ?: return
        prefs.edit().remove(LAST_CRASH_KEY).apply()
        val firstLine = previousCrash.lineSequence().firstOrNull().orEmpty()
        Log.e(TAG, "Previous crash:\n$previousCrash")
        onStatus("Previous app crash: $firstLine")
        Toast.makeText(this, "Previous app crash: $firstLine", Toast.LENGTH_LONG).show()
    }

    sealed class CartItem {
        abstract val customer: CustomerSummary

        data class Recurring(
            override val customer: CustomerSummary,
            val plan: MembershipPlan
        ) : CartItem()

        data class OneTime(
            override val customer: CustomerSummary,
            val name: String,
            val amount: Int,
            val currency: String
        ) : CartItem()
    }

    data class ReceiptDetails(
        val customerName: String?,
        val customerEmail: String?,
        val customerPhone: String?,
        val product: String,
        val amount: String,
        val method: String,
        val status: String,
        val createdAt: String = formatEpochSeconds(System.currentTimeMillis() / 1000)
    )

    sealed class ReceiptAction {
        data object Print : ReceiptAction()
        data class Email(val email: String) : ReceiptAction()
        data class Text(val phone: String) : ReceiptAction()
    }

    data class AppTransaction(
        val action: String,
        val customer: String,
        val product: String,
        val result: String,
        val createdAt: String = formatEpochSeconds(System.currentTimeMillis() / 1000),
        val receipt: ReceiptDetails? = null
    ) {
        override fun toString(): String {
            return "$createdAt | $action | $customer | $product | $result"
        }
    }

    data class TransactionDisplayItem(
        val label: String,
        val source: String,
        val action: String,
        val result: String,
        val receipt: ReceiptDetails? = null
    )

    companion object {
        private const val TAG = "StripePos"
        private const val CRASH_PREFS = "stripe_pos_crashes"
        private const val LAST_CRASH_KEY = "last_crash"
        private const val REQUEST_CODE_PERMISSIONS = 1001
    }
}
