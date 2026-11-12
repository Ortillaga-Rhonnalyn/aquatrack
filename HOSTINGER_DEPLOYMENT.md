# AquaTrack — Hostinger Deployment Guide

**Do not purchase hosting before verifying the actual plan includes the necessary PHP/MySQL features.** The steps below are designed for PHP/MySQL hosting with hPanel and phpMyAdmin; specific labels may change.

## Before upload

1. Fully test the integrated package on an isolated staging copy of XAMPP. Record test evidence.
2. Export a complete **backup** of your existing `aquatrack_db` in phpMyAdmin and store it offline (database contains real names, contacts, accounts and password hashes). Back up the whole application folder separately.
3. Do not publicly upload the original SQL dump, screenshots containing customer details, server logs or actual credentials.
4. Rotate any demo/admin credentials and force staff to use strong unique passwords before going live.
5. Review applicable privacy/data protection obligations before storing customer information online.

## Hosting setup

1. Create a PHP/MySQL-capable website/domain in Hostinger.
2. Enable HTTPS and confirm TLS certificate works before allowing login.
3. Create a new MySQL database and an application database user with access only to that database. Hostinger may prepend a username prefix; use the **actual database name** the host assigns.
4. Import the fresh backup/export of your current XAMPP `aquatrack_db` using Hostinger phpMyAdmin. **Do not run** the bundled schema reference against your existing imported database.
5. Upload the **contents** of this project into the website document root (`public_html` on typical shared hosting), preserving folders and hidden `.htaccess` files. Be careful: many zip clients omit hidden files.
6. Edit `.env` (never share it publicly). Example:

   ```ini
   APP_ENV=production
   APP_URL=https://your-real-domain.example
   APP_DEBUG=false
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=your_actual_hostinger_database
   DB_USER=your_actual_hostinger_database_user
   DB_PASSWORD=your_private_database_password
   MAIL_FROM=no-reply@your-real-domain.example
   ```

   `DB_HOST` and `DB_PORT` are examples; follow the values provided by Hostinger. If `.env` values contain special characters, wrap them in quotes.
7. Test **denial** of `https://your-domain/.env`, `/config/database.php`, `/database/aquatrack_schema_reference.sql`, `/includes/admin-layout.php`, `/tools/create_admin.php`, and `/tests/forecast_smoke.php`. These should not be publicly accessible (403/404). If you can see/download any of them, **stop deployment** and fix server restrictions.
8. Confirm PHP `pdo_mysql` is installed. Prefer PHP 8.2/8.3. Verify `mbstring` if the hosting setup uses dependent extensions (the final code avoids relying on it for its main paths).
9. If password recovery is required, test the entire delivery of a reset email, and consider an SMTP/API integration if the host's `mail()` does not deliver reliably. The included form should **not** be advertised as dependable before mail testing.
10. Create scheduled backups of both database and files, and test restoring a backup. Retain records per station policy and legal requirements.

## Smoke tests on staging/live

- Landing page and login over HTTPS; verify that HTTP redirects to HTTPS.
- Session expiration; logout must require CSRF-valid POST.
- Login roles: active Admin, active Staff, inactive user, invalid password, and access denial when staff visits `admin/*` directly.
- Create/edit Customer; create/activate Sellable Item; add Supply and Stock In/Out; create multi-line Order.
- Order processing: Pending → Confirmed → Preparing; schedule and assign delivery; Assigned Staff marks Ongoing then Completed; Cashier/Manager payment page enforces role checks; order changes to Completed.
- Record partial/full payments and confirm remaining balance; reject overpayment or payment on cancelled orders.
- Verify Reports counts, daily/weekly/monthly charts and CSV export; check empty data and long date ranges.
- If there are fewer than 6 historical complete months, ML forecasting should say **Insufficient Data** instead of inventing a forecast.
- Verify file uploads are not offered (this package has no public upload handler).
- Verify .env, schema reference, tooling, error logs, and database credentials cannot be retrieved via HTTP.

## IMPORTANT limitations to resolve before production sign-off

- Final staging tests, host-specific deployment and security penetration assessment are **not** performed in this package generation environment.
- Persistent login rate limits, mail deliverability, password recovery reliability, concurrent transaction tests, data retention, access logs and accounting/refund policies must be validated or hardened.
- Inventory material consumption remains manual and should not be claimed as automated stock deduction.
- The older dashboard/UI modules were preserved; verify their page-by-page behavior on the target PHP/MySQL runtime.

## Rollback plan

Keep dated, offsite copies of both application and database. For rollback, put site into maintenance mode, restore the matching database AND application backup, test basic login/order/report functions, and only then reopen access. Do not mix backups from different schema/data snapshots.
