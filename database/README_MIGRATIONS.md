# Database Migration Notes — Kenji's Kitchen

**Important:** The actual hosted database has not been inspected by these local regression reports. Treat migration status as unknown until the hosting administrator checks it directly.

For a **fresh, isolated installation**, use the root `schema.sql` baseline. The ignored `database/kenjis_kitchen.sql` and `database/kenjis_kitchen_full_seed.sql` are legacy exports, not current installation scripts. Do not import `schema.sql` or a local dump over an existing database. `schema.sql` creates and seeds the `kenjis_kitchen` database; its demo accounts and catalog are intended for development/test environments and must be reviewed before any real deployment.

## Migration 001 — Online auth / OTP hardening

File expected in the project:

`database/migrations/001_online_auth_otp_hardening.sql`

The inspected baseline contained the hardened `otp_verifications` structure, including:

- `code` widened to `VARCHAR(255)` for password-hashed OTP values
- `purpose` distinguishing registration and sign-in use
- Pending registration fields including full name, phone, address, and password hash

Before deploying this migration, inspect the exact SQL and compare the hosted `otp_verifications` structure with its required columns and indexes. Do not rerun a DDL script blindly merely because the filename exists.

## Migration 002 — GCash payment integrity and audit

File:

`database/migrations/002_gcash_payment_integrity_and_audit.sql`

Reported purpose and contents:

- Creates `payment_reference_registry` with a unique constraint preventing reuse of the same reference/type pair.
- Creates `payment_verification_audits` for manual payment verification history.
- Backfills historical payment references, including treatment for duplicate legacy references.

The inspected script uses `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE` for its structures/backfill. Nonetheless, inspect the exact script and database before running it on hosting.

## Migration 003 — Customer email verification

File:

`database/migrations/003_customer_email_verification.sql`

Reported behavior:

- Adds `users.email_verified_at DATETIME NULL` after `customer_status` if the column is absent.
- Backfills active, non-deleted `Customer (Online)` accounts with their `created_at` timestamp when `email_verified_at` is still NULL.
- Excludes staff and inactive/deleted accounts according to the migration's predicates.

The inspected SQL uses MariaDB-compatible `ADD COLUMN IF NOT EXISTS` syntax and a conditional backfill. The actual hosted MariaDB/MySQL version must be checked before use.

## Migration 004 — Application schema alignment

File: `database/migrations/004_application_schema_alignment.sql`

Adds `orders.inventory_deducted` and expands `approval_requests` to support menu/category request types, nullable item/category references, and JSON payload text. Review the live table and existing foreign keys first. The script assumes the `orders`, `approval_requests`, `menu_items`, and `categories` tables already exist. It does not replace migrations 001-003 or the other historical migrations needed by a legacy installation.

## Migration 005 — Retire unsupported staff accounts

File: `database/migrations/005_retire_manager_server_accounts.sql`

The active staff roles are Admin, Cashier, Kitchen Staff, and Inventory Staff. The separate `Customer (Online)` role remains necessary for the storefront. Manager and Server definitions and demo accounts remain in the fresh schema as inactive history, and the staff login accepts only the four active roles. On an existing installation, this migration marks historical Manager and Server accounts inactive while retaining user and role rows for foreign-key and audit history. The operational XAMPP database was inspected on November 4, 2026 and those roles were already inactive; this migration was unnecessary there.

## Migration 006 — Void/refund approval records

File: `database/migrations/006_transaction_requests.sql`

Creates `transaction_requests` for cashier requests and Admin decisions, including manual refund method and reference. Applied to the operational XAMPP database on November 4, 2026. No historical order was voided or refunded during migration.

## Migration 007 — Delivery arrangements and dispatch

File: `database/migrations/007_delivery_dispatch.sql`

Adds `orders.delivery_mode` and `orders.delivery_tracking_url`, and creates `delivery_dispatches`. Existing orders default to `LAGRO_FREE`; this does not retrospectively prove their delivery addresses are within Lagro. Applied to the operational XAMPP database on November 4, 2026; the order count remained 1,279 and the new dispatch table was empty immediately after migration.

File: `database/migrations/008_failed_delivery_cancellation.sql`

Adds `Cancel Delivery` requests and a `stock_returned` decision flag. Cashiers can request cancellation only for a failed, unpaid online cash-on-delivery order. Admin approval closes the order; stock returns only when Admin confirms the items are available for sale. Applied to the operational XAMPP database on November 4, 2026. Existing requests and orders were unchanged by the migration.

File: `database/migrations/009_order_cancellation_schema_alignment.sql`

The older operational order tables lacked `cancelled_at`, `cancel_reason`, `order_items.voided_quantity`, and the `Voided`/`Refunded` status values required by the approval code. This migration adds those missing fields and expands the order status enum without changing existing order statuses or quantities. Applied to the operational XAMPP database on November 4, 2026 after the first live cancellation approval rolled back with an unknown-column error. Request #2 remained pending and its order stock remained 39 after the repair.

## Current schema and checkout retries (November 14, 2026)

The operational XAMPP MariaDB 10.4.32 database (`kenjis_kitchen`) was inspected and aligned directly with the 36-table baseline in `schema.sql` without resetting accounts, stock, orders, or payment history:

- Added `checkout_requests` (`PRIMARY KEY (channel, actor_id, request_key)`) and `otp_verifications.is_verified`.
- Expanded `otp_verifications.purpose` (`password_reset`), `payments.payment_status` (`Refunded`, `Voided`), `orders.order_channel` (`POS`, `ONLINE`), `approval_requests.request_type`, and `users.customer_status` (nullable).
- Widened `users.email` (`VARCHAR(150)`), `payments.payment_method` (`VARCHAR(50)`), `payments.payment_reference` (`VARCHAR(100)`), and made `inventory_logs.user_id` nullable for automated/system stock logs.

Run `php tools/check_schema.php --database=EXACT_DATABASE_NAME` for a read-only schema compatibility check.

## Required safe procedure for hosting

1. Back up the **hosted** database and verify the backup file exists and is usable.
2. Inspect live tables, columns, constraints, database engine/version, and migration history where available.
3. Compare the hosted schema with what each migration adds. Local database state is not proof of hosted state.
4. Review the exact migration SQL with the administrator and apply only missing changes, in dependency order, to the correct hosted database.
5. Run read-only post-migration checks before deploying customer-facing code that requires the new columns/tables.
6. Keep the backup outside the public web root. Record execution time and result without logging credentials.
7. If a migration fails, stop and investigate; do not import the local test database as a workaround.

## Basic post-migration checks (read-only)

Check for the three required structures, adapting schema/table names to the host's actual database:

```sql
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'email_verified_at';

SHOW TABLES LIKE 'payment_reference_registry';
SHOW TABLES LIKE 'payment_verification_audits';

DESCRIBE otp_verifications;
```

Verify that the expected role name for online customers exists and that the migration backfill affected only the intended accounts. Do not print customer email addresses or other private data in deployment logs.

## Do not do this

- Do not run these migrations against the wrong selected database.
- Do not execute a full local SQL dump against the hosted production database.
- Do not assume a migration is safe to repeat without inspecting its exact contents.
- Do not apply migrations to the local operational database merely to mimic hosting.
- Do not expose `.env`, credentials, or backup dumps through HTTP.

## Customer storefront reviews
Apply `database/migrations/009_customer_reviews.sql` when deploying the storefront update. It creates only the customer review table and can be run again safely. Reviews are submitted by active, verified online customers (one editable review per account), with CSRF protection. The public feed displays the latest 12 reviews and the overall rating; it refreshes every 30 seconds. These are storefront submissions, not imported Facebook reviews.
