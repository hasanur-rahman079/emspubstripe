# EmsPubStripe Payment Plugin

A Stripe payment gateway plugin for OJS (Open Journal Systems) that enables journals to accept credit card payments for article processing charges (APC), subscriptions, and other fees via Stripe Checkout.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- **Stripe Checkout Integration**: Secure, PCI-compliant payment processing via Stripe's hosted checkout page
- **3D Secure Support**: Automatic handling of 3D Secure authentication for card payments
- **Multi-currency Support**: Accept payments in any currency supported by Stripe
- **Test Mode**: Toggle between live and sandbox environments for development
- **Seamless OJS Integration**: Works with OJS's built-in payment system for publication fees, submission fees, and subscriptions
- **Success/Cancel Pages**: Custom templates for payment confirmation and cancellation

---

## Requirements

| Requirement | Version |
|-------------|---------|
| OJS | 3.4.0+ |
| PHP | 8.1+ |
| Stripe Account | Required |
| SSL Certificate | Required (for production) |

---

## Installation

### Step 1: Install Plugin Files

```bash
# Clone or copy the plugin to OJS paymethod plugins directory
cp -r emspubstripe /path/to/ojs/plugins/paymethod/
```

### Step 2: Install Composer Dependencies

```bash
cd /path/to/ojs/plugins/paymethod/emspubstripe
composer install
```

### Step 3: Enable the Plugin

1. Navigate to **Settings → Website → Plugins → Payment Method Plugins**
2. Find "EmsPub Stripe Payment"
3. Click **Enable**

---

## Configuration

### Step 1: Get Stripe API Keys

1. Log in to your [Stripe Dashboard](https://dashboard.stripe.com/apikeys)
2. Copy your API keys:

| Key Type | Usage |
|----------|-------|
| **Publishable Key** (`pk_...`) | Client-side (not used by this plugin) |
| **Secret Key** (`sk_...`) | Server-side API calls |

### Step 2: Configure Plugin Settings

1. Navigate to **Settings → Workflow → Payments**
2. Enable "Payments"
3. Scroll to "EmsPub Stripe Payment" section
4. Configure the following:

| Setting | Description |
|---------|-------------|
| **Test Mode** | Enable for sandbox testing, disable for live payments |
| **Account Name** | Your Stripe account display name |
| **Client ID** | Your Stripe account identifier (optional) |
| **Secret Key** | Your Stripe secret key (`sk_test_...` or `sk_live_...`) |

### Step 3: Enable Publication Fees

1. In **Settings → Workflow → Payments**
2. Set **Publication Fee** amount and currency
3. Save settings

---

## How It Works

### Payment Flow

```
┌─────────────┐     ┌──────────────┐     ┌────────────────┐
│   Author    │────▶│ OJS Payment  │────▶│ Stripe Checkout│
│ Clicks Pay  │     │   System     │     │  (Hosted Page) │
└─────────────┘     └──────────────┘     └───────┬────────┘
                                                  │
┌─────────────┐     ┌──────────────┐              │
│  Payment    │◀────│   Callback   │◀─────────────┘
│  Complete   │     │   Handler    │
└─────────────┘     └──────────────┘
```

1. **Author initiates payment**: Clicks "Pay Now" for their submission
2. **Plugin creates Stripe Session**: Generates a Checkout Session with payment details
3. **Redirect to Stripe**: Author is redirected to Stripe's secure checkout page
4. **Payment processing**: Author enters card details on Stripe's hosted page
5. **Callback handling**: After payment, Stripe redirects back to OJS with session ID
6. **Payment verification**: Plugin fetches session status from Stripe API
7. **Fulfillment**: If successful, queued payment is marked complete

### Files Overview

```
plugins/paymethod/emspubstripe/
├── EmsPubStripePlugin.php        # Main plugin class
├── EmsPubStripePaymentForm.php   # Payment form handler
├── index.php                     # Plugin loader
├── version.xml                   # Version metadata
├── composer.json                 # PHP dependencies
├── templates/
│   ├── paymentSuccess.tpl        # Success confirmation page
│   └── paymentCancel.tpl         # Payment cancelled page
├── locale/                       # Internationalization files
│   └── en/locale.po              # English translations
└── images/                       # Plugin assets
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `EmsPubStripePlugin` | Main plugin; handles settings, callbacks, payment verification |
| `EmsPubStripePaymentForm` | Creates Stripe Checkout sessions and redirects users |

---

## Development

### Local Testing

```bash
# Start development server
cd /path/to/ojs
php -S localhost:8000
```

### Test Card Numbers

| Card Number | Behavior |
|-------------|----------|
| `4242 4242 4242 4242` | Successful payment |
| `4000 0000 0000 0002` | Generic decline |
| `4000 0000 0000 3220` | 3D Secure required |
| `4000 0027 6000 3184` | 3D Secure required (authentication fails) |

Use any future expiry date, any 3-digit CVC, and any 5-digit postal code.

### Debug Logging

The plugin writes debug logs to:
```
plugins/paymethod/emspubstripe/debug_log.txt
```

Clear this file periodically in production.

### Git Repository

```
https://github.com/hasanur-rahman079/emspubstripe
```

### Contributing

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/new-feature`
3. Commit your changes: `git commit -m 'Add new feature'`
4. Push to the branch: `git push origin feature/new-feature`
5. Submit a pull request

---

## Troubleshooting

### Payment Not Processing

1. **Check Secret Key**: Ensure the correct secret key is configured (test vs live)
2. **Verify SSL**: Stripe requires HTTPS in production
3. **Check Logs**: Review `debug_log.txt` for error details
4. **Currency Match**: Ensure currency is supported by your Stripe account

### Redirect Issues

1. **Check Return URLs**: Verify URLs in OJS configuration are accessible
2. **Session Timeout**: Stripe sessions expire after 24 hours
3. **Sandbox Mode**: Ensure sandbox mode is disabled in `config.inc.php` for production

### Common Errors

| Error | Solution |
|-------|----------|
| "Invalid API Key provided" | Check your secret key configuration |
| "Payment status is not paid" | User may have cancelled or session expired |
| "Missing session_id parameter" | Stripe redirect failed; check success URL format |

---

## API Reference

### Settings

| Setting Key | Type | Description |
|-------------|------|-------------|
| `testMode` | bool | Enable Stripe test mode |
| `accountName` | string | Display name for account |
| `clientId` | string | Stripe client identifier |
| `secret` | string | Stripe secret API key |

### Dependencies

- [omnipay/omnipay](https://github.com/thephpleague/omnipay) - Payment gateway abstraction
- [meebio/omnipay-stripe](https://github.com/meebio/omnipay-stripe) - Stripe driver for Omnipay

---

## Compatibility

Works with these OJS payment types:
- Publication Fees (APC)
- Submission Fees
- Fast-Track Fees
- Issue Purchase
- Article Purchase
- Subscription Payments

---

## License

GNU GPL v3. See [LICENSE](../../../docs/COPYING) for details.

---

## Support

- **Email**: support@emspub.com
- **GitHub Issues**: [Report a bug](https://github.com/hasanur-rahman079/emspubstripe/issues)

---

*Last updated: December 2024*
