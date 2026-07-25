# Changelog

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
