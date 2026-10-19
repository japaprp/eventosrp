# Reservation payment reconciliation

## Scope

This workflow periodically asks the EventosRP API to reconcile at most five expired pending reservation holds per run. It does not deploy the application, create a checkout, issue refunds, transfer money, or change the database schema.

The endpoint preserves the conservative payment lifecycle rules: it checks Mercado Pago payment records and checkout preference expiry, keeps the reservation blocked when provider data is incomplete or uncertain, and serializes the final expiry decision against reservation/webhook writes.

## Enable only after review

1. Review and merge the prerequisite reservation-agreement and payment-lifecycle changes in their documented dependency order.
2. Configure these GitHub Actions repository secrets; never commit their values:
   - `EVENTOSRP_API_URL`: the canonical HTTPS base URL for the deployed EventosRP API (without `/api.php`).
   - `RESERVATION_RECONCILE_SECRET`: a randomly generated secret with at least 32 characters. Set the same value in the API host's private environment variables.
3. Confirm that the API host has `RESERVATION_RECONCILE_SECRET`, valid database access, and valid Mercado Pago credentials before manually running the workflow.
4. Use `workflow_dispatch` for the first controlled run and inspect application logs and reservation/payment state before relying on the 15-minute schedule.

If either GitHub secret is absent, the workflow skips without making an API request. The API endpoint returns 503 until its server-side secret is configured and rejects requests without the matching bearer token. Use HTTPS only.

## Operational limits

- Each invocation attempts at most five due reservations to bound execution time.
- GitHub scheduled workflows may run later than their nominal schedule; this is best-effort reconciliation, not a hard real-time guarantee.
- Unknown, pending, in-process, approved, refunded, disputed, incomplete, or unavailable provider states must not release a hold automatically.
- A payment approved after the reservation expired or conflicts with another active reservation requires manual refund handling; this code does not issue refunds.
- Existing pending reservations without `hold_expires_at` are not expired retroactively.
- Monitor workflow failures and application logs. Do not enable real checkout/commissions or treat this scheduler as proof that all financial edge cases have passed end-to-end tests.
