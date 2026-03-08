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
├── controllers/
│   ├── AvailabilityController.php  # AJAX: providers, dates, slots (anonymous)
│   ├── BookingFormController.php   # Frontend booking submission (anonymous)
│   ├── BookingsController.php      # CP: booking list/edit/status
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
│   ├── BookingHelper.php           # Reference number generation, price formatting
│   └── TimeHelper.php              # UTC conversion, timezone math, overlap detection
├── migrations/
│   └── Install.php                 # Creates all 9 tables, drops on uninstall
├── models/                         # 8 data models with validation rules
├── records/                        # 9 ActiveRecord classes (one per table)
├── services/
│   ├── Availability.php            # Time slot generation engine
│   ├── Bookings.php                # Booking lifecycle, status transitions, events
│   ├── Customers.php               # Find/create customers, Craft user linking
│   ├── Emails.php                  # Email dispatch via Craft system messages
│   ├── Payments.php                # Stripe PaymentIntent, webhook handling
│   ├── Providers.php               # Provider CRUD + schedule/break/blocked-date mgmt
│   └── Services.php                # Service CRUD with soft delete and reorder
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
