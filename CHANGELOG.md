# Changelog

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
