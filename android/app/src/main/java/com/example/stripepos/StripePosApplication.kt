package com.example.stripepos

import android.app.Application
import com.stripe.stripeterminal.TerminalApplicationDelegate

class StripePosApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        TerminalApplicationDelegate.onCreate(this)
    }
}

