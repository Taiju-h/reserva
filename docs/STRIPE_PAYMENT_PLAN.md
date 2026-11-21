# Hanamaru: Stripe Checkout payment integration plan

Status: design only. No live charges, no Stripe secrets, and no payment routes are enabled.

## Decision
Use Stripe-hosted Checkout for initial one-time payment in JPY. Card details are never received by this server. Stripe account and onboarding are required before implementation can run. Do not enable charging until the account owner approves the merchant and tax/receipt setup.

## Booking/payment lifecycle
1. User chooses menu/date/time, enters contact information and accepts relevant terms.
2. Backend runs BEGIN IMMEDIATE; validates active menu, server-side price, availability, and hold deadline, then creates booking in `pending_payment` and a uniquely identified payment attempt. Holds expire in ~15 minutes.
3. Backend creates a Checkout Session with idempotency key, server-calculated integer JPY amount, booking ID as opaque metadata/reference, fixed success/cancel return URLs, and a suitable expiration. Persist Checkout session ID and payment attempt status. If session creation fails, release the hold safely.
4. Browser redirects to Stripe-hosted Checkout. Never assume the success URL implies payment.
5. Stripe webhook verifies `Stripe-Signature` on the raw HTTP request body using the endpoint-specific secret, logs event IDs idempotently, rechecks expected session/amount/currency/booking ID, and atomically transitions a pending hold to `confirmed` ONLY for verified paid events.
6. Webhooks can be delayed, repeated, or out of order; use unique event IDs and conditional updates. Handle expiration, failed payment, and refund separately. If payment arrives after a hold has expired and slot is reallocated, do not double-book: flag for manual remediation/refund.
7. Send confirmation mail asynchronously from the confirmed DB state, avoiding duplicate sends.
8. Customer return page polls booking status and displays pending until server confirms payment. Do not expose customer data using guessable booking IDs.

## Minimum schema additions (migration, NOT automatic schema overwrite)
- bookings: status enum including pending_payment, confirmed, cancelled, expired; hold_expires_at, paid_at, confirmed_at, price_snapshot_jpy, currency
- payment_attempts: unique checkout_session_id, booking_id, amount_jpy, currency, status, created_at, finalized_at
- stripe_webhook_events: event_id PK, type, processed_at, result
- notification_outbox: unique booking+event combination, retry policy
- admin action audit log for refunds, cancellation and manual status updates

## Critical invariants and constraints
- Race-safe time overlap considers both confirmed bookings and unexpired payment holds.
- Never trust price or menu duration from browser inputs.
- Never store raw card number, CVC, or Stripe secret in GitHub or client JS.
- Refund must be authorized on the server and needs a separate policy for cancellation cutoffs.
- Stripe test mode first; 3DS cases, duplicate delivery, payment after hold expiration, refund, declined card and webhook outage tests required.
- Keep rate limiting, CSRF on browser calls, authenticated admin, least-privilege Stripe keys, HTTPS, backups, webhook signature verification and alerting.
- Consent/privacy/tax receipt wording must be approved before public use.
- Confirm Stripe Checkout expiry range supports intended hold duration, or revise flow accordingly; avoid creating a hold longer than the server allows.
- Deploy requires explicit migration and rollback plan because SQLite changes should not be reverted automatically by code rollback.

## Environment variables (names only; never commit values)
STRIPE_SECRET_KEY
STRIPE_WEBHOOK_SECRET
STRIPE_PUBLISHABLE_KEY
STRIPE_MODE=test
APP_BASE_URL=https://hanamaru.uzero.style

## Configuration to ask merchant
- Existing Stripe account? If not, create and complete Japanese merchant review.
- Charge 5,500 JPY upfront or allow pay-later?
- Cancellation and refunds policy.
- Receipt/invoice issuer legal name and tax treatment.
- Mail sending provider and sender address.

## Phase 1 completion criterion
A test-mode card payment for the consultation moves pending_payment -> confirmed via a valid webhook; an unpaid reservation expires, duplicates cannot confirm a second booking, and the admin dashboard shows payment state. Production use only after independent validation.
