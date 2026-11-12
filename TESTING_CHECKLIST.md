# AquaTrack — Acceptance / Deployment Testing Checklist

Unchecked boxes are **not** a claim of failure; they indicate tests to perform with your actual MySQL, Apache/LiteSpeed, browser and Hostinger hosting.

## Offline validation done during package generation

- [x] PHP syntax check for generated PHP files (PHP CLI 8.4; no app database connection).
- [x] Node syntax check for existing JavaScript files.
- [x] Native PHP linear-regression numeric math smoke tests.
- [x] Reviewed module SQL fields against original `aquatrack_db-4.sql` schema.

## Required acceptance testing (not yet executed)

- [ ] Login/logout for Admin, Staff; session expiration; inactive account denied.
- [ ] Password reset email arrives; token expires; token cannot be reused.
- [ ] Admin-only access to Admin pages and restricted Staff permissions.
- [ ] CRUD for staff, customers, sellable products, inventory supplies.
- [ ] Stock In/Out/Adjustment, preventing negative stock and recording movements.
- [ ] Multi-line order creation, duplicate item rejection, order status transitions.
- [ ] Prevent cancelling a paid order or completing an uncompleted delivery.
- [ ] Delivery scheduling, staff assignment and staff-only update to their own deliveries.
- [ ] Completing a delivery creates exactly one completed order and appropriate sale.
- [ ] Partial/full payment; no overpayment; no duplicate sale on concurrent saves.
- [ ] Daily, weekly, monthly reports and CSV exports; escaped formula-like strings.
- [ ] Forecast insufficient-data threshold, backtests, and plausible output on real history.
- [ ] Database backup and restore drill.
- [ ] HTTPS, safe cookies, file-path deny rules, error log and credentials protection.
- [ ] Responsive UI tests on mobile and desktop across all modules.
- [ ] Security tests: SQL injection, XSS, CSRF, access bypass, auth brute force, secrets disclosure.
- [ ] Hostinger staging deployment and rollback test.

**Production status:** NOT YET VERIFIED. Do not describe as production certified.
