# Changelog

## 5.7.0 - 2026-08-31

### Added
- **Frontend service filtering.** `craft.stub.services()` now takes an optional filter, so a
  template can show one author's services rather than every service on the site:
  `craft.stub.services({ user: currentUser })`. Filter by service `id`/`handle`, by
  `provider` (ID, handle or model), or by `user` — the Craft user a provider is linked to —
  and add `includeDisabled` to see disabled ones. Every key accepts one value or a list.
- `craft.stub.bookingForm()` accepts the same filter. It has always taken an `options`
  argument and always ignored it; it no longer does. A form scoped to one service skips the
  "select a service" step, and one scoped to a single provider skips that step too and opens
  on the calendar — pass `pinProvider: false` to keep it. The progress bar and Back buttons
  follow, so a two-step form shows two dots and no dead Back button.
- `craft.stub.service(idOrHandle)` and `craft.stub.provider(idOrHandleOrUser)` for pages
  built around one service or one provider. `provider()` accepts a `User` element directly.
- `Services::getServices()`, `Providers::getProviderByHandle()`,
  `Providers::getProviderByUserId()` and `Providers::getProviderIdsFor()` in PHP.
- An empty state on the booking form's service step. A filtered form can legitimately have
  nothing to offer, and an empty box under a progress bar read as a bug.

### Fixed
- The bookings index returned HTTP 500 whenever the status column was shown. Craft 5 expects
  `statuses()` to return `craft\enums\Color` cases rather than colour strings.
- The **Default Timezone** setting listed languages. The dropdown was built from Craft's
  locale list, so it offered "English (United States)" and could store a locale id as a
  timezone, which then became a new provider's timezone and broke on use. It is now Craft's
  standard timezone picker, and a settings save rejects anything that isn't a real timezone
  identifier — so a site carrying a bad value from the old field is told about it.

### Notes
- Filtering is a **display** concern. The booking endpoints are anonymous and take a service
  ID from the request, so a filtered list does not stop a crafted POST from booking a
  service that was filtered out. It narrows what a visitor sees, not what they may do.
- An unknown filter key throws instead of being ignored, and a filter that resolves to
  nothing — `{ user: currentUser }` on a logged-out request — matches nothing rather than
  everything. Both failure modes would otherwise show a visitor services meant for someone
  else.
- Services are not elements and have no field layout, so there is no custom-field filtering.
  Provider and user are the two ownership axes Stub models.
- No schema change, no migration. `craft.stub.services()`, `craft.stub.providers()` and
  `craft.stub.bookingForm()` with no arguments behave exactly as before, including on a site
  with one service.

## 5.6.0 - 2026-08-13

### Added
- Support for 25 common currencies instead of 5 — CHF, NZD, JPY, SEK, NOK, DKK, PLN, CZK,
  HUF, ZAR, SGD, HKD, INR, BRL, MXN, ILS, AED, TRY, KRW and THB join USD, EUR, GBP, CAD
  and AUD. The list lives in one place (`Currencies::commonCurrencies()`) rather than being
  duplicated across two templates and a symbol map.
- Currencies configured in **Craft Commerce** are offered automatically when Commerce is
  installed, on top of the built-in list. Commerce is not a dependency and nothing about a
  site without it changes.
- `Currencies` service, on `Plugin::getInstance()->currencies`.
- `craft.stub.formatPrice(price, currency)` and `craft.stub.currencies()` for templates.
- `Customers::resolveUser()` — the Craft user behind a customer, by link or by matching
  email. Read-only: it works out who someone is without writing a link.
