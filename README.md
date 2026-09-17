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

### Manual Bookings
Bookings can be entered in the control panel as well as through the front-end form — a phone
call, a walk-in, a regular whose slot never changes. **Bookings → New Booking** asks for the
service, provider, date and time, and the customer's details.

The time picker is populated by the same availability engine the front-end form uses, so the
default is a genuinely free slot. **Override availability** replaces it with a plain time
field and turns every check off, which is how you double-book a provider, book outside their
hours, book a service they aren't assigned to, or record something that already happened.

The booking's status and payment status are set by hand — no payment is taken here, so a
booking you've already been paid for is marked paid yourself. Sending the customer their
confirmation email is a switch on the form, and the admin notification is deliberately never
sent: whoever filled the form in is the admin.

Creating a booking this way requires the `stub:manageBookings` permission.

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

Steps 1 and 2 drop out when a filter has already decided the service or the provider — see
[Filtering Services](#filtering-services).

The customer's browser timezone is auto-detected via `Intl.DateTimeFormat`. All times display in the customer's local timezone on the frontend and the provider's timezone in the CP.

### Stripe Payments
- Direct `stripe/stripe-php` integration (no Commerce required)
- Stripe Payment Element with SCA/3DS support
- Webhook handler with signature verification (`payment_intent.succeeded`, `payment_intent.payment_failed`)
- CSRF disabled on the webhook endpoint
- Free services auto-confirm without payment flow
- All Stripe keys support `$ENV_VAR` syntax for environment variable parsing

### Email Notifications
Five system messages registered in **Utilities → System Messages** (editable by admins):

| Key | Trigger | Recipient |
|-----|---------|-----------|
| `stub_booking_confirmation` | Booking confirmed | Customer |
| `stub_admin_notification` | New booking created | Admin email |
| `stub_booking_cancellation` | Booking cancelled | Customer + Admin |
| `stub_booking_reminder` | Lead time before the appointment | Customer |
| `stub_booking_reminder_internal` | Lead time before the appointment | Provider + Admin |

Template variables: `referenceNumber`, `customerName`, `customerEmail`, `serviceName`, `providerName`, `dateFormatted`, `timeFormatted`, `priceFormatted`, `timezone`, plus full model objects (`booking`, `service`, `provider`, `customer`).

### Reminder Emails
A reminder goes out a configurable number of hours before the appointment — 24 by default.
Only **confirmed** bookings are reminded, each exactly once, and never after the appointment
has started.

Craft has no scheduler, so reminders need a cron entry. Nothing is sent without one, which is
why both reminder switches are **off by default**:

```cron
0 * * * * /path/to/craft stub/reminders/send
```

Hourly is plenty for a 24-hour lead time. The sweep is safe to run at any interval and safe
to run twice at once — it claims each booking with an atomic update before composing its
email, so an overlapping run can't send a second copy.

```bash
craft stub/reminders/send            # send everything due
craft stub/reminders/send --dry-run  # list what would go out
craft stub/reminders/send --limit=50 # cap one run
```

A single booking's reminder can also be sent on demand from its detail page in the control
panel, which is the way to check the copy — and the fallback for a site with no cron.

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

## Currencies

The currency picker offers 25 common currencies (USD, EUR, GBP, CHF, CAD, AUD, NZD, JPY,
SEK, NOK, DKK, PLN, CZK, HUF, ZAR, SGD, HKD, INR, BRL, MXN, ILS, AED, TRY, KRW, THB). Each
service can override the site default, and prices are formatted with the currency's own
symbol, decimal count and symbol placement.

**With Craft Commerce installed**, any currency configured on a Commerce store is offered
too, automatically. Commerce is not a dependency — nothing changes on a site without it.

A currency already saved on a service is always kept in its picker, even if it's since been
removed from Commerce, so editing that service can't silently re-price it.

In templates:

```twig
{{ craft.stub.formatPrice(service.price, service.currency) }}  {# CHF 10.00 #}
{{ craft.stub.currencies() }}                                  {# { USD: 'US Dollar', … } #}
```

In PHP:

```php
use justinholtweb\stub\services\Currencies;

Currencies::format(1000.0, 'JPY');        // ¥1,000
Currencies::toMinorUnits(1000.0, 'JPY');  // 1000 — what Stripe charges
Plugin::getInstance()->currencies->getAvailableCurrencies();
```

> **Stripe:** which currencies your account can actually settle in depends on the country
> it's registered in. Stub will happily price a service in any valid code; if Stripe rejects
> it, that's an account limitation rather than a plugin one.

## Configuration

All settings are in **Settings → Stub** or via `config/stub.php`:

### General
- `pluginName` — Display name in CP sidebar (default: "Stub")
- `defaultCurrency` — 3-letter ISO 4217 currency code (default: USD). See [Currencies](#currencies).
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
- `sendCustomerReminder` — Send the customer a reminder before their appointment (default: false)
- `sendInternalReminder` — Send the same reminder to the provider and the admin address (default: false)
- `reminderLeadTime` — Hours before the appointment that reminders go out (default: 24, max: 336)

Both reminder switches are off by default because reminders need `stub/reminders/send` on a
schedule; turning them on without one promises an email that never arrives.

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

{# …or only some of them — see Filtering Services #}
{% set services = craft.stub.services({ user: currentUser }) %}

{# One service, by handle or ID #}
{% set service = craft.stub.service('consultation') %}

{# Get providers (optionally filtered by service) #}
{% set providers = craft.stub.providers(serviceId) %}

{# One provider, by ID, handle, or the Craft user they're linked to #}
{% set provider = craft.stub.provider(currentUser) %}

{# Access settings #}
{% set settings = craft.stub.settings() %}
```

## Filtering Services

`craft.stub.services()` and `craft.stub.bookingForm()` both take an optional filter, so a
template can show one author's services, a named handful, or a single service.

```twig
{# Every service a provider offers — by handle, ID, or Provider model #}
{% set services = craft.stub.services({ provider: 'jane' }) %}

{# …or by the Craft user that provider is linked to #}
{% set services = craft.stub.services({ user: currentUser }) %}

{# Named services, in the order the control panel sorts them #}
{% set services = craft.stub.services({ handles: ['consultation', 'follow-up'] }) %}

{# A booking form scoped the same way #}
{{ craft.stub.bookingForm({ user: currentUser }) }}
{{ craft.stub.bookingForm({ handle: 'consultation' }) }}
```

| Key | Takes |
|-----|-------|
| `id` / `ids` | Service IDs |
| `handle` / `handles` | Service handles |
| `provider` / `providers` | A provider ID, handle, or Provider model |
| `user` / `users` | A Craft `User` element or its ID — matched against the provider's linked user |
| `includeDisabled` | `true` to include disabled services and providers |

Every key accepts one value or a list, and they combine (all conditions must hold). Two
behaviours are deliberate and worth knowing:

- **An unknown key throws** rather than being ignored. A typo'd `{ providor: … }` that
  quietly returned every service would be the worst possible failure mode.
- **A filter that resolves to nothing matches nothing.** `{ user: currentUser }` on a
  logged-out request returns `[]`, not the full list.

When a filter leaves exactly one service, `bookingForm()` pins it and drops the "select a
service" step; when a provider filter matches exactly one provider, that step goes too, and
the form opens on the calendar. Pass `pinProvider: false` to keep the provider step.

> **This is a display filter, not access control.** The booking endpoints are anonymous and
> take a service ID from the request, so narrowing what a visitor is shown does not stop a
> crafted POST from booking a service that was filtered out.

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
