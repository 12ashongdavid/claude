# Database Migrations

Upgrade scripts for databases that already exist. Apply them **in order** (001 → 016),
once each, from the `pk_ams` database.

**New installs do not need these** — `setup.sql` at the project root already contains
every table, column, and enum from all 16 migrations, plus seed data.

## Applying via phpMyAdmin

1. Open `http://localhost/phpmyadmin` → select the `pk_ams` database.
2. Click the **SQL** tab.
3. Open a migration file, paste its contents, click **Go**.
4. Repeat for each file in order.

## Applying via command line

```bat
C:\xampp\mysql\bin\mysql -u root pk_ams < "C:\xampp\htdocs\Apt PK\database\migrations\011_room_types_charge_period.sql"
```

## Order

| # | File | Purpose |
|---|------|---------|
| 001 | `001_room_images.sql` | `rooms.image` cover-photo column |
| 002 | `002_must_change_password.sql` | `users.must_change_password` flag |
| 003 | `003_room_types.sql` | `room_types` table + widen `rooms.room_type` to VARCHAR |
| 004 | `004_agreements.sql` | `tenant_agreements` table |
| 005 | `005_announcements_targeting.sql` | `announcements.target_tenant_id` (targeted announcements) |
| 006 | `006_room_images_table.sql` | `room_images` gallery table (seeded from `rooms.image`) |
| 007 | `007_paystack_methods.sql` | add `paystack` to `payment_method` enums |
| 008 | `008_paystack_transactions.sql` | `paystack_transactions` ledger (idempotency) |
| 009 | `009_paystack_channels.sql` | add `card`/`ussd`/`qr` to `payment_method` enums |
| 010 | `010_rent_reminder_log.sql` | `rent_reminder_log` cron tracking |
| 011 | `011_room_types_charge_period.sql` | **Fix:** add `room_types.charge_period` (required by the app) |
| 012 | `012_notification_types.sql` | **Fix:** widen `notifications.type` (adds `utility`, `announcement`) |
| 013 | `013_last_login.sql` | `users.last_login` for tracking inactive accounts |
| 014 | `014_booking_payments.sql` | Add payment columns to `booking_requests` (down/full payment) |
| 015 | `015_feedback_reports.sql` | `feedback_reports` table (tenant + public complaints/feedback) |
| 016 | `016_booking_verification_code.sql` | `booking_requests.verification_code` (admin confirms payment) |

> **Note:** migrations 011 and 012 are bug fixes. Apply them to any existing database
> or the app will throw SQL errors (`Unknown column 'charge_period'` on the landing page
> and payment/announcement inserts failing with `Data truncated for column 'type'`).
