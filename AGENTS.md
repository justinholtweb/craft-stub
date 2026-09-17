# Agents Guide — Stub (Craft CMS Plugin)

This file provides context for AI agents working on the Stub codebase.

## Project Overview

Stub is a paid booking & appointments plugin for Craft CMS 5. It lets service businesses accept online bookings with multi-provider scheduling, Stripe payments, and email notifications. Target price: $59–79 single edition on the Craft Plugin Store.

**Tech stack**: PHP 8.2+, Craft CMS 5.5+, Yii2, Twig, vanilla JS, Stripe (`stripe/stripe-php` ^13.0)
**Namespace**: `justinholtweb\stub`
**Package**: `justinholtweb/craft-stub`
**License**: Proprietary (Craft License)

## Architecture

### Entry Point
`src/Plugin.php` — Extends `craft\base\Plugin`. Registers all components, routes, element types, permissions, variables, and email system messages in `init()`.

### Architectural Pattern
Craft CMS plugins follow a **Model-Record-Service-Controller** pattern built on Yii2:

- **Models** (`src/models/`) — Plain data objects with validation rules. NOT ActiveRecord.
- **Records** (`src/records/`) — Yii2 ActiveRecord classes, one per database table. Used only for DB read/write, never passed to templates.
- **Services** (`src/services/`) — Business logic layer. Registered as Yii2 components on the plugin. Accessed via `Plugin::getInstance()->serviceName`.
- **Controllers** (`src/controllers/`) — Handle HTTP requests. CP controllers require permissions. Frontend controllers use `$allowAnonymous`.
- **Elements** (`src/elements/`) — Craft's content objects. `Booking` is the only element type. Gets native index, search, statuses, trash/restore.

### Key Distinction: Elements vs ActiveRecord
- **Booking** = Craft Element Type. Stored in both `elements` (Craft core) and `stub_bookings` (plugin content table). Saved via `Craft::$app->getElements()->saveElement()`.
- **Services, Providers, Customers** = ActiveRecord models. Configuration/operational data, not content. Saved via record `->save()`.

### Database
9 tables prefixed `stub_`. Schema defined in `src/migrations/Install.php`. The `stub_bookings` table has `id` as a FK to `elements.id` (CASCADE delete). All other tables use auto-increment PKs.

## Directory Map

```
src/
├── Plugin.php                      # Plugin entry point, component registration
├── assetbundles/
│   ├── booking/                    # Frontend booking form (JS + CSS)
│   ├── calendar/                   # FullCalendar v6 integration (CP)
│   └── cp/                         # CP-wide styles and scripts
├── console/controllers/
│   └── RemindersController.php     # `stub/reminders/send` — the cron entry point
├── controllers/
│   ├── AvailabilityController.php  # AJAX: providers, dates, slots (anonymous)
│   ├── BookingFormController.php   # Frontend booking submission (anonymous)
│   ├── BookingsController.php      # CP: booking list/edit/status/manual entry
│   ├── CalendarController.php      # CP: calendar view + JSON event feed
│   ├── CustomersController.php     # CP: customer list/detail
│   ├── DashboardController.php     # CP: stats overview
│   ├── PaymentController.php       # Frontend: Stripe PaymentIntent (anonymous)
│   ├── ProvidersController.php     # CP: provider CRUD + schedule management
│   ├── ServicesController.php      # CP: service CRUD + reorder
│   ├── SettingsController.php      # CP: plugin settings
│   └── WebhookController.php       # Stripe webhook (anonymous, CSRF disabled)
├── elements/
│   ├── Booking.php                 # Craft element type with custom statuses
│   └── db/BookingQuery.php         # Element query with booking-specific filters
├── enums/
│   ├── BookingStatus.php           # pending|confirmed|completed|cancelled|noShow
│   └── PaymentStatus.php           # unpaid|paid|refunded
├── events/
│   ├── BookingEvent.php            # Fired on booking save/status change
│   └── PaymentEvent.php            # Fired on payment completion
├── helpers/
│   ├── BookingHelper.php           # Reference number generation, price formatting (delegates to Currencies)
│   └── TimeHelper.php              # UTC conversion, timezone math, overlap detection
├── migrations/
│   ├── Install.php                 # Creates all 9 tables, drops on uninstall
│   └── m*_*.php                    # Versioned migrations for existing installs
├── models/                         # 9 data models with validation rules
│   └── ServiceCriteria.php         # Pure normalizer for frontend service filters
├── records/                        # 9 ActiveRecord classes (one per table)
├── services/
│   ├── Availability.php            # Time slot generation engine
│   ├── Bookings.php                # Booking lifecycle, status transitions, events
│   ├── Currencies.php              # Currency list, ICU formatting, Stripe minor units
│   ├── Customers.php               # Find/create customers, Craft user linking
│   ├── Emails.php                  # Email dispatch via Craft system messages
│   ├── Payments.php                # Stripe PaymentIntent, webhook handling
│   ├── Providers.php               # Provider CRUD + schedule/break/blocked-date mgmt
│   ├── Reminders.php               # Due-reminder sweep, driven by the console command
│   └── Services.php                # Service CRUD with soft delete, reorder and filtering
├── templates/                      # Twig templates (CP + frontend)
├── translations/en/stub.php        # English translation strings
└── variables/StubVariable.php      # craft.stub.* Twig API
```

