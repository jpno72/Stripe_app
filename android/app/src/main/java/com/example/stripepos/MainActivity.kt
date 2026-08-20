package com.example.stripepos

import android.Manifest
import android.app.AlertDialog
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.graphics.pdf.PdfDocument
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.print.PrintAttributes
import android.print.PrintManager
import android.util.Log
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ListView
import android.widget.RadioButton
import android.widget.RadioGroup
import android.widget.ScrollView
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
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
import java.io.File
import java.io.FileOutputStream

class MainActivity : AppCompatActivity(), TerminalManager.UiListener {
    private lateinit var terminalManager: TerminalManager
    private lateinit var paymentSheet: PaymentSheet
    private val apiClient = ApiClient()
    private val handler = Handler(Looper.getMainLooper())
    private val screenBackStack = mutableListOf<() -> Unit>()
    private var currentScreenAction: (() -> Unit)? = null
    private var restoringScreen = false

    private lateinit var statusText: TextView
    private var statusRow: View? = null
    private var connectedReaderText: TextView? = null
    private var readersListView: ListView? = null
    private var readersAdapter: ArrayAdapter<String>? = null
    private var discoveredReaders: List<Reader> = emptyList()
    private var showReaderResultsPopup = false
    private var readerSelectionDialog: AlertDialog? = null
    private var connectedReaderLabel: String? = null

    private var selectedCustomer: CustomerSummary? = null
    private var selectedPlan: MembershipPlan? = null
    private var foundCustomers: List<CustomerSummary> = emptyList()
    private var loadedPlans: List<MembershipPlan> = emptyList()
    private val cartItems = mutableListOf<CartItem>()
    private val recentAppTransactions = mutableListOf<AppTransaction>()
    private var cartDiscount: CartDiscount? = null

