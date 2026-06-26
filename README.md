# Stub — Booking & Appointments for Craft CMS

Stub is a booking and appointment scheduling plugin for Craft CMS 5. Built for service businesses, salons, consultants, and anyone who needs to accept bookings online. Multi-provider scheduling, Stripe payments, and a drop-in frontend booking form — no Commerce dependency required.

## Requirements

- Craft CMS 5.5+
- PHP 8.2+
- `stripe/stripe-php` ^13.0 (included via Composer)

## Installation

```bash
composer require justinholtweb/craft-stub
php craft plugin/install stub
```

After installation, the **Stub** section appears in the CP sidebar with subnav items for Dashboard, Calendar, Bookings, Services, Providers, and Customers.

## Features

### Services
Define bookable services with duration, pricing, buffer times (before/after), capacity per slot, and color coding. Services support soft delete and drag-to-reorder.

### Multi-Provider Scheduling
Each provider (staff member) gets:
- **Weekly schedule** — Set working hours per day of week (in the provider's own timezone)
- **Breaks** — Recurring break periods (lunch, etc.) per day of week
- **Blocked dates** — Date ranges for vacations, holidays, or closures
- **Service assignments** — Choose which services each provider offers
- **Optional Craft user link** — Tie a provider to a Craft user account

### Bookings (Craft Element Type)
Bookings are native Craft elements. This means:
- Full element index with column customization, sorting, and search
- Status filters: Pending, Confirmed, Completed, Cancelled, No Show
- Trash/restore support
- Status transitions with cancellation reason tracking
- Payment status tracking (Unpaid, Paid, Refunded)
- Reference numbers (e.g. `STB-20260215-A1B2`)

### Availability Engine
The slot generation algorithm respects all constraints:
- Provider's weekly schedule and timezone
- Breaks and blocked dates
- Service duration and buffer times (before + after)
- Existing bookings and service capacity
- Minimum notice period and max advance booking window

### Frontend Booking Form
A vanilla JS step wizard (no React/Vue dependency) with 6 steps:

1. **Select Service** — Cards with name, duration, price
2. **Select Provider** — AJAX-loaded providers for the chosen service
3. **Pick Date & Time** — Custom calendar grid + time slot list
4. **Customer Info** — Name, email, phone, notes with client-side validation
5. **Payment** — Stripe Payment Element (if service has a price)
6. **Confirmation** — Reference number and booking summary

Embed in any Twig template:

```twig
{{ craft.stub.bookingForm() }}
```

The customer's browser timezone is auto-detected via `Intl.DateTimeFormat`. All times display in the customer's local timezone on the frontend and the provider's timezone in the CP.

### Stripe Payments
- Direct `stripe/stripe-php` integration (no Commerce required)
- Stripe Payment Element with SCA/3DS support
- Webhook handler with signature verification (`payment_intent.succeeded`, `payment_intent.payment_failed`)
- CSRF disabled on the webhook endpoint
- Free services auto-confirm without payment flow
- All Stripe keys support `$ENV_VAR` syntax for environment variable parsing

### Email Notifications
Three system messages registered in **Utilities → System Messages** (editable by admins):

| Key | Trigger | Recipient |
|-----|---------|-----------|
| `stub_booking_confirmation` | Booking confirmed | Customer |
| `stub_admin_notification` | New booking created | Admin email |
| `stub_booking_cancellation` | Booking cancelled | Customer + Admin |

Template variables: `referenceNumber`, `customerName`, `serviceName`, `providerName`, `dateFormatted`, `timeFormatted`, `priceFormatted`, plus full model objects (`booking`, `service`, `provider`, `customer`).

### Calendar View
FullCalendar v6 integration in the CP with:
- Week, day, and month views
- Provider filter dropdown
- Color-coded events by service
- Click-to-navigate to booking detail

### Dashboard
At-a-glance stats: today's bookings, this week's count, pending confirmations, monthly revenue. Plus a today's schedule list with direct links to each booking.

### Customer Management
- Searchable customer list
- Customer detail view with booking history
- Auto-links to existing Craft users by email
- Find-or-create pattern for returning customers

### Permissions
Six granular permissions:
- `stub:viewBookings`, `stub:manageBookings`, `stub:deleteBookings`
- `stub:manageServices`, `stub:manageProviders`, `stub:manageCustomers`

## Configuration

All settings are in **Settings → Stub** or via `config/stub.php`:

### General
- `pluginName` — Display name in CP sidebar (default: "Stub")
- `defaultCurrency` — 3-letter currency code (default: USD)
- `defaultTimezone` — IANA timezone for new providers (default: America/New_York)
- `minimumNotice` — Minutes before a booking can be made (default: 60)
- `maxAdvanceBooking` — Days into the future bookings are allowed (default: 90)
- `slotInterval` — Minutes between slot start times (default: 15)

### Booking
- `autoConfirmFreeBookings` — Skip pending status for $0 services (default: true)
- `requirePhone` — Make phone field required on booking form (default: false)
- `allowCustomerNotes` — Show notes textarea on booking form (default: true)
- `referencePrefix` — Prefix for reference numbers (default: STB)
- `bookingPageUrl` — URL where booking form is embedded (for email links)
- `termsUrl` — Link to terms & conditions (shows checkbox if set)

### Stripe
- `paymentEnabled` — Enable payment processing (default: false)
- `stripePublishableKey` — Supports `$STRIPE_PUBLISHABLE_KEY` syntax
- `stripeSecretKey` — Supports `$STRIPE_SECRET_KEY` syntax
- `stripeWebhookSecret` — Supports `$STRIPE_WEBHOOK_SECRET` syntax

### Notifications
- `adminEmail` — Email for admin booking alerts
- `sendCustomerConfirmation` — Send confirmation email to customer (default: true)
- `sendAdminNotification` — Send alert to admin on new booking (default: true)
- `sendCancellationEmail` — Send cancellation emails (default: true)

### Appearance
- `primaryColor` — Hex color for booking form UI (default: #2563eb)
- `embedStripeJs` — Auto-load Stripe.js on booking form pages (default: true)

### Anti-Abuse
- `enableHoneypot` — Add a hidden field to the booking form; submissions that fill it are silently dropped (default: true)
- `honeypotFieldName` — `name` attribute used for the honeypot input (default: stub_hp)
- `bookingsPerHour` — Max booking submissions allowed from a single IP per hour; set to 0 to disable (default: 10)
- `paymentIntentsPerHour` — Max create-intent calls allowed from a single IP per hour; set to 0 to disable (default: 30)

The anonymous booking and payment endpoints are protected by three measures: a configurable
**honeypot** field that traps naive bots, per-IP **rate limiting** on both submission and
payment-intent creation, and a signed **payment token**. The payment token is an HMAC of the
booking's identity (keyed by the site security key) returned from the submit response and
required by `payment/create-intent`, so booking IDs can't be enumerated to trigger Stripe
PaymentIntents.

## Twig API

```twig
{# Render the full booking form #}
{{ craft.stub.bookingForm() }}

{# Query bookings #}
{% set upcoming = craft.stub.bookings()
    .bookingStatus('confirmed')
    .startDateTime('>= now')
    .orderBy('startDateTime asc')
    .all() %}

{# Get all services #}
{% set services = craft.stub.services() %}

{# Get providers (optionally filtered by service) #}
{% set providers = craft.stub.providers(serviceId) %}

{# Access settings #}
{% set settings = craft.stub.settings() %}
```

## Stripe Webhook Setup

Set your webhook endpoint in the Stripe dashboard to:

```
https://your-site.com/actions/stub/webhook/handle
```

Listen for these events:
- `payment_intent.succeeded`
- `payment_intent.payment_failed`

## Database Tables

The plugin creates 9 tables (all prefixed `stub_`):

| Table | Purpose |
|-------|---------|
| `stub_services` | Bookable services with pricing and duration |
| `stub_providers` | Staff members with timezone and contact info |
| `stub_provider_schedules` | Weekly working hours per provider |
| `stub_provider_breaks` | Recurring break periods per day |
| `stub_provider_blocked_dates` | Date ranges when provider is unavailable |
| `stub_provider_services` | Junction table: which providers offer which services |
| `stub_customers` | Customer contact info with optional Craft user link |
| `stub_bookings` | Booking element content table (FK to elements) |
| `stub_payments` | Stripe payment records linked to bookings |

## License

This plugin requires a license purchased through the [Craft Plugin Store](https://plugins.craftcms.com). See [LICENSE.md](LICENSE.md).