## Critical Paths

### Booking Flow (Frontend)
1. Customer selects service → `AvailabilityController::actionGetProviders()`
2. Customer selects provider → `AvailabilityController::actionGetDates()` + `actionGetSlots()`
3. Customer fills info → client-side validation in `booking-form.js`
4. Submit → `BookingFormController::actionSubmit()` → creates Booking element + Customer record
5. If paid: `PaymentController::actionCreateIntent()` → Stripe Payment Element → `stripe.confirmPayment()`
6. Stripe webhook → `WebhookController::actionHandle()` → `Payments::handleWebhookEvent()` → confirms booking + sends emails
7. If free: auto-confirms immediately in step 4

### Manual Booking Flow (CP)
1. **Bookings → New Booking** → `BookingsController::actionNew()`
2. Service/provider/date chosen → the form's JS calls the same
   `stub/availability/get-slots` endpoint the front-end form uses
3. Submit → `BookingsController::actionCreate()` re-validates the slot server-side
   (the picker was populated seconds ago; a customer may have taken it since)
4. `Customers::findOrCreate()` → `Bookings::createManualBooking()`, which fires the same
   save events as a front-end booking
5. **Override availability** skips step 3 entirely, along with the provider↔service check

### Reminder Sweep (`stub/reminders/send`)
1. Cron runs the console command; it exits early if both reminder settings are off
2. `Reminders::getDueBookings()` — confirmed, not yet reminded, starting inside
   `[now, now + leadTime]`
3. Each booking is **claimed** with `UPDATE ... WHERE reminderSentAt IS NULL` before its
   email is composed, so overlapping runs can't duplicate
4. `Emails::sendReminder()` — one copy to the customer, one to the provider and admin

### Availability Algorithm (`Availability::getAvailableSlots`)
1. Load service (duration, buffers, capacity) and provider (timezone)
2. Check blocked dates → return `[]` if blocked
3. Get schedule for day of week → return `[]` if disabled
4. Convert working hours to UTC
5. Get breaks, convert to UTC
6. Query active bookings (pending/confirmed) overlapping the window
7. Generate candidate slots at `slotInterval` intervals
8. Reject if: in the past, overlaps break, exceeds capacity (with buffer zones)
9. Return surviving slots formatted in customer's timezone

### Status Transitions
`pending` → `confirmed` → `completed`
`pending` or `confirmed` → `cancelled`
`confirmed` → `noShow`

Transitions happen in `Bookings::updateStatus()` which fires events and triggers emails.

## Conventions

### Naming
- PHP: PSR-4, `justinholtweb\stub` namespace
- Tables: `stub_` prefix, snake_case
- Handles: camelCase (services, providers)
- Enums: PHP 8.1 backed enums
- Templates: underscore-prefixed partials (`_edit.twig`, `_index.twig`)

### Craft CMS Patterns to Follow
- Use `Craft::$app->getDb()->createCommand()` for raw queries, `craft\db\Query` for selects
- Use `Craft::$app->getElements()->saveElement()` for element types, never `$record->save()` directly on elements
- Parse environment variables with `craft\helpers\App::parseEnv()` for Stripe keys
- Register CP routes via `UrlManager::EVENT_REGISTER_CP_URL_RULES`
- Register permissions via `UserPermissions::EVENT_REGISTER_PERMISSIONS`
- Register element types via `Elements::EVENT_REGISTER_ELEMENT_TYPES`
- Use `craft\db\SoftDeleteTrait` on records that need soft delete
- CP templates extend `_layouts/cp` and use `{% import '_includes/forms' as forms %}`
- AJAX responses use `$this->asJson()`
- Frontend controllers use `protected array|int|bool $allowAnonymous = [...]`
- Webhook controllers set `public $enableCsrfValidation = false`