- `Customers::getCustomerForUser()` — the same question from the other side.
- A **Link Customers to Users** setting, on by default. Stub already attached new customers
  to a matching Craft user; this makes it switchable (a shared household or office address
  shouldn't have to imply one person) and extends it to a customer who *books again* after
  registering an account — previously they stayed two separate people forever.
- `Plugin::emailDefinitions()` — Stub's three system messages, the settings attribute that
  switches each one on, and the variables its body may use, all in one place. It's what
  registers the messages with Craft, and it lets a host bundle list every bundled plugin's
  notifications on one screen instead of one per plugin. No change to the messages
  themselves, their keys, or their default copy.

### Fixed
- **Payments in zero-decimal currencies were charged 100× the price.** `createPaymentIntent()`
  multiplied every price by 100 to get Stripe's minor unit, but JPY, KRW, VND, CLP, XOF and
  eleven others have no minor unit — a ¥1,000 booking would have been billed ¥100,000.
  Three-decimal currencies (BHD, JOD, KWD, OMR, TND) were wrong in the other direction and
  are now rounded to the multiple of 10 Stripe requires. No currency reachable from the old
  five-entry picker was affected, so no existing site can have been overcharged.
- Prices are formatted with the currency's real symbol, decimal count and symbol placement
  via ICU, replacing a five-entry symbol map that rendered anything unknown as `CHF 10.00`
  and put the symbol before the amount even in locales that place it after (`10,00 €`).
- Four templates formatted prices by hand with `|number_format(2)`, bypassing `formatPrice()`
  and hardcoding two decimals. They now go through the same path as everything else.
- `Emails` rendered each system message's subject and body and then threw both away —
  `composeFromKey()` does its own rendering at send time. Harmless, but it meant reading the
  code told you the wrong thing about where the copy came from.

## 5.5.1 - 2026-07-25

### Fixed
- The provider schedule page threw a Twig syntax error (`Unexpected token "name" of value
  "if"`) and would not render. The weekly hours loop used the inline `{% for ... if ... %}`
  form, which Twig 3 removed; it now filters with `|filter()`. This was the last of three
  faults stacked in the same template — each one masked the next, since the lexer error
  fixed in 5.0.4 aborted before the parser ever reached this line.

## 5.5.0 - 2026-07-25

### Added
- `$stripeWebhookRouter` — when Stub runs as a module of a host bundle that owns the Stripe
  account, the host can take over webhook handling so this endpoint and the bundle's verify
  and route identically. Sites that pointed Stripe at `/actions/stub/webhook/handle` before
  bundling keep working, and behave the same as ones using the bundle's endpoint. Null
  (standalone) → Stub handles it itself, unchanged.

## 5.4.0 - 2026-07-25

### Added
- `Availability::EVENT_DEFINE_BUSY_INTERVALS` — other code can now contribute windows in
  which a provider is unavailable, and those windows stop producing bookable slots exactly
  as an existing booking does. Stub only knows about its own bookings, but a provider can be
  busy for reasons it has no concept of: running a class, a synced external calendar, an
  all-hands. Handlers append UTC intervals to `BusyIntervalsEvent::$intervals`.

## 5.3.0 - 2026-07-25

### Added
- `BookingEvent::$isValid` — an `EVENT_BEFORE_SAVE_BOOKING` handler can now refuse a booking
  outright by setting it to false, and add an error explaining why (a generic one is added
  if it doesn't). Previously the event could observe and mutate a booking but not veto it,
  so there was no way to make a service conditional on anything Stub doesn't know about.
  Defaults to true, so existing handlers are unaffected.

## 5.2.0 - 2026-07-25

### Added
- Stub now refuses to install on a site where a host bundle that already includes it is
  installed. Both copies would register the Booking element type and share the `stub_*`
  tables, and uninstalling either would then drop the other's data.
- `Plugin::permissionDefinitions()` exposes Stub's permissions, so a host bundle can list
  them under a single combined heading instead of one heading per bundled plugin. The
  permission keys are unchanged, so existing user groups keep working. Installed standalone,
  Stub registers its own "Stub" heading exactly as before.

## 5.1.1 - 2026-07-25

### Security
- **Stored XSS in the front-end booking form.** Provider cards were built by interpolating
  the AJAX response into an HTML string, and `data-name="${p.name}"` went in raw. The helper
  used for the visible text escaped `&`, `<` and `>` but not quotes, so it would not have
  helped in an attribute either. A provider name containing a double quote could therefore
  break out of the attribute and run script for every visitor of the public booking form —
  reachable by any control-panel user with `stub:manageProviders`, not just admins. Provider
  cards and time slots are now built with `document.createElement` + `textContent` +
  `dataset`, which encode correctly in both text and attribute contexts, so no part of a
  response is interpolated into markup. The unused `escHtml()` helper was removed.

## 5.1.0 - 2026-07-25

### Added
- A mount seam, so Stub can run as an internal module of a host bundle plugin instead of as
  its own installed plugin: a `mountedUnderShowtime` flag plus a `bootFeatures()` /
  `bootChrome()` split, where the host takes over the control-panel nav and settings screen
  and everything else boots identically. Standalone behavior is unchanged — the flag
  defaults to false and `bootChrome()` runs as before.

### Changed
- Widened the `stripe/stripe-php` constraint to `^13.0 || ^14.0 || ^15.0 || ^16.0`. It was
  pinned to `^13.0`, which conflicted with other plugins on a newer major and forced
  Composer to resolve the whole project down to Stripe 13.

## 5.0.4 - 2026-07-25

### Fixed
- The provider schedule page threw a Twig syntax error (`Unexpected character ";"`) and would
  not render. An unescaped apostrophe in a single-quoted string left the lexer with an
  unterminated string, which cascaded through the rest of the file until it hit a character
  that was invalid in an expression — so the reported line was 121 lines past the actual fault.
- The weekly hours day toggles were inert and always appeared switched on. The markup was
  hand-rolled with a hardcoded `on` class and none of the structure `Craft.LightSwitch` binds
  to, so a day's stored enabled state was never reflected and clicking the switch did nothing.
  Replaced with Craft's `lightswitch` macro, labelled by its day cell.

## 5.0.3 - 2026-07-24

### Fixed
- Saving a service always failed with "Couldn't save service." Craft's color input posts the
  hex without a leading `#`, but the `color` rule required one, so validation could never
  pass. The same rule blocked provider saves and the plugin settings' primary color.
- The New Provider page threw a Twig runtime error (`Calling unknown method:
  craft\i18n\I18N::allTimezonesByGroup()`). Replaced the hand-rolled select with Craft's
  `timeZoneField`.

## 5.0.2 - 2026-07-22

### Updated

- Masked icon replaced, thanks for noticing Jalen Davenport

## 5.0.1 - 2026-07-22

### Fixed
- The Bookings index rendered its markup as escaped text instead of an element index.
- The Calendar page rendered outside the CP content area.
- Dashboard "Today's Bookings" and "This Week" counts were wrong. Each applied only the
  second of two `startDateTime()` calls, so the lower bound was silently dropped and every
  earlier booking was counted.
- Dashboard periods, and the monthly revenue total, are now calculated in the system
  timezone rather than UTC, so "today" means today for whoever is looking at the page.
- Booking times on the dashboard, customer detail, and booking edit pages were shown in the
  wrong timezone. The stored value is a naive UTC string, and Twig's `date` filter was
  reading it as server-local before converting.
- The calendar feed dropped bookings that straddled the edge of the visible range, and
  double-converted its range bounds to UTC.
- Removed a stale FullCalendar v5 stylesheet URL that 404'd on every calendar page load.

### Added
- `TimeHelper::periodBounds()`, covering the day/week/month window math the dashboard
  relies on, with unit tests for timezone anchoring, DST transitions, week rollover, and
  month lengths.

## 5.0.0 - 2026-07-19

### Added
- Initial release
- Service management with duration, pricing, and capacity
- Multi-provider support with individual schedules, breaks, and blocked dates
- Booking element type with full Craft CMS element index integration
- Frontend booking form with step-by-step wizard (vanilla JS)
- Stripe payment integration with Payment Element and webhook support
- Calendar view with FullCalendar v6
- Dashboard with booking stats and revenue overview
- Customer management with booking history
- Email notifications for confirmations, admin alerts, and cancellations
- Time slot generation engine with timezone support
- User permissions for bookings, services, providers, and customers
- Anti-abuse protection for the public booking form: configurable honeypot field, per-IP
  rate limiting on booking submission and payment-intent creation, and HMAC-signed payment
  tokens that prevent booking-ID enumeration against the Stripe create-intent endpoint
- Unit test suite (Pest) covering payment-token signing, the rate-limit decision, and the
  price/duration/timezone helpers

### Security
- The anonymous `payment/create-intent` endpoint now requires a payment token bound to the
  booking, closing an enumeration vector where any booking ID could trigger a PaymentIntent
