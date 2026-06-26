# Changelog

## 1.0.0 - Unreleased

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