### Frontend Service Filtering
- `craft.stub.services()` and `craft.stub.bookingForm()` take a filter hash. It is normalized
  by `ServiceCriteria::fromArray()` before any query runs — keep that class **pure** (no DB,
  no booted Craft), which is what makes its rules unit-testable.
- Two rules exist to stop a filter failing open, and both are load-bearing: an **unknown key
  throws** rather than being ignored, and a filter that was **asked for but resolved to
  nothing matches nothing** (`matchesNothing`), rather than falling back to everything. The
  failure mode both prevent is showing a visitor another author's services.
- Provider handles and Craft user IDs need a query to become provider IDs, so `ServiceCriteria`
  carries them unresolved and `Providers::getProviderIdsFor()` does the lookup.
- Filtering is **presentation, not authorization.** `AvailabilityController` and
  `BookingFormController` are anonymous and take a service ID from the request. Never describe
  a filter as restricting what someone can book, and don't build an access-control feature on
  top of it without server-side checks in those controllers.
- The booking form's six steps are indexed by DOM order, so a skipped step still renders — it
  is just never navigated to and gets no progress dot. If you add or reorder steps, keep
  `isStepSkipped()`, `previousStep()` and the template's `skippedSteps`/`entryStep` in sync.

### Reminders
- Nothing schedules itself. `stub/reminders/send` on a cron is the only thing that sends
  reminders, which is why both settings default to **off** — switching them on without the
  cron promises an email that never arrives.
- The due window is `[now, now + leadTime]` and deliberately **never reaches backwards**: a
  sweep that hasn't run for days must not mail people about appointments they've been to.
  `Reminders::dueWindow()` is pure and takes `$now`, which is what makes that testable.
- A booking is **claimed** before its email is composed, not after it's sent. The trade is
  deliberate — a duplicate reminder is worse than a missing one, so a send that then fails
  is logged and not retried.
- `reminderSentAt` is written with a direct `UPDATE`, not `saveElement()`. It's bookkeeping;
  routing it through the element would fire save events, touch `dateUpdated` and re-index.
- `startDateTime` is compared with raw UTC strings here, **not** the element query's date
  param — `Db::parseDateParam()` reads params as system-local. Same reasoning as
  `Bookings::getBookingStats()` and its `paidAt` bounds.

### Manual Bookings
- `createManualBooking()` differs from `createBooking()` in exactly one way: who decides the
  two statuses. Everything else, including both save events, is shared via `_newBooking()`
  and `_saveNew()` — keep it that way, so integrations see CP bookings too.
- Availability is re-checked **server-side** on submit. The slot picker is populated by an
  AJAX call that may be seconds stale, so trusting the posted value would let the CP create
  the conflicts the front end refuses.
- **Override availability** is a real override, not a looser check: no slot check, no
  schedule, breaks or blocked dates, no capacity or buffers, and no provider↔service check.
  It exists to double-book on purpose. Don't quietly add validation back into that branch.
- `Providers::getServiceIdsForProvider()` returns whatever the driver hands back, and MySQL
  hands back **strings**. Anything comparing those IDs strictly — PHP or the form's JS —
  has to cast first.

### Currency Handling
- The currency list has ONE source: `Currencies::commonCurrencies()`. Never hardcode a
  currency list in a template again — pass `currencyOptions` from the controller instead.
- Never write `price * 100` for a Stripe amount. Zero-decimal currencies (JPY, KRW, VND,
  CLP, XOF, …) have no minor unit and would be charged 100× too much. Use
  `Currencies::toMinorUnits()`, which follows **Stripe's** table, not ICU's — the two
  disagree (ICU calls ISK zero-decimal; Stripe bills it in aurar).
- Never format a price with `|number_format(2)` or a symbol map. Two decimals and
  symbol-before-amount are both wrong for plenty of currencies. Use
  `craft.stub.formatPrice()` in Twig or `Currencies::format()` in PHP; ICU knows the
  symbol, decimal count and placement for every code.