    private var pendingCheckoutItem: CartItem? = null
    private var pendingCheckoutCartItems: List<CartItem> = emptyList()
    private var pendingSetupIntentId: String? = null
    private var pendingPaymentIntentId: String? = null
    private var pendingReaderCheckoutItem: CartItem.OneTime? = null
    private var pendingReaderConnectionItem: CartItem.OneTime? = null
    private var pendingReaderConnectionCartItems: List<CartItem> = emptyList()
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
        screenBackStack.clear()
        currentScreenAction = { showMainMenu() }
        setContentView(R.layout.screen_main_menu)
        applySystemBarPaddingToContent()
        findViewById<TextView>(R.id.mainBackendText).text = "Backend: ${AppConfig.backendBaseUrl}"
        statusText = findViewById<TextView>(R.id.mainStatusText).apply {
            text = ""
            visibility = View.GONE
        }
        statusRow = findViewById(R.id.mainStatusRow)
        findViewById<Button>(R.id.ConfigurationButton).setOnClickListener { showTestingScreen() }
        findViewById<Button>(R.id.ConfigurationButton).applyButtonBackground(R.drawable.button_background_navy)
        findViewById<Button>(R.id.singlePaymentShortcutButton).apply {
            setOnClickListener { showTakePaymentScreen(PaymentEntryMode.SINGLE) }
            applyButtonBackground(R.drawable.button_background_payment_pair_single)
        }
        findViewById<Button>(R.id.productPaymentShortcutButton).apply {
            setOnClickListener { showTakePaymentScreen(PaymentEntryMode.PRODUCT) }
            applyButtonBackground(R.drawable.button_background_payment_pair_product)
        }
        findViewById<Button>(R.id.cartButton).apply {
            text = "Cart (${cartItems.size})"
            setOnClickListener { showCartScreen() }
            applyButtonBackground(R.drawable.button_background_green)
        }
        findViewById<Button>(R.id.showActiveCustomersButton).apply {
            setOnClickListener { loadActiveCustomersReport() }
            applyButtonBackground(R.drawable.button_background)
        }
        findViewById<Button>(R.id.showActiveSubscriptionsButton).apply {
            setOnClickListener { loadActiveSubscriptionsReport() }
            applyButtonBackground(R.drawable.button_background)
        }
        findViewById<Button>(R.id.recentAppTransactionsButton).apply {
            setOnClickListener { showRecentTransactionsScreen() }
            applyButtonBackground(R.drawable.button_background)
        }
    }

    private fun showTestingScreen() {
        beginScreen { showTestingScreen() }
        setContentView(R.layout.screen_testing_configuration)
        applySystemBarPaddingToContent()
        findViewById<LinearLayout>(R.id.testingContent).addView(centeredScreenHeader("Testing and Configuration"), 0)
        findViewById<TextView>(R.id.testingTitle).visibility = View.GONE
        findViewById<Button>(R.id.testingBackButton).apply {
            applyBackHomeButtonStyle()
            setOnClickListener {
                clearTakePaymentSelection()
                showMainMenu()
            }
        }
        findViewById<TextView>(R.id.testingSimulatedReaderText).text =
            "Simulated reader mode: ${AppConfig.useSimulatedReader}"
        statusText = findViewById<TextView>(R.id.testingStatusText).apply {
            text = ""
            visibility = View.GONE
        }
        statusRow = findViewById(R.id.testingStatusRow)

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
            showReaderResultsPopup = false
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
        beginScreen { showReportingScreen() }
        val root = screenRoot("Reporting")
        root.addView(backButton())
        root.addView(statusLabel(""))

        root.addView(menuButton("Show Active Customers") { loadActiveCustomersReport() })
        root.addView(menuButton("Show Active Subscriptions") { loadActiveSubscriptionsReport() })
        root.addView(menuButton("Recent App Transactions") {
            showRecentTransactionsScreen()
        })
        setScrollableContent(root)
    }

    private fun loadActiveCustomersReport() {
        runNetworkAction("Loading active customers...") {
            val customers = apiClient.fetchActiveCustomers()
            runOnUiThread {
                showInfoPopup(
                    "Active Customers",
                    customers.map { it.toString() }.ifEmpty { listOf("No active customers found") }
                )
                onStatus("Loaded ${customers.size} active customer(s)")
            }
        }
    }

    private fun loadActiveSubscriptionsReport() {
        runNetworkAction("Loading active subscriptions...") {
            val subscriptions = apiClient.fetchActiveSubscriptions()
            runOnUiThread {
                showInfoPopup(
                    "Active Subscriptions",
                    subscriptions.map { it.toString() }.ifEmpty { listOf("No active subscriptions found") }
                )
                onStatus("Loaded ${subscriptions.size} active subscription(s)")
            }
        }
    }

    private fun showTakePaymentScreen(initialMode: PaymentEntryMode? = null) {
        beginScreen { showTakePaymentScreen(initialMode) }
        val screenTitle = when (initialMode) {
            PaymentEntryMode.SINGLE -> "Single Payment"
            PaymentEntryMode.PRODUCT -> "Product Payment"
            null -> "Take Payment"
        }
        val statusMessage = when (initialMode) {
            PaymentEntryMode.SINGLE -> "Enter a one-time payment"
            PaymentEntryMode.PRODUCT -> "Select a customer and product"
            null -> "Select a customer and product"
        }
        val root = screenRoot(screenTitle)
        root.addView(statusLabel(statusMessage))

        val bucketText = selectionPanel(bucketText())
        root.addView(bucketText)
        root.addView(
            buttonRow(
                compactButton("Back to Main Screen") {
                    clearTakePaymentSelection()
                    showMainMenu()
                }.apply { applyBackHomeButtonStyle() },
                compactButton("Go to Checkout") {
                    clearTakePaymentSelection()
                    showCartScreen()
                }
            )
        )

        val customerChoiceSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        val customerLookupSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        val newCustomerSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        var addProductCustomerButton: Button? = null
        var selectedProductTypeFilter = "One-time"
        var loadSelectedProducts: (() -> Unit)? = null
        var pendingSubscriptionPlan: MembershipPlan? = null

        lateinit var lookupToggleButton: Button
        lateinit var newCustomerToggleButton: Button
        fun showProductSearchControlsIfCustomerReady() {
        }
        fun resetCustomerSubsections() {
            lookupToggleButton.visibility = View.VISIBLE
            newCustomerToggleButton.visibility = View.VISIBLE
            customerLookupSection.visibility = View.GONE
            newCustomerSection.visibility = View.GONE
        }
        fun toggleCustomerChoiceSection() {
            customerChoiceSection.visibility =
                if (customerChoiceSection.visibility == View.VISIBLE) View.GONE else View.VISIBLE
            resetCustomerSubsections()
        }
        fun showOnlyCustomerLookup() {
            lookupToggleButton.visibility = View.VISIBLE
            lookupToggleButton.applyButtonBackground(R.drawable.button_background_navy)
            newCustomerToggleButton.visibility = View.GONE
            customerLookupSection.visibility = View.VISIBLE
            newCustomerSection.visibility = View.GONE
        }
        fun showOnlyNewCustomer() {
            lookupToggleButton.visibility = View.GONE
            newCustomerToggleButton.visibility = View.VISIBLE
            newCustomerToggleButton.applyButtonBackground(R.drawable.button_background_gold)
            customerLookupSection.visibility = View.GONE
            newCustomerSection.visibility = View.VISIBLE
        }

        lookupToggleButton = compactButton("Customer Lookup") {
            if (customerLookupSection.visibility == View.VISIBLE) {
                resetCustomerSubsections()
            } else {
                showOnlyCustomerLookup()
            }
        }
        newCustomerToggleButton = compactButton("New Customer") {
            if (newCustomerSection.visibility == View.VISIBLE) {
                resetCustomerSubsections()
            } else {
                showOnlyNewCustomer()
            }
        }
        val customerToggleRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
        }
        customerToggleRow.addView(lookupToggleButton, twoColumnButtonParams())
        customerToggleRow.addView(newCustomerToggleButton, twoColumnButtonParams())
        customerChoiceSection.addView(customerToggleRow)

        val customerSearchInput = input("Search name, email, or phone")
        customerLookupSection.addView(customerSearchInput)
        customerLookupSection.addView(menuButton("Search Customers") {
            runNetworkAction("Searching customers...") {
                foundCustomers = apiClient.searchCustomers(customerSearchInput.text.toString())
                runOnUiThread {
                    showSelectionPopup(
                        title = "Select Customer",
                        labels = foundCustomers.map(CustomerSummary::toString).ifEmpty { listOf("No customers found") }
                    ) { position, dialog ->
                        foundCustomers.getOrNull(position)?.let {
                            selectedCustomer = it
                            bucketText.text = bucketText()
                            customerChoiceSection.visibility = View.GONE
                            resetCustomerSubsections()
                            showProductSearchControlsIfCustomerReady()
                            onStatus("Selected customer: ${it.name ?: it.email ?: it.id}")
                            dialog.dismiss()
                        }
                    }
                    onStatus("Found ${foundCustomers.size} customer(s)")
                }
            }
        })
        customerChoiceSection.addView(customerLookupSection)

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
                    customerChoiceSection.visibility = View.GONE
                    resetCustomerSubsections()
                    showProductSearchControlsIfCustomerReady()
                    onStatus("Customer ready")
                }
            }
        })
        customerChoiceSection.addView(newCustomerSection)

        val singlePaymentSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        val productPaymentSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        fun showSinglePaymentSection() {
            singlePaymentSection.visibility =
                if (singlePaymentSection.visibility == View.VISIBLE) View.GONE else View.VISIBLE
            productPaymentSection.visibility = View.GONE
            customerChoiceSection.visibility = View.GONE
            resetCustomerSubsections()
        }
        fun showProductPaymentSection() {
            productPaymentSection.visibility =
                if (productPaymentSection.visibility == View.VISIBLE) View.GONE else View.VISIBLE
            singlePaymentSection.visibility = View.GONE
            customerChoiceSection.visibility = View.GONE
            resetCustomerSubsections()
        }
        if (initialMode == null) {
            root.addView(menuButton("Single Payment") {
                showSinglePaymentSection()
            }.applyButtonBackground(R.drawable.button_background_navy))
            root.addView(menuButton("Product Payment") {
                showProductPaymentSection()
            }.applyButtonBackground(R.drawable.button_background_teal))
        }

        singlePaymentSection.addView(sectionTitle("Single Payment"))
        val oneTimeNameInput = input("Payment name")
        val oneTimeAmountInput = input("Dollar amount, e.g. 25.00")
        singlePaymentSection.addView(oneTimeNameInput)
        oneTimeAmountInput.apply {
            isFocusable = false
            isFocusableInTouchMode = false
            setOnClickListener {
                showNumberPadPopup("Single Payment Amount", oneTimeAmountInput)
            }
        }
        singlePaymentSection.addView(oneTimeAmountInput)
        singlePaymentSection.addView(menuButton("Add Single Payment to Cart") {
            val customer = selectedCustomer
            val name = oneTimeNameInput.text.toString().trim()
            if (name.isBlank()) {
                Toast.makeText(this, "Payment name is required", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            if (!canAddOneTimeItemToCart()) {
                Toast.makeText(this, "Finish or clear the recurring payment before adding one-time items", Toast.LENGTH_LONG).show()
                return@menuButton
            }
            val amount = parseDollarAmountToCents(oneTimeAmountInput.text.toString())
            if (amount == null || amount <= 0) {
                Toast.makeText(this, "Enter a valid dollar amount", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            cartItems.add(CartItem.OneTime(customer, name, amount, "usd"))
            recentAppTransactions.add(AppTransaction("cart", customer?.toString() ?: "Guest customer", name, "added_to_cart"))
            oneTimeNameInput.text?.clear()
            oneTimeAmountInput.text?.clear()
            singlePaymentSection.visibility = View.VISIBLE
            bucketText.text = bucketText()
            Toast.makeText(this, "Single payment added to cart", Toast.LENGTH_SHORT).show()
        })
        root.addView(singlePaymentSection)

        val productPathRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            isBaselineAligned = false
            gravity = Gravity.CENTER
        }
        fun showOneTimeProducts() {
            selectedProductTypeFilter = "One-time"
            pendingSubscriptionPlan = null
            customerChoiceSection.visibility = View.GONE
            addProductCustomerButton?.visibility = View.GONE
            resetCustomerSubsections()
            loadSelectedProducts?.invoke()
        }
        fun showSubscriptionProducts() {
            selectedProductTypeFilter = "Recurring"
            pendingSubscriptionPlan = null
            customerChoiceSection.visibility = View.GONE
            addProductCustomerButton?.visibility = View.GONE
            resetCustomerSubsections()
            loadSelectedProducts?.invoke()
        }
        productPathRow.addView(
            productPathButton("One-Time Products") {
                showOneTimeProducts()
            },
            productPathButtonParams()
        )
        productPathRow.addView(
            productPathButton("Subscription Products") {
                showSubscriptionProducts()
            },
            productPathButtonParams()
        )
        productPaymentSection.addView(productPathRow)

        addProductCustomerButton = menuButton("Add Customer") {
            toggleCustomerChoiceSection()
        }.applyButtonBackground(R.drawable.button_background_gold).apply {
            visibility = View.GONE
        }
        productPaymentSection.addView(addProductCustomerButton)
        productPaymentSection.addView(customerChoiceSection)
        fun addProductToCart(plan: MembershipPlan): Boolean {
            val customer = selectedCustomer
            if (plan.isRecurring() && customer == null) {
                Toast.makeText(this, "Select or create a customer first", Toast.LENGTH_SHORT).show()
                return false
            }
            if (!canAddProductToCart(plan)) {
                val message = if (plan.isRecurring()) {
                    "Recurring payments must be checked out separately from one-time items"
                } else {
                    "Finish or clear the recurring payment before adding one-time items"
                }
                Toast.makeText(this, message, Toast.LENGTH_LONG).show()
                return false
            }
            if (plan.isRecurring()) {
                cartItems.add(CartItem.Recurring(customer ?: return false, plan))
            } else {
                cartItems.add(CartItem.OneTime(customer, plan.name, plan.unitAmount, plan.currency))
            }
            recentAppTransactions.add(AppTransaction("cart", customer?.toString() ?: "Guest customer", plan.name, "added_to_cart"))
            selectedPlan = null
            productPaymentSection.visibility = View.VISIBLE
            customerChoiceSection.visibility = View.GONE
            addProductCustomerButton?.visibility = View.GONE
            resetCustomerSubsections()
            bucketText.text = bucketText()
            Toast.makeText(this, "Added to cart", Toast.LENGTH_SHORT).show()
            return true
        }
        fun showProductResultsPopup(plans: List<MembershipPlan>) {
            showSelectionPopup(
                title = "Select Product",
                labels = plans.map(::productLabel).ifEmpty { listOf("No products found") }
            ) { position, dialog ->
                val plan = plans.getOrNull(position) ?: return@showSelectionPopup
                if (plan.isRecurring() && selectedProductTypeFilter == "Recurring") {
                    pendingSubscriptionPlan = plan
                    selectedCustomer = null
                    customerChoiceSection.visibility = View.GONE
                    addProductCustomerButton?.visibility = View.GONE
                    resetCustomerSubsections()
                    dialog.dismiss()
                    showSubscriptionCustomerPopup(plan) { customer ->
                        selectedCustomer = customer
                        val added = addProductToCart(plan)
                        if (added) {
                            pendingSubscriptionPlan = null
                        }
                        added
                    }
                    return@showSelectionPopup
                }
                if (addProductToCart(plan)) {
                    dialog.dismiss()
                }
            }
        }
        fun loadProducts(filter: String = "") {
            runNetworkAction("Loading products...") {
                loadedPlans = apiClient.fetchMembershipPlans()
                runOnUiThread {
                    val filtered = filteredProducts(filter, selectedProductTypeFilter)
                    showProductResultsPopup(filtered)
                    onStatus("Loaded ${filtered.size} product(s)")
                }
            }
        }
        loadSelectedProducts = { loadProducts() }
        root.addView(productPaymentSection)

        when (initialMode) {
            PaymentEntryMode.SINGLE -> showSinglePaymentSection()
            PaymentEntryMode.PRODUCT -> showProductPaymentSection()
            null -> Unit
        }

        setScrollableContent(root)
    }

    private fun showCartScreen() {
        beginScreen { showCartScreen() }
        clearTakePaymentSelection()
        val root = screenRoot("Cart")
        root.addView(backButton())
        root.addView(statusLabel(""))
        val cartAdapter = multilineAdapter(cartItems.map(::cartLabel).toMutableList())
        val cartList = ListView(this).apply {
            adapter = cartAdapter
            isNestedScrollingEnabled = false
            setOnItemClickListener { _, _, position, _ ->
                cartItems.getOrNull(position)?.let { item ->
                    confirmRemoveCartItem(item)
                }
            }
        }
        root.addView(cartList, LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
        expandListToContent(cartList)
        root.addView(TextView(this).withText("Tap an item to remove it from the cart"))
        root.addView(selectionPanel(cartSummaryText()))
        root.addView(
            buttonRow(
                compactButton("Discount") {
                    showDiscountPopup()
                }.applyButtonBackground(R.drawable.button_background_gold),
                compactButton("Clear Cart") {
                    confirmClearCart()
                }.applyButtonBackground(R.drawable.button_background_navy)
            )
        )
        root.addView(menuButton("Checkout") {
            if (cartItems.isEmpty()) {
                Toast.makeText(this, "Cart is empty", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            checkoutCart()
        })
        root.addView(menuButton("Pay with Card Reader") {
            if (cartItems.isEmpty()) {
                Toast.makeText(this, "Cart is empty", Toast.LENGTH_SHORT).show()
                return@menuButton
            }
            checkoutCartWithReader()
        })
        addCheckoutReaderControls(root)
        setScrollableContent(root)
    }

    private fun addCheckoutReaderControls(root: LinearLayout) {
        connectedReaderText = selectionPanel(connectedReaderDisplayText())
        root.addView(connectedReaderText)
    }

    private fun checkoutCartItemWithReader(item: CartItem, itemsToRemove: List<CartItem> = listOf(item)) {
        if (item !is CartItem.OneTime) {
            onPaymentFailed("Use regular Checkout for recurring memberships and subscriptions")
            return
        }

        if (connectedReaderLabel == null) {
            pendingReaderConnectionItem = item
            pendingReaderConnectionCartItems = itemsToRemove
            showReaderResultsPopup = true
            onStatus("Looking for card readers...")
            if (hasRequiredPermissions()) {
                terminalManager.discoverBluetoothReaders()
            } else {
                requestRuntimePermissions()
            }
            return
        }

        pendingReaderCheckoutItem = item
        pendingCheckoutCartItems = itemsToRemove
        showReaderPaymentDialog(item)
        terminalManager.collectAndProcessPayment(item.amount, item.currency, item.customer?.id)
    }

    private fun showReaderPaymentDialog(item: CartItem.OneTime) {
        readerPaymentDialog?.dismiss()
        readerPaymentDialog = AlertDialog.Builder(this)
            .setTitle("Card Reader Payment")
            .setMessage(
                "Amount: ${formatDisplayAmount(item.amount, item.currency)}\n\n" +
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
        readerPaymentDialog?.let(::applyPopupDialogStyle)
    }

    private fun showRecentTransactionsScreen() {
        beginScreen { showRecentTransactionsScreen() }
        val root = screenRoot("Recent App Transactions")
        root.addView(backButton())
        root.addView(statusLabel(""))
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
                    showSelectionPopup(
                        title = "Recent Transactions",
                        labels = visibleDisplayItems.map { it.label }.ifEmpty { listOf("No matching transactions") }
                    ) { position, dialog ->
                        val receipt = visibleDisplayItems.getOrNull(position)?.receipt
                        if (receipt == null) {
                            selectedReceipt = null
                            selectedReceiptText.text = "This transaction does not have receipt details available"
                            Toast.makeText(this@MainActivity, "No receipt available for this transaction", Toast.LENGTH_SHORT).show()
                        } else {
                            selectedReceipt = receipt
                            selectedReceiptText.text = "Selected Receipt\n${receiptSummary(receipt)}"
                            dialog.dismiss()
                        }
                    }
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

    private fun checkoutCart() {
        if (cartItems.size == 1) {
            val item = cartItems.first()
            if (item is CartItem.OneTime && cartDiscount != null) {
                val discountedTotal = discountedCartAmount(item.amount).coerceAtLeast(0)
                if (discountedTotal <= 0) {
                    onPaymentFailed("Cart total must be greater than zero")
                    return
                }
                checkoutCartItem(
                    item.copy(
                        name = "${item.name} (discounted)",
                        amount = discountedTotal
                    ),
                    itemsToRemove = listOf(item)
                )
            } else {
                checkoutCartItem(item)
            }
            return
        }

        val oneTimeItems = cartItems.filterIsInstance<CartItem.OneTime>()
        if (oneTimeItems.size != cartItems.size) {
            onPaymentFailed("Checkout recurring memberships one at a time")
            return
        }

        val currencies = oneTimeItems.map { it.currency.lowercase() }.distinct()
        if (currencies.size != 1) {
            onPaymentFailed("Cart checkout requires one currency")
            return
        }

        val customerIds = oneTimeItems.mapNotNull { it.customer?.id }.distinct()
        if (customerIds.size > 1) {
            onPaymentFailed("Cart checkout requires one customer or guest-only items")
            return
        }

        val currency = oneTimeItems.first().currency
        val subtotal = oneTimeItems.sumOf { it.amount }
        val discountedTotal = discountedCartAmount(subtotal).coerceAtLeast(0)
        if (discountedTotal <= 0) {
            onPaymentFailed("Cart total must be greater than zero")
            return
        }

        checkoutCartItem(
            CartItem.OneTime(
                customer = oneTimeItems.mapNotNull { it.customer }.distinctBy { it.id }.singleOrNull(),
                name = "Cart order (${oneTimeItems.size} items)",
                amount = discountedTotal,
                currency = currency
            ),
            itemsToRemove = oneTimeItems
        )
    }

    private fun checkoutCartWithReader() {
        if (cartItems.size == 1) {
            val item = cartItems.first()
            if (item is CartItem.OneTime && cartDiscount != null) {
                val discountedTotal = discountedCartAmount(item.amount).coerceAtLeast(0)
                if (discountedTotal <= 0) {
                    onPaymentFailed("Cart total must be greater than zero")
                    return
                }
                checkoutCartItemWithReader(
                    item.copy(
                        name = "${item.name} (discounted)",
                        amount = discountedTotal
                    ),
                    itemsToRemove = listOf(item)
                )
            } else {
                checkoutCartItemWithReader(item)
            }
            return
        }

        val oneTimeItems = cartItems.filterIsInstance<CartItem.OneTime>()
        if (oneTimeItems.size != cartItems.size) {
            onPaymentFailed("Card reader cart checkout supports one-time items only")
            return
        }

        val currencies = oneTimeItems.map { it.currency.lowercase() }.distinct()
        val customerIds = oneTimeItems.mapNotNull { it.customer?.id }.distinct()
        if (currencies.size != 1 || customerIds.size > 1) {
            onPaymentFailed("Card reader cart checkout requires one customer or guest-only items, and one currency")
            return
        }

        val subtotal = oneTimeItems.sumOf { it.amount }
        val discountedTotal = discountedCartAmount(subtotal).coerceAtLeast(0)
        if (discountedTotal <= 0) {
            onPaymentFailed("Cart total must be greater than zero")
            return
        }

        checkoutCartItemWithReader(
            CartItem.OneTime(
                customer = oneTimeItems.mapNotNull { it.customer }.distinctBy { it.id }.singleOrNull(),
                name = "Cart order (${oneTimeItems.size} items)",
                amount = discountedTotal,
                currency = oneTimeItems.first().currency
            ),
            itemsToRemove = oneTimeItems
        )
    }

    private fun checkoutCartItem(item: CartItem, itemsToRemove: List<CartItem> = listOf(item)) {
        if (!AppConfig.stripePublishableKey.startsWith("pk_test_") && !AppConfig.stripePublishableKey.startsWith("pk_live_")) {
            onPaymentFailed("Stripe publishable key is missing or invalid")
            return
        }

        pendingCheckoutItem = item
        pendingCheckoutCartItems = itemsToRemove
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
                        customerId = item.customer?.id,
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
                val cartItemsToRemove = pendingCheckoutCartItems.ifEmpty { item?.let(::listOf).orEmpty() }
                pendingCheckoutItem = null
                pendingCheckoutCartItems = emptyList()
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
                                cartItems.removeAll(cartItemsToRemove)
                                cartDiscount = null
                                recentAppTransactions.add(
                                    AppTransaction("checkout", item.customer.toString(), item.plan.name, subscription.status, receipt = receipt)
                                )
                                showPaymentComplete(receipt)
                            }
                        }
                    }
                    is CartItem.OneTime -> {
                        val receipt = receiptForCartItem(item, "Card checkout", "succeeded")
                        cartItems.removeAll(cartItemsToRemove)
                        cartDiscount = null
                        recentAppTransactions.add(
                            AppTransaction("checkout", item.customer?.toString() ?: "Guest customer", item.name, "succeeded ${paymentIntentId ?: ""}".trim(), receipt = receipt)
                        )
                        showPaymentComplete(receipt)
                    }
                }
            }
            is PaymentSheetResult.Canceled -> {
                pendingCheckoutItem?.let {
                    recentAppTransactions.add(AppTransaction("checkout", it.customer?.toString() ?: "Guest customer", cartItemName(it), "canceled"))
                }
                pendingCheckoutItem = null
                pendingCheckoutCartItems = emptyList()
                pendingSetupIntentId = null
                pendingPaymentIntentId = null
                onStatus("Checkout canceled")
            }
            is PaymentSheetResult.Failed -> {
                pendingCheckoutItem?.let {
                    recentAppTransactions.add(AppTransaction("checkout", it.customer?.toString() ?: "Guest customer", cartItemName(it), "failed: ${result.error.localizedMessage}"))
                }
                pendingCheckoutItem = null
                pendingCheckoutCartItems = emptyList()
                pendingSetupIntentId = null
                pendingPaymentIntentId = null
                onPaymentFailed("Checkout failed: ${result.error.localizedMessage}")
            }
        }
    }

    private fun showThankYou() {
        beginScreen { showThankYou() }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
            background = borderedBackground()
        }
        root.addView(topBackArrowButton())
        root.addView(TextView(this).apply {
            text = "Thank You"
            textSize = 34f
            gravity = Gravity.CENTER
            typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
            setTextColor(Color.rgb(24, 34, 53))
            includeFontPadding = true
            setPadding(0, dp(150), 0, dp(12))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            )
        })
        setInsetContentView(root)
        handler.postDelayed({
            clearTakePaymentSelection()
            showMainMenu()
        }, 2_000)
    }

    private fun showPaymentComplete(receipt: ReceiptDetails? = null) {
        beginScreen { showPaymentComplete(receipt) }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
            background = borderedBackground()
        }
        root.addView(topBackArrowButton())
        root.addView(TextView(this).apply {
            text = "Payment Complete"
            textSize = 34f
            gravity = Gravity.CENTER
            typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
            setTextColor(Color.rgb(24, 34, 53))
            includeFontPadding = true
            setPadding(0, dp(150), 0, dp(12))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                LinearLayout.LayoutParams.WRAP_CONTENT
            )
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
        beginScreen { showReceiptOptionsScreen(receipt) }
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
        root.addView(backButton())
        root.addView(menuButton("Back") {
            showPaymentComplete(receipt)
        })
        setScrollableContent(root)
    }

    private fun showEmailReceiptScreen(receipt: ReceiptDetails) {
        beginScreen { showEmailReceiptScreen(receipt) }
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
        root.addView(backButton())
        setScrollableContent(root)
    }

    private fun showTextReceiptScreen(receipt: ReceiptDetails) {
        beginScreen { showTextReceiptScreen(receipt) }
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
        root.addView(backButton())
        setScrollableContent(root)
    }

    private fun showReceiptConfirmation(receipt: ReceiptDetails, action: ReceiptAction) {
        beginScreen { showReceiptConfirmation(receipt, action) }
        val root = screenRoot("Confirm Receipt")
        root.addView(statusLabel("Review before sending"))
        root.addView(selectionPanel("${receiptSummary(receipt)}\n\n${receiptActionSummary(action)}"))
        root.addView(menuButton("Confirm") {
            performReceiptAction(receipt, action)
        })
        root.addView(menuButton("Change Receipt Method") {
            showReceiptOptionsScreen(receipt)
        })
        root.addView(backButton())
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
        val pdfUri = createReceiptPdfUri(receipt)
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra(Intent.EXTRA_EMAIL, arrayOf(email))
            putExtra(Intent.EXTRA_SUBJECT, "Bloom MMA receipt")
            putExtra(Intent.EXTRA_TEXT, "Attached is your Bloom MMA receipt PDF.")
            putExtra(Intent.EXTRA_STREAM, pdfUri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        try {
            startActivity(Intent.createChooser(intent, "Email receipt PDF"))
            onStatus("Email receipt PDF opened")
        } catch (_: ActivityNotFoundException) {
            onPaymentFailed("No email app is available on this device")
        }
    }

    private fun textReceipt(receipt: ReceiptDetails, phone: String) {
        val pdfUri = createReceiptPdfUri(receipt)
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra("address", phone)
            putExtra("sms_body", "Attached is your Bloom MMA receipt PDF.")
            putExtra(Intent.EXTRA_TEXT, "Attached is your Bloom MMA receipt PDF.")
            putExtra(Intent.EXTRA_STREAM, pdfUri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        try {
            startActivity(Intent.createChooser(intent, "Send receipt PDF"))
            onStatus("Receipt PDF share opened")
        } catch (_: ActivityNotFoundException) {
            onPaymentFailed("No app is available to send the receipt PDF")
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

    private fun createReceiptPdfUri(receipt: ReceiptDetails): Uri {
        val pdfFile = createReceiptPdfFile(receipt)
        return FileProvider.getUriForFile(
            this,
            "${BuildConfig.APPLICATION_ID}.fileprovider",
            pdfFile
        )
    }

    private fun createReceiptPdfFile(receipt: ReceiptDetails): File {
        val template = receiptTemplate(receipt)
        val receiptsDir = File(cacheDir, "receipts").apply { mkdirs() }
        val file = File(receiptsDir, receiptPdfFileName(receipt))
        val pdf = PdfDocument()
        val pageInfo = PdfDocument.PageInfo.Builder(612, 792, 1).create()
        val page = pdf.startPage(pageInfo)
        val canvas = page.canvas
        val titlePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.rgb(37, 99, 235)
            textSize = 28f
            typeface = Typeface.create(Typeface.SANS_SERIF, Typeface.BOLD)
        }
        val subtitlePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.rgb(96, 111, 128)
            textSize = 14f
        }
        val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.rgb(96, 111, 128)
            textSize = 14f
            typeface = Typeface.create(Typeface.SANS_SERIF, Typeface.BOLD)
        }
        val valuePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.rgb(28, 38, 50)
            textSize = 15f
        }
        val linePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = Color.rgb(215, 222, 229)
            strokeWidth = 1f
        }

        var y = 72f
        canvas.drawText("Bloom MMA Receipt", 56f, y, titlePaint)
        y += 28f
        canvas.drawText("Payment receipt", 56f, y, subtitlePaint)
        y += 34f

        template.lines.forEach { (label, value) ->
            canvas.drawLine(56f, y - 12f, 556f, y - 12f, linePaint)
            canvas.drawText(label, 56f, y + 4f, labelPaint)
            y = drawWrappedText(canvas, value, 190f, y + 4f, valuePaint, 360f)
            y += 24f
        }

        y += 8f
        canvas.drawText("Thank you.", 56f, y, valuePaint)
        pdf.finishPage(page)

        FileOutputStream(file).use { output ->
            pdf.writeTo(output)
        }
        pdf.close()
        return file
    }

    private fun drawWrappedText(
        canvas: android.graphics.Canvas,
        value: String,
        x: Float,
        y: Float,
        paint: Paint,
        maxWidth: Float
    ): Float {
        var lineY = y
        val words = value.split(" ")
        var line = ""
        words.forEach { word ->
            val candidate = if (line.isBlank()) word else "$line $word"
            if (paint.measureText(candidate) <= maxWidth) {
                line = candidate
            } else {
                canvas.drawText(line, x, lineY, paint)
                line = word
                lineY += 20f
            }
        }
        if (line.isNotBlank()) {
            canvas.drawText(line, x, lineY, paint)
        }
        return lineY
    }

    private fun receiptPdfFileName(receipt: ReceiptDetails): String {
        val customer = receipt.customerName ?: receipt.customerEmail ?: "customer"
        val safeCustomer = customer.lowercase()
            .replace(Regex("[^a-z0-9]+"), "-")
            .trim('-')
            .ifBlank { "customer" }
        val safeDate = receipt.createdAt
            .replace(Regex("[^A-Za-z0-9]+"), "-")
            .trim('-')
            .ifBlank { System.currentTimeMillis().toString() }
        return "bloom-mma-receipt-$safeCustomer-$safeDate.pdf"
    }

    private fun receiptForCartItem(item: CartItem, method: String, status: String): ReceiptDetails {
        return when (item) {
            is CartItem.Recurring -> ReceiptDetails(
                customerName = item.customer.name,
                customerEmail = item.customer.email,
                customerPhone = item.customer.phone,
                product = item.plan.name,
                amount = formatDisplayAmount(item.plan.unitAmount, item.plan.currency),
                method = method,
                status = status
            )
            is CartItem.OneTime -> ReceiptDetails(
                customerName = item.customer?.name ?: "Guest customer",
                customerEmail = item.customer?.email,
                customerPhone = item.customer?.phone,
                product = item.name,
                amount = formatDisplayAmount(item.amount, item.currency),
                method = method,
                status = status
            )
        }
    }

    private fun receiptSummary(receipt: ReceiptDetails): String {
        return receiptTemplate(receipt).summary
    }

    private fun receiptBody(receipt: ReceiptDetails): String {
        return receiptTemplate(receipt).plainText
    }

    private fun receiptHtml(receipt: ReceiptDetails): String {
        return receiptTemplate(receipt).html
    }

    private fun receiptTemplate(receipt: ReceiptDetails): ReceiptTemplate {
        val lines = listOf(
            "Customer" to (receipt.customerName ?: receipt.customerEmail ?: "Customer"),
            "Product" to receipt.product,
            "Amount" to receipt.amount,
            "Method" to receipt.method,
            "Status" to receipt.status,
            "Date" to receipt.createdAt
        )
        val summary = lines.joinToString("\n") { (label, value) -> "$label: $value" }
        val plainText = listOf(
            "Bloom MMA Receipt",
            "",
            summary,
            "",
            "Thank you."
        ).joinToString("\n")
        val rows = lines.joinToString("\n") { (label, value) ->
            """
                <tr>
                    <th>${escapeHtml(label)}</th>
                    <td>${escapeHtml(value)}</td>
                </tr>
            """.trimIndent()
        }
        val html = """
            <html>
            <head>
                <meta name="viewport" content="width=device-width, initial-scale=1.0" />
                <style>
                    body {
                        color: #1C2632;
                        font-family: sans-serif;
                        margin: 0;
                        padding: 24px;
                    }
                    h1 {
                        color: #0066E6;
                        font-size: 26px;
                        margin: 0 0 8px;
                    }
                    .subtitle {
                        color: #606F80;
                        margin: 0 0 24px;
                    }
                    table {
                        border-collapse: collapse;
                        width: 100%;
                    }
                    th, td {
                        border-bottom: 1px solid #D7DEE5;
                        font-size: 16px;
                        padding: 10px 0;
                        text-align: left;
                        vertical-align: top;
                    }
                    th {
                        color: #606F80;
                        font-weight: 700;
                        width: 32%;
                    }
                    .thanks {
                        margin-top: 24px;
                    }
                </style>
            </head>
            <body>
                <h1>Bloom MMA Receipt</h1>
                <p class="subtitle">Payment receipt</p>
                <table>$rows</table>
                <p class="thanks">Thank you.</p>
            </body>
            </html>
        """.trimIndent()
        return ReceiptTemplate(
            lines = lines,
            summary = summary,
            plainText = plainText,
            smsText = plainText,
            html = html
        )
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
        return paymentScreenCartText()
    }

    private fun paymentScreenCartText(): String {
        if (cartItems.isEmpty()) {
            return "Items: 0\nNo items"
        }

        val itemLines = cartItems.mapIndexed { index, item ->
            val itemNumber = index + 1
            when (item) {
                is CartItem.Recurring -> {
                    val plan = item.plan
                    "$itemNumber. ${plan.name} - ${formatDisplayAmount(plan.unitAmount, plan.currency)}${plan.interval?.let { " / $it" }.orEmpty()}"
                }
                is CartItem.OneTime -> {
                    "$itemNumber. ${item.name} - ${formatDisplayAmount(item.amount, item.currency)}"
                }
            }
        }

        return "${cartSummaryText()}\n${itemLines.joinToString("\n")}"
    }

    private fun productLabel(plan: MembershipPlan): String {
        val billing = plan.interval?.let { "Every $it" } ?: "One-time"
        return "${plan.name}\nType: ${plan.typeLabel()}\nPrice: ${formatDisplayAmount(plan.unitAmount, plan.currency)}\nBilling: $billing"
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
                "${plan.name}\nType: recurring\nPrice today: ${formatDisplayAmount(plan.unitAmount, plan.currency)}${plan.interval?.let { "\nBilling: every $it" }.orEmpty()}\nCustomer: ${item.customer}"
            }
            is CartItem.OneTime -> {
                "${item.name}\nType: one time\nPrice: ${formatDisplayAmount(item.amount, item.currency)}\nCustomer: ${item.customer?.toString() ?: "Guest customer"}"
            }
        }
    }

    private fun canAddOneTimeItemToCart(): Boolean {
        return cartItems.none { it is CartItem.Recurring }
    }

    private fun canAddProductToCart(plan: MembershipPlan): Boolean {
        return if (plan.isRecurring()) {
            cartItems.none { it is CartItem.OneTime }
        } else {
            canAddOneTimeItemToCart()
        }
    }

    private fun confirmRemoveCartItem(item: CartItem) {
        val dialog = AlertDialog.Builder(this)
            .setTitle("Remove Item")
            .setMessage(cartLabel(item))
            .setPositiveButton("Remove") { _, _ ->
                cartItems.remove(item)
                if (cartItems.isEmpty()) {
                    cartDiscount = null
                }
                showCartScreen()
            }
            .setNegativeButton("Cancel", null)
            .show()
        applyPopupDialogStyle(dialog)
    }

    private fun confirmClearCart() {
        if (cartItems.isEmpty()) {
            Toast.makeText(this, "Cart is already empty", Toast.LENGTH_SHORT).show()
            return
        }

        val dialog = AlertDialog.Builder(this)
            .setTitle("Clear Cart")
            .setMessage("Remove all items from the cart?")
            .setPositiveButton("Clear") { _, _ ->
                cartItems.clear()
                cartDiscount = null
                showCartScreen()
            }
            .setNegativeButton("Cancel", null)
            .show()
        applyPopupDialogStyle(dialog)
    }

    private fun cartSummaryText(): String {
        if (cartItems.isEmpty()) {
            return "Items: 0\nNo items"
        }
        val oneTimeItems = cartItems.filterIsInstance<CartItem.OneTime>()
        val recurringCount = cartItems.size - oneTimeItems.size
        val currency = oneTimeItems.firstOrNull()?.currency ?: "usd"
        val subtotal = oneTimeItems.sumOf { it.amount }
        val discountAmount = cartDiscountAmount(subtotal)
        val total = (subtotal - discountAmount).coerceAtLeast(0)
        val lines = mutableListOf<String>()
        lines.add("Items: ${cartItems.size}")
        if (oneTimeItems.isNotEmpty()) {
            lines.add("Total: ${formatDisplayAmount(subtotal, currency)}")
        }
        if (recurringCount > 0) {
            lines.add("Recurring item(s): $recurringCount")
        }
        cartDiscount?.let {
            lines.add("Discount: ${discountLabel(it, currency)}")
            lines.add("Total after discount: ${formatDisplayAmount(total, currency)}")
        }
        return lines.joinToString("\n")
    }

    private fun discountedCartAmount(subtotal: Int): Int {
        return (subtotal - cartDiscountAmount(subtotal)).coerceAtLeast(0)
    }

    private fun cartDiscountAmount(subtotal: Int): Int {
        return when (val discount = cartDiscount) {
            is CartDiscount.Percent -> Math.round(subtotal * (discount.percent / 100.0)).toInt()
            is CartDiscount.Amount -> discount.amount.coerceAtMost(subtotal)
            null -> 0
        }
    }

    private fun discountLabel(discount: CartDiscount, currency: String): String {
        return when (discount) {
            is CartDiscount.Percent -> "${"%.2f".format(discount.percent)}%"
            is CartDiscount.Amount -> formatDisplayAmount(discount.amount, currency)
        }
    }

    private fun formatDisplayAmount(amount: Int, currency: String): String {
        return formatMinorAmount(amount, currency)
            .removePrefix("${currency.uppercase()} ")
            .trim()
    }

    private fun cartItemName(item: CartItem): String {
        return when (item) {
            is CartItem.Recurring -> item.plan.name
            is CartItem.OneTime -> item.name
        }
    }

    private fun connectedReaderDisplayText(): String {
        return connectedReaderLabel ?: "No reader connected"
    }

    private fun readerLabel(reader: Reader): String {
        return "${reader.serialNumber ?: "Unknown serial"} ${reader.deviceType}"
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
        } else if (requestCode == REQUEST_CODE_PERMISSIONS && pendingReaderConnectionItem != null) {
            showReaderResultsPopup = true
            onStatus("Looking for card readers...")
            terminalManager.discoverBluetoothReaders()
        }
    }

    override fun onStatus(message: String) {
        runOnUiThread {
            if (::statusText.isInitialized) {
                statusText.text = message
                val statusVisibility = if (message.isBlank() || message == "Ready") View.GONE else View.VISIBLE
                statusText.visibility = statusVisibility
                statusRow?.let { row ->
                    row.visibility = if (row.id == R.id.mainStatusRow) View.VISIBLE else statusVisibility
                }
            }
        }
    }

    override fun onReadersUpdated(readers: List<Reader>) {
        runOnUiThread {
            discoveredReaders = readers
            if (showReaderResultsPopup) {
                readerSelectionDialog?.setOnDismissListener(null)
                readerSelectionDialog?.dismiss()
                readerSelectionDialog = showSelectionPopup(
                    title = "Select Reader",
                    labels = readers.map(::readerLabel).ifEmpty { listOf("No readers found") }
                ) { position, dialog ->
                    readers.getOrNull(position)?.let {
                        onStatus("Connecting to reader...")
                        terminalManager.connectReader(it)
                        showReaderResultsPopup = false
                        dialog.dismiss()
                    }
                }
                readerSelectionDialog?.setOnDismissListener {
                    if (showReaderResultsPopup) {
                        showReaderResultsPopup = false
                        pendingReaderConnectionItem = null
                        pendingReaderConnectionCartItems = emptyList()
                        onStatus("Reader selection canceled")
                    }
                }
            } else {
                readersAdapter?.clear()
                readersAdapter?.addAll(readers.map(::readerLabel))
                readersAdapter?.notifyDataSetChanged()
                readersListView?.let(::expandListToContent)
            }
        }
    }

    override fun onReaderConnected(reader: Reader) {
        connectedReaderLabel = "${reader.serialNumber ?: "reader"} ${reader.deviceType}"
        onStatus("Connected: ${reader.serialNumber ?: "reader"}")
        runOnUiThread {
            readerSelectionDialog?.dismiss()
            readerSelectionDialog = null
            connectedReaderText?.text = connectedReaderDisplayText()
            val deferredItem = pendingReaderConnectionItem
            if (deferredItem != null) {
                val deferredCartItems = pendingReaderConnectionCartItems.ifEmpty { listOf(deferredItem) }
                pendingReaderConnectionItem = null
                pendingReaderConnectionCartItems = emptyList()
                checkoutCartItemWithReader(deferredItem, deferredCartItems)
            }
        }
    }

    override fun onReaderDisconnected() {
        connectedReaderLabel = null
        pendingReaderConnectionItem = null
        pendingReaderConnectionCartItems = emptyList()
        runOnUiThread {
            connectedReaderText?.text = connectedReaderDisplayText()
        }
    }

    override fun onPaymentSucceeded(message: String) {
        onStatus(message)
        runOnUiThread {
            val readerItem = pendingReaderCheckoutItem
            if (readerItem != null) {
                val cartItemsToRemove = pendingCheckoutCartItems.ifEmpty { listOf(readerItem) }
                pendingReaderCheckoutItem = null
                pendingCheckoutCartItems = emptyList()
                readerPaymentDialog?.dismiss()
                readerPaymentDialog = null
                cartItems.removeAll(cartItemsToRemove)
                cartDiscount = null
                val receipt = receiptForCartItem(readerItem, "Card reader", "succeeded")
                recentAppTransactions.add(
                    AppTransaction("terminal", readerItem.customer?.toString() ?: "Guest customer", readerItem.name, "succeeded", receipt = receipt)
                )
                showTransactionSuccessfulDialog(receipt)
                return@runOnUiThread
            }

            recentAppTransactions.add(AppTransaction("terminal", selectedCustomer?.toString() ?: "No customer", "Card-present payment", "succeeded"))
            Toast.makeText(this, message, Toast.LENGTH_LONG).show()
        }
    }

    private fun showTransactionSuccessfulDialog(receipt: ReceiptDetails) {
        val dialog = AlertDialog.Builder(this)
            .setTitle("Transaction Successful")
            .setMessage("Payment complete.")
            .setCancelable(false)
            .setPositiveButton("OK") { dialog, _ ->
                dialog.dismiss()
                showPaymentComplete(receipt)
            }
            .show()
        applyPopupDialogStyle(dialog)
    }

    override fun onPaymentFailed(message: String) {
        onStatus(message)
        runOnUiThread {
            if (pendingReaderCheckoutItem != null) {
                recentAppTransactions.add(
                    AppTransaction("terminal", pendingReaderCheckoutItem?.customer?.toString() ?: "No customer", pendingReaderCheckoutItem?.name ?: "Card-present payment", "failed")
                )
                pendingReaderCheckoutItem = null
                pendingCheckoutCartItems = emptyList()
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

    private fun beginScreen(recreate: () -> Unit) {
        if (!restoringScreen) {
            currentScreenAction?.let(screenBackStack::add)
        }
        currentScreenAction = recreate
    }

    private fun goBackOneScreen() {
        val previousScreen = screenBackStack.removeLastOrNull()
        if (previousScreen == null) {
            showMainMenu()
            return
        }

        restoringScreen = true
        try {
            previousScreen()
        } finally {
            restoringScreen = false
        }
    }

    private fun screenRoot(title: String): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(18), dp(18), dp(18))
            addView(centeredScreenHeader(title))
        }
    }

    private fun centeredScreenHeader(title: String): FrameLayout {
        return FrameLayout(this).apply {
            setPadding(0, 0, 0, dp(22))
            addView(
                topBackArrowButton(),
                FrameLayout.LayoutParams(dp(56), dp(56), Gravity.START or Gravity.TOP).apply {
                    topMargin = dp(6)
                }
            )
            addView(
                TextView(this@MainActivity).apply {
                    text = title
                    textSize = 27f
                    gravity = Gravity.CENTER
                    typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
                    setTextColor(Color.rgb(24, 34, 53))
                    includeFontPadding = true
                    maxLines = 2
                    setLineSpacing(dp(2).toFloat(), 1.0f)
                    setPadding(dp(62), dp(8), dp(62), dp(8))
                },
                FrameLayout.LayoutParams(
                    FrameLayout.LayoutParams.MATCH_PARENT,
                    dp(88),
                    Gravity.CENTER
                )
            )
        }
    }

    private fun setScrollableContent(root: LinearLayout) {
        val currentStatusText = if (::statusText.isInitialized) statusText else null
        currentStatusText?.let { label ->
            (label.parent as? ViewGroup)?.removeView(label)
        }
        statusRow = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = ContextCompat.getDrawable(this@MainActivity, R.drawable.status_pill)
            setPadding(dp(12), dp(6), dp(12), dp(6))
            visibility = currentStatusText?.visibility ?: View.GONE
            currentStatusText?.let { label ->
                label.setPadding(0, 0, 0, 0)
                addView(
                    label,
                    LinearLayout.LayoutParams(
                        LinearLayout.LayoutParams.MATCH_PARENT,
                        LinearLayout.LayoutParams.WRAP_CONTENT
                    )
                )
            }
        }
        val frame = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(10), dp(18), dp(10), dp(10))
            background = borderedBackground()
            addView(
                ScrollView(this@MainActivity).apply { addView(root) },
                LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    0,
                    1f
                )
            )
            addView(
                statusRow,
                LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.MATCH_PARENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT
                ).apply {
                    topMargin = dp(8)
                }
            )
        }
        setInsetContentView(frame)
    }

    private fun setInsetContentView(content: View) {
        val outer = FrameLayout(this).apply {
            setBackgroundColor(Color.rgb(244, 247, 250))
            setPadding(dp(7), dp(8), dp(7), dp(8))
            addView(
                content,
                FrameLayout.LayoutParams(
                    FrameLayout.LayoutParams.MATCH_PARENT,
                    FrameLayout.LayoutParams.MATCH_PARENT
                )
            )
        }
        applySystemBarPadding(outer)
        setContentView(outer)
    }

    private fun applySystemBarPaddingToContent() {
        val contentRoot = findViewById<ViewGroup>(android.R.id.content)
        val screen = contentRoot.getChildAt(0) ?: return
        applySystemBarPadding(screen)
    }

    private fun applySystemBarPadding(view: View) {
        val baseLeft = view.paddingLeft
        val baseTop = view.paddingTop
        val baseRight = view.paddingRight
        val baseBottom = view.paddingBottom

        view.setOnApplyWindowInsetsListener { insetView, insets ->
            val systemBars = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                @Suppress("NewApi")
                insets.getInsets(WindowInsets.Type.systemBars()).let {
                    intArrayOf(it.left, it.top, it.right, it.bottom)
                }
            } else {
                @Suppress("DEPRECATION")
                intArrayOf(
                    insets.systemWindowInsetLeft,
                    insets.systemWindowInsetTop,
                    insets.systemWindowInsetRight,
                    insets.systemWindowInsetBottom
                )
            }
            insetView.setPadding(
                baseLeft + systemBars[0],
                baseTop + systemBars[1],
                baseRight + systemBars[2],
                baseBottom + systemBars[3]
            )
            insets
        }
        view.requestApplyInsets()
    }

    private fun statusLabel(value: String): TextView {
        statusText = TextView(this).apply {
            text = value
            visibility = if (value.isBlank() || value == "Ready") View.GONE else View.VISIBLE
            textSize = 15f
            typeface = Typeface.create("sans-serif", Typeface.NORMAL)
            setTextColor(Color.rgb(97, 112, 132))
            setPadding(0, 0, 0, dp(12))
        }
        return statusText
    }

    private fun sectionTitle(text: String): TextView {
        return TextView(this).apply {
            this.text = text
            textSize = 18f
            typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
            setTextColor(Color.rgb(24, 34, 53))
            setPadding(0, dp(26), 0, dp(8))
        }
    }

    private fun selectionPanel(initialText: String): TextView {
        return TextView(this).apply {
            text = initialText
            textSize = 15f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.rgb(36, 50, 71))
            setPadding(dp(18), dp(16), dp(18), dp(16))
            background = GradientDrawable().apply {
                cornerRadius = dp(10).toFloat()
                setColor(Color.rgb(247, 250, 252))
                setStroke(dp(1), Color.rgb(201, 215, 230))
            }
        }
    }

    private fun input(hint: String): TextInputEditText {
        return TextInputEditText(this).apply {
            this.hint = hint
            textSize = 15f
            typeface = Typeface.create("sans-serif", Typeface.NORMAL)
            setTextColor(Color.rgb(36, 50, 71))
            setHintTextColor(Color.rgb(97, 112, 132))
            setPadding(dp(14), 0, dp(14), 0)
            setSingleLine(true)
            minHeight = dp(52)
            background = GradientDrawable().apply {
                cornerRadius = dp(8).toFloat()
                setColor(Color.WHITE)
                setStroke(dp(1), Color.rgb(201, 215, 230))
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

    private fun amountKeypad(amountInput: TextInputEditText): LinearLayout {
        val keypad = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(4), 0, dp(10))
        }

        fun updateAmount(value: String) {
            val current = amountInput.text?.toString().orEmpty()
            val updated = when (value) {
                "Del" -> current.dropLast(1)
                "." -> when {
                    current.contains(".") -> current
                    current.isBlank() -> "0."
                    else -> "$current."
                }
                else -> current + value
            }
            amountInput.setText(updated)
            amountInput.setSelection(updated.length)
        }

        listOf(
            listOf("1", "2", "3"),
            listOf("4", "5", "6"),
            listOf("7", "8", "9"),
            listOf(".", "0", "Del")
        ).forEach { rowLabels ->
            keypad.addView(
                buttonRow(
                    *rowLabels.map { label ->
                        compactButton(label) { updateAmount(label) }
                    }.toTypedArray()
                )
            )
        }

        return keypad
    }

    private fun showSubscriptionCustomerPopup(
        plan: MembershipPlan,
        onCustomerReady: (CustomerSummary) -> Boolean
    ) {
        var completed = false
        val content = popupContentContainer().apply {
            addView(selectionPanel("Selected product\n${productLabel(plan)}"))
        }
        val customerChoiceRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
        }
        val selectedChoiceText = TextView(this).apply {
            visibility = View.GONE
            textSize = 15f
            typeface = Typeface.create("sans-serif-medium", Typeface.BOLD)
            setPadding(0, dp(10), 0, dp(10))
        }
        val lookupSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        val newCustomerSection = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }

        fun showLookupSelected() {
            customerChoiceRow.visibility = View.GONE
            selectedChoiceText.apply {
                text = "Customer Lookup"
                setTextColor(Color.rgb(37, 99, 235))
                visibility = View.VISIBLE
            }
            lookupSection.visibility = View.VISIBLE
            newCustomerSection.visibility = View.GONE
        }

        fun showNewCustomerSelected() {
            customerChoiceRow.visibility = View.GONE
            selectedChoiceText.apply {
                text = "New Customer"
                setTextColor(Color.rgb(124, 58, 237))
                visibility = View.VISIBLE
            }
            lookupSection.visibility = View.GONE
            newCustomerSection.visibility = View.VISIBLE
        }

        val lookupButton = compactButton("Customer Lookup") {
            showLookupSelected()
        }.applyButtonBackground(R.drawable.button_background_navy)
        val newCustomerButton = compactButton("New Customer") {
            showNewCustomerSelected()
        }.applyButtonBackground(R.drawable.button_background_gold)
        customerChoiceRow.addView(lookupButton, twoColumnButtonParams())
        customerChoiceRow.addView(newCustomerButton, twoColumnButtonParams())
        content.addView(customerChoiceRow)
        content.addView(selectedChoiceText)

        lateinit var dialog: AlertDialog
        fun finishWithCustomer(customer: CustomerSummary) {
            if (onCustomerReady(customer)) {
                completed = true
                dialog.dismiss()
            } else {
                onStatus("product addition failed")
            }
        }

        val searchInput = input("Search name, email, or phone")
        lookupSection.addView(searchInput)
        lookupSection.addView(menuButton("Search Customers") {
            onStatus("Searching customers...")
            Thread {
                try {
                    val customers = apiClient.searchCustomers(searchInput.text.toString())
                    runOnUiThread {
                        showSelectionPopup(
                            title = "Select Customer",
                            labels = customers.map(CustomerSummary::toString).ifEmpty { listOf("No customers found") }
                        ) { position, selectionDialog ->
                            customers.getOrNull(position)?.let {
                                finishWithCustomer(it)
                                selectionDialog.dismiss()
                            }
                        }
                        onStatus("Found ${customers.size} customer(s)")
                    }
                } catch (_: Exception) {
                    onStatus("product addition failed")
                }
            }.start()
        })
        content.addView(lookupSection)

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
            onStatus("Creating customer...")
            Thread {
                try {
                    val customer = apiClient.createCustomer(
                        name,
                        customerEmailInput.text.toString(),
                        customerPhoneInput.text.toString()
                    )
                    runOnUiThread {
                        finishWithCustomer(customer)
                    }
                } catch (_: Exception) {
                    onStatus("product addition failed")
                }
            }.start()
        })
        content.addView(newCustomerSection)

        dialog = AlertDialog.Builder(this)
            .setTitle("Add Customer")
            .setView(content)
            .setNegativeButton("Cancel", null)
            .create()
        dialog.setOnDismissListener {
            if (!completed) {
                onStatus("product addition failed")
            }
        }
        dialog.show()
        applyPopupDialogStyle(dialog)
    }

    private fun showSelectionPopup(
        title: String,
        labels: List<String>,
        onSelected: (position: Int, dialog: AlertDialog) -> Unit
    ): AlertDialog {
        val content = popupContentContainer()
        val listView = ListView(this).apply {
            adapter = multilineAdapter(labels.toMutableList())
            divider = null
            dividerHeight = 0
            isNestedScrollingEnabled = true
            setPadding(0, dp(2), 0, dp(2))
            clipToPadding = false
        }
        content.addView(
            listView,
            LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                popupListHeight(labels.size)
            )
        )
        val dialog = AlertDialog.Builder(this)
            .setTitle(title)
            .setView(content)
            .setNegativeButton("Close", null)
            .create()
        listView.setOnItemClickListener { _, _, position, _ ->
            onSelected(position, dialog)
        }
        dialog.show()
        applyPopupDialogStyle(dialog)
        return dialog
    }

    private fun showInfoPopup(title: String, labels: List<String>) {
        showSelectionPopup(title, labels) { _, dialog ->
            dialog.dismiss()
        }
    }

    private fun showDiscountPopup() {
        val amountInput = input("Discount amount")
        val percentButton = RadioButton(this).apply {
            text = "% of order"
            textSize = 15f
            id = View.generateViewId()
        }
        val dollarButton = RadioButton(this).apply {
            text = "Dollar amount"
            textSize = 15f
            id = View.generateViewId()
        }
        val discountTypeGroup = RadioGroup(this).apply {
            orientation = RadioGroup.VERTICAL
            addView(percentButton)
            addView(dollarButton)
            check(percentButton.id)
        }
        val dialogContent = popupContentContainer().apply {
            addView(discountTypeGroup)
            addView(amountInput)
            addView(amountKeypad(amountInput))
        }

        val dialog = AlertDialog.Builder(this)
            .setTitle("Order Discount")
            .setView(dialogContent)
            .setPositiveButton("Apply") { _, _ ->
                val value = amountInput.text?.toString().orEmpty().toDoubleOrNull()
                if (value == null || value <= 0.0) {
                    Toast.makeText(this, "Enter a valid discount", Toast.LENGTH_SHORT).show()
                    return@setPositiveButton
                }
                cartDiscount = if (discountTypeGroup.checkedRadioButtonId == percentButton.id) {
                    CartDiscount.Percent(value.coerceAtMost(100.0))
                } else {
                    CartDiscount.Amount(Math.round(value * 100).toInt())
                }
                showCartScreen()
            }
            .setNeutralButton("Clear") { _, _ ->
                cartDiscount = null
                showCartScreen()
            }
            .setNegativeButton("Cancel", null)
            .show()
        applyPopupDialogStyle(dialog)
    }

    private fun showNumberPadPopup(title: String, amountInput: TextInputEditText) {
        val popupInput = input("Amount").apply {
            setText(amountInput.text?.toString().orEmpty())
            setSelection(text?.length ?: 0)
        }
        val dialogContent = popupContentContainer().apply {
            addView(popupInput)
            addView(amountKeypad(popupInput))
        }
        val dialog = AlertDialog.Builder(this)
            .setTitle(title)
            .setView(dialogContent)
            .setPositiveButton("Done") { _, _ ->
                val value = popupInput.text?.toString().orEmpty()
                amountInput.setText(value)
                amountInput.setSelection(value.length)
            }
            .setNegativeButton("Cancel", null)
            .show()
        applyPopupDialogStyle(dialog)
    }

    private fun popupContentContainer(): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(10), dp(18), dp(8))
            background = GradientDrawable().apply {
                cornerRadius = dp(12).toFloat()
                setColor(Color.rgb(248, 251, 253))
                setStroke(dp(1), Color.rgb(218, 228, 238))
            }
        }
    }

    private fun popupListHeight(itemCount: Int): Int {
        val visibleRows = itemCount.coerceIn(1, 5)
        return dp((visibleRows * 86).coerceAtLeast(104))
    }

    private fun applyPopupDialogStyle(dialog: AlertDialog) {
        dialog.window?.setLayout((resources.displayMetrics.widthPixels * 0.92f).toInt(), ViewGroup.LayoutParams.WRAP_CONTENT)
        dialog.window?.setBackgroundDrawable(
            GradientDrawable().apply {
                cornerRadius = dp(16).toFloat()
                setColor(Color.WHITE)
            }
        )
    }

    private fun multilineAdapter(items: MutableList<String> = mutableListOf()): ArrayAdapter<String> {
        return object : ArrayAdapter<String>(this, android.R.layout.simple_list_item_1, items) {
            override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
                val view = super.getView(position, convertView, parent)
                (view as? TextView)?.apply {
                    text = formatPopupLabel(getItem(position).orEmpty())
                    isSingleLine = false
                    maxLines = Int.MAX_VALUE
                    textSize = 15f
                    typeface = Typeface.create("sans-serif", Typeface.NORMAL)
                    setTextColor(Color.rgb(36, 50, 71))
                    setLineSpacing(dp(3).toFloat(), 1.0f)
                    minHeight = dp(72)
                    setPadding(dp(16), dp(14), dp(16), dp(14))
                    background = GradientDrawable().apply {
                        cornerRadius = dp(8).toFloat()
                        setColor(if (position % 2 == 0) Color.WHITE else Color.rgb(248, 251, 253))
                        setStroke(dp(1), Color.rgb(218, 228, 238))
                    }
                }
                return view
            }
        }
    }

    private fun formatPopupLabel(value: String): String {
        return value
            .replace(" | ", "\n")
            .replace(" - ", "\nPrice: ")
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
            isBaselineAligned = false
            gravity = Gravity.CENTER
            buttons.forEach { button ->
                addView(button, twoColumnButtonParams())
            }
        }
    }

    private fun twoColumnButtonParams(): LinearLayout.LayoutParams {
        return LinearLayout.LayoutParams(0, dp(72), 1f).apply {
            setMargins(dp(4), dp(6), dp(4), dp(6))
        }
    }

    private fun productPathButtonParams(): LinearLayout.LayoutParams {
        return LinearLayout.LayoutParams(0, dp(72), 1f).apply {
            setMargins(dp(4), dp(6), dp(4), dp(6))
        }
    }

    private fun compactButton(text: String, action: () -> Unit): Button {
        return Button(this).apply {
            this.text = text
            setAllCaps(false)
            textSize = 13.5f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.WHITE)
            minHeight = dp(72)
            background = ContextCompat.getDrawable(this@MainActivity, R.drawable.button_background)
            elevation = dp(1).toFloat()
            stateListAnimator = null
            gravity = Gravity.CENTER
            maxLines = 2
            includeFontPadding = true
            setPadding(dp(10), dp(2), dp(10), dp(2))
            setOnClickListener { action() }
        }
    }

    private fun productPathButton(text: String, action: () -> Unit): Button {
        return compactButton(text, action).apply {
            applyButtonBackground(R.drawable.button_background_teal)
            textSize = 13f
            maxLines = 2
            gravity = Gravity.CENTER
            minHeight = dp(72)
            setPadding(dp(8), 0, dp(8), 0)
        }
    }

    private fun menuButton(text: String, action: () -> Unit): Button {
        return Button(this).apply {
            this.text = text
            setAllCaps(false)
            textSize = 15f
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.WHITE)
            minHeight = dp(72)
            background = ContextCompat.getDrawable(this@MainActivity, R.drawable.button_background)
            elevation = dp(1).toFloat()
            stateListAnimator = null
            gravity = Gravity.CENTER
            maxLines = 2
            includeFontPadding = true
            setPadding(dp(18), dp(2), dp(18), dp(2))
            layoutParams = LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT,
                dp(72)
            ).apply {
                setMargins(0, dp(6), 0, dp(6))
            }
            setOnClickListener { action() }
        }
    }

    private fun topBackArrowButton(): TextView {
        return TextView(this).apply {
            text = "←"
            contentDescription = "Go back"
            textSize = 42f
            gravity = Gravity.CENTER
            includeFontPadding = false
            typeface = Typeface.create("sans-serif-medium", Typeface.NORMAL)
            setTextColor(Color.rgb(37, 99, 235))
            minWidth = dp(56)
            minHeight = dp(56)
            background = null
            elevation = dp(3).toFloat()
            stateListAnimator = null
            setPadding(0, 0, 0, 0)
            layoutParams = LinearLayout.LayoutParams(dp(56), dp(56)).apply {
                setMargins(0, 0, 0, 0)
            }
            setOnClickListener { goBackOneScreen() }
        }
    }

    private fun backButton(): Button = menuButton("Back to Main Screen") {
        clearTakePaymentSelection()
        showMainMenu()
    }.apply {
        applyBackHomeButtonStyle()
    }

    private fun Button.applyBackHomeButtonStyle() {
        applyButtonBackground(R.drawable.button_background_green)
    }

    private fun Button.applyButtonBackground(drawableResId: Int): Button {
        background = ContextCompat.getDrawable(this@MainActivity, drawableResId)
        return this
    }

    private fun borderedButtonBackground(fillColor: Int, strokeColor: Int): GradientDrawable {
        return GradientDrawable().apply {
            setColor(fillColor)
            cornerRadius = dp(10).toFloat()
            setStroke(dp(1), strokeColor)
        }
    }

    private fun borderedBackground(): GradientDrawable {
        return GradientDrawable().apply {
            setColor(Color.WHITE)
            cornerRadius = dp(16).toFloat()
            setStroke(dp(4), Color.rgb(37, 99, 235))
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
        abstract val customer: CustomerSummary?

        data class Recurring(
            override val customer: CustomerSummary,
            val plan: MembershipPlan
        ) : CartItem()

        data class OneTime(
            override val customer: CustomerSummary?,
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

    data class ReceiptTemplate(
        val lines: List<Pair<String, String>>,
        val summary: String,
        val plainText: String,
        val smsText: String,
        val html: String
    )

    enum class PaymentEntryMode {
        SINGLE,
        PRODUCT
    }

    sealed class CartDiscount {
        data class Percent(val percent: Double) : CartDiscount()
        data class Amount(val amount: Int) : CartDiscount()
    }

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
