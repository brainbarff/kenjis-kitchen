# Database Migration Notes — Kenji's Kitchen

**Important:** The actual hosted database has not been inspected by these local regression reports. Treat migration status as unknown until the hosting administrator checks it directly.

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