- `Currencies` splits deliberately: **static** methods are pure currency facts and work
  without a booted Craft (so they're unit-testable); **instance** methods answer "what can
  this site offer?" and need the app.
- Craft Commerce is read defensively — it is NOT a dependency. Every call is behind
  `isPluginEnabled` / `method_exists` / try-catch, and any failure degrades to the built-in
  list rather than taking the settings screen down.
- Always pass the currently-saved code into `getCurrencyOptions($current)` so a record
  priced in a currency that's since disappeared from Commerce keeps its own value.
- ICU separates a letter-code symbol from the amount with a **non-breaking space**
  (`CHF<NBSP>10.00`). Tests asserting on formatted output must use `\u{A0}`, not a space.

### Timezone Handling
- Provider schedules are stored in the provider's IANA timezone
- Bookings store `startDateTime`/`endDateTime` in UTC
- Bookings store `timezone` (the customer's IANA timezone) for display
- All availability calculations convert to UTC for comparison
- Frontend auto-detects via `Intl.DateTimeFormat().resolvedOptions().timeZone`

### Frontend JS
- Vanilla JS only, no framework dependency
- `StubBookingForm` class manages state and step navigation
- AJAX calls go to `/actions/stub/...` endpoints
- CSRF token passed in request body (read from `data-csrf` attribute)
- No jQuery on the frontend; jQuery is available in CP templates

## Testing Checklist

When making changes, verify:
1. Plugin installs/uninstalls cleanly (tables created/dropped)
2. Services CRUD works (create, edit, reorder, soft-delete)
3. Providers with schedules, breaks, blocked dates, service assignments
4. Availability returns correct slots (respects all constraints)
5. Booking form works end-to-end for free services
6. Stripe test mode works with card `4242 4242 4242 4242`
7. Emails send on confirmation, new booking, and cancellation
7b. `stub/reminders/send --dry-run` lists the right bookings; a real run sends once and
    stamps `reminderSentAt`; a second run immediately after sends nothing
7c. A manual booking saves from **Bookings → New Booking**, respects availability, and
    creates any time at all with **Override availability** on
8. Calendar displays bookings with provider filter
9. Cross-timezone booking displays correctly for both customer and provider
10. CP permissions restrict access appropriately

## Common Tasks

### Adding a new setting
1. Add property to `src/models/Settings.php` with default value and validation
2. Add form field to `src/templates/settings.twig`
3. Use via `Plugin::getInstance()->getSettings()->yourSetting`

### Adding a new CP page
1. Add route in `Plugin::_registerCpRoutes()`
2. Create controller action
3. Create template in `src/templates/`
4. Add subnav item in `Plugin::getCpNavItem()` if top-level

### Adding a new table
1. Add creation method in `src/migrations/Install.php` (both `safeUp` and `safeDown`)
2. Create Record class in `src/records/`
3. Create Model class in `src/models/`
4. Add service methods for CRUD
5. Bump `$schemaVersion` in `Plugin.php`
6. Create a versioned migration in `src/migrations/` for existing installs

### Modifying the booking form
- A form may open on a step other than the first — see Frontend Service Filtering
- Steps are in `src/templates/frontend/_steps/`
- JS logic is in `src/assetbundles/booking/booking-form.js`
- CSS is in `src/assetbundles/booking/booking-form.css`
- The `StubBookingForm` class manages all state — add new data properties there

### Adding a new email notification
1. Register the message in `Plugin::_registerEmailMessages()`
2. Add send method to `src/services/Emails.php`
3. Wire the trigger into the appropriate lifecycle method in `Bookings` or `Payments` service
4. Add a setting toggle in `Settings.php` and `settings.twig`

## Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `craftcms/cms` | ^5.5 | Craft CMS framework |
| `stripe/stripe-php` | ^13.0 | Stripe API client |

No frontend build step. No npm dependencies. JS and CSS are shipped as source files in `src/assetbundles/`.

## Files That Should Not Be Modified Casually

- `src/migrations/Install.php` — Changing this affects new installs. Existing installs need separate versioned migrations.
- `src/elements/Booking.php` — Element types are deeply integrated with Craft. Changes here can break indexes, search, and queries.
- `src/elements/db/BookingQuery.php` — Must stay in sync with `Booking.php` and the `stub_bookings` schema.
- `composer.json` — Namespace and plugin class path are critical. Changing breaks autoloading.
