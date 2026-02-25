# Tagom CRM

Tagom CRM is a lightweight internal CRM/ERP-style web app built with plain PHP + MySQL (no full framework).  
It covers customer/supplier management, products, sales and purchase invoices, payments, stock movement, and ledger tracking.

## Stack

- PHP 8.1+ (uses `readonly` properties and modern syntax)
- MySQL/MariaDB
- Apache (XAMPP-friendly)
- Tailwind via CDN for UI

## Main Features

- Login + session auth (`users` + `roles`)
- Role-based authorization checks
- Dashboard KPIs (receivables, payables, daily metrics, overdue, low stock)
- Customer and supplier profiles with running ledger balance
- Product management with supplier link
- Sales invoices with stock decrease + ledger write + optional immediate payment
- Purchase invoices with stock increase + ledger write + optional immediate payment
- Standalone payments for customer collection (`payment_in`) and supplier disbursement (`payment_out`)
- Optional service-layer execution via feature flags
- CSRF protection on POST forms
- Optional audit logs table support

## Project Structure

```text
tagom/
  app/
    core/                 # DB, Auth, CSRF, Logger
    Domain/               # Services, DTOs, Validators
    Infrastructure/       # Transaction + repositories
    Middlewares/          # Auth middleware
    views/                # Shared layout + login view
  config/
    config.php            # Env-driven app configuration
  public/                 # HTTP entry points
    *.php                 # Pages/controllers
    assets/               # CSS and images
  composer.json
```

## HTTP Entry Points

- `public/login.php` - login form
- `public/logout.php` - logout
- `public/index.php` - dashboard
- `public/customers.php` / `public/customer.php`
- `public/suppliers.php` / `public/supplier.php`
- `public/products.php`
- `public/sale_create.php` / `public/sale_view.php`
- `public/purchase_create.php` / `public/purchase_view.php`
- `public/payment_in_create.php`
- `public/payment_out_create.php`
- `public/users.php` (owner-only)
- `public/make_hash.php` (CLI-only utility in local env)

## Configuration

All config is loaded from `config/config.php` using environment variables with defaults.

### Database

- `DB_HOST` (default: `127.0.0.1`)
- `DB_NAME` (default: `tagom_crm`)
- `DB_USER` (default: `root`)
- `DB_PASS` (default: empty)
- `DB_CHARSET` (default: `utf8mb4`)

### App

- `APP_BASE_URL` (default: `/tagom/public`)
- `APP_ENV` (default: `local`)
- `APP_SESSION_NAME` (default: `TAGOMSESSID`)
- `APP_SECURE` (default: auto by HTTPS)
- `APP_SAMESITE` (default: `Lax`)
- `APP_ALLOW_NEGATIVE_STOCK` (default: `0`)

### Feature Flags

- `FEATURE_USE_SALES_SERVICE` (default: `0`)
- `FEATURE_USE_PURCHASE_SERVICE` (default: `0`)
- `FEATURE_USE_PAYMENT_SERVICE` (default: `0`)

When flags are `1`, create/payment pages use domain services (`app/Domain/*Service.php`) instead of inline transactional logic.

## Authorization Defaults

Defined in `config/config.php`:

- `sales_create`: `owner`, `employee`
- `purchase_create`: `owner`, `employee`
- `payment_in_create`: `owner`, `employee`
- `payment_out_create`: `owner`, `employee`
- `products_manage`: `owner`, `employee`
- `users_manage`: `owner`

## Expected Database Tables

The code expects these tables (at minimum):

- `roles`
- `users`
- `parties`
- `products`
- `stock`
- `sales_invoices`
- `sales_invoice_items`
- `purchase_invoices`
- `purchase_invoice_items`
- `payments`
- `ledger_entries`
- `doc_sequences`
- `audit_logs` (optional, checked at runtime)

Notes:

- Invoice services use `doc_sequences` with row-level locking.
- `audit_logs` is optional; repository silently skips insert if table does not exist.

## Quick Start (XAMPP)

1. Put project in `C:\xampp\htdocs\tagom`.
2. Create database `tagom_crm` (or set `DB_NAME`).
3. Create/import schema for required tables (no migration scripts currently in this repo).
4. Ensure Apache + MySQL are running.
5. Open:
   `http://localhost/tagom/public/login.php`

## Password Utility

Generate bcrypt hash for a user password:

```bash
php public/make_hash.php myPassword123
```

The script only works in:

- CLI mode
- `APP_ENV=local`

## Logging

- Logger path: `storage/logs/app-YYYY-MM-DD.log`
- `Logger` auto-creates `storage/logs` if missing.
- Failures in logging do not break request flow.

## Current Repository State

Current top-level folders include: `app`, `config`, `public`.

Not present right now:

- `tests/`
- `scripts/`
- `storage/` (re-created automatically on first log write)
- `phpunit.xml`

`composer.json` still contains dev test dependency (`phpunit/phpunit`) and test script, but test files/config are currently absent.

## Common Issues

- Redirect/login loop: check `APP_BASE_URL` matches actual URL path.
- CSRF 419: session expired or missing token; refresh page and retry.
- Stock errors on sale: if negative stock is blocked, set `APP_ALLOW_NEGATIVE_STOCK=1` only if business rules allow it.
- Access denied (403): user role does not match required role list.

## Development Notes

- Runtime autoload is handled in `public/bootstrap.php` with a PSR-4-like loader for `App\`.
- Composer autoload settings exist but are not required for normal web runtime in current setup.
- Keep files UTF-8 encoded to avoid mojibake in Arabic labels/messages.
