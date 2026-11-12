# AquaTrack — Integrated Hostinger Deployment Candidate

**Gaeven's Water Refilling Station**  
Native PHP 8.0+ / MySQL (MariaDB) / HTML / CSS / JavaScript. **No Python and no external machine-learning API.**

> **Release stage:** integrated code candidate. PHP/JS syntax checks pass and the offline forecast math smoke test passes. Full MySQL, browser, concurrency, security and live Hostinger testing have **not** been completed. Do not use with live customer/financial data until tested.

## What is included

| Area | Location | Main capability |
| --- | --- | --- |
| Landing | `index.php` | Station information and authorized login |
| Authentication | `auth/` | Login, logout, password reset request / confirmation (requires working mail configuration) |
| Admin Dashboard | `admin/dashboard.php` | DB-backed overview |
| Staff Accounts | `admin/users/index.php` | Admin creates and manages staff accounts |
| Customers | `admin/customers/index.php` | Customer CRUD and status |
| Orders | `admin/orders/index.php` | Itemized orders, totals, order status |
| Unified Inventory | `admin/inventory/index.php` | Sellable products/prices + supply stocks and movement history |
| Deliveries | `admin/deliveries/index.php` | Schedule, assign, track deliveries, synchronize completed orders |
| Sales and Payments | `admin/payments/index.php` | Record payments with no overpayment, outstanding balances, payment audit |
| Reports and Forecasting | `admin/reports/index.php` | Daily/weekly/monthly sales, order, stock, delivery reports, CSV, print, forecast |
| Staff Dashboard | `staff/dashboard.php` | Restricted staff overview |
| Staff modules | `staff/{orders,tasks,inventory,payments,profile}.php` | Own orders, assigned deliveries, read-only inventory, cashier payments (Cashier/Manager only), change password |
| Database | `database/aquatrack_schema_reference.sql` | ORIGINAL `aquatrack_db` schema only (sanitized, no user/customer records) |
| Deployment settings | `.env`, `.env.example`, `.htaccess`, `config/` | Environment configuration and access restrictions |

## SAME DATABASE (important)

The existing database name remains `aquatrack_db` in local `.env`. Original tables and foreign keys are retained: `users`, `customers`, `orders`, `order_items`, `products`, `inventory`, `inventory_transactions`, `deliveries`, `payments`, `sales`, and `activity_logs`.

**Do not import the schema reference into an existing `aquatrack_db`.** That file is for reference or an empty database only. To preserve the current XAMPP data, **back up/export the current database in phpMyAdmin**, import it into Hostinger's new database, and set the new database credentials in `.env`. Never upload the raw SQL export to the public website folders.

### Unified Inventory model

There is one Inventory page. "Sellable Items" persists to the original `products` table because `order_items.product_id` refers to `products`; "Supplies & Stock" persists to the original `inventory` and `inventory_transactions` tables. **Stock is not automatically deducted from supplies when orders are placed/completed.** To automatically consume materials, a verified bill-of-materials mapping and a consumption policy are still needed.

### Payments and completed revenue

- An order has fixed historical order items and prices.
- Payment creation locks the order, confirms the remaining balance and rejects overpayment.
- Payment recording creates/updates the matching `sales` row; order or delivery completion also synchronizes the `sales` row.
- **Completed-order revenue** is reported separately from **cash payments collected**. Cancelled orders with recorded payments are blocked from simple cancellation because refunds are not yet supported.
- Refunds, voided payments and advanced accounting reconciliation are not implemented.

### Next month sales prediction

`config/forecast.php` implements native PHP least-squares linear regression on completed months of `sales` records linked to completed orders. At least **six complete calendar months** are required. The partial current month is excluded; for the **next calendar month**, the model predicts two positions after the last complete training month. It performs time-ordered walk-forward backtesting and compares MAE to a 3-month baseline. Six months is a **minimum**, not a quality guarantee; 12–24+ months is much preferable. Months without recorded completed sales after the first sale are treated as zeros and may represent missing information. Forecasts are estimates, not promises.

## Local quick start

1. Back up your current working `aquatrack` folder and export `aquatrack_db` in phpMyAdmin.
2. Extract this package to a separate test folder, e.g. `/Applications/XAMPP/xamppfiles/htdocs/aquatrack-test`.
3. Edit `.env`: `APP_URL=http://localhost/aquatrack-test`, and specify your local MySQL credentials.
4. Make sure your **existing** `aquatrack_db` and users are available. To avoid modifying existing production-like test data, first clone the database into a separate test database and set `DB_NAME` accordingly.
5. Use `http://localhost/aquatrack-test/auth/login.php` and log in with a **known existing admin account**. No default password is shipped.
6. Test **Customers → Inventory sellable item → Order → Delivery → Payment → Completed sale → Reports** before deploying.

## First admin for a truly empty installation

`tools/create_admin.php` is **CLI-only** and refuses to run if any Admin already exists. It uses environment variables `AQUATRACK_ADMIN_NAME`, `AQUATRACK_ADMIN_USERNAME`, `AQUATRACK_ADMIN_EMAIL`, `AQUATRACK_ADMIN_PASSWORD` (12–72 bytes). It does not ship default passwords. Do **not** run it when migrating existing users. Only use on a private machine/SSH session and clear the variables afterward.

## Deployment

See [`HOSTINGER_DEPLOYMENT.md`](HOSTINGER_DEPLOYMENT.md) and [`TESTING_CHECKLIST.md`](TESTING_CHECKLIST.md).

## Minimum hosting requirements

PHP 8.0+ with `pdo_mysql`, HTTPS, MySQL/MariaDB, `.htaccess` support, and a working outgoing transactional email mechanism for password reset (the provided code calls PHP `mail()`, which must be verified and may need an SMTP/provider integration). PHP 8.2 or 8.3 is recommended after confirming compatibility.

## Important security limitation

Existing older modules were integrated from previously written pages. They require a security review and staging testing. The login includes basic **session-scoped** throttling, which is not equivalent to persistent per-IP/per-account rate limiting. Do not treat this package as certified production-ready yet.
