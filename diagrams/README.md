# PK's Luxury Apartments — Diagrams

System design, use case, and entity-relationship diagrams for the Apartment
Management System (AMS).

## Files

| File | Description | Source |
|------|-------------|--------|
| `system_design.jpg` | Architecture / system design diagram | `system_design.mmd` |
| `use_case.jpg`      | Use case diagram (actors + functionalities) | `use_case.mmd` |
| `erd.jpg`           | Entity-Relationship Diagram (all 14 tables) | `erd.mmd` |
| `site_map.jpg`      | Site map / page hierarchy with role access | `site_map.mmd` |
| `flowchart.jpg`     | Business process flows (onboarding, payments, maintenance, reminders) | `flowchart.mmd` |
| `*.mmd`             | Mermaid source files (editable) | — |

## Diagrams

### 1. System Design (`system_design.mmd`)

High-level architecture running on XAMPP (Apache + PHP + MySQL):

- **Clients** — Tenant / Staff / Admin browsers
- **Application layer** — PHP pages (landing, dashboard, rooms, tenants,
  payments, utilities, maintenance, reports) and REST API endpoints under `/api/`
- **Paystack webhook** and **cron** rent-reminder job
- **Data layer** — MySQL `pk_ams` database + `uploads/` file store
- **External services** — Paystack (payments), mNotify (SMS reminders), Google
  Maps embed

### 2. Use Case (`use_case.mmd`)

Actors and their capabilities:

- **Visitor** — browse residences, submit booking request
- **Tenant** — login, dashboard, pay rent & utilities, maintenance requests,
  notifications, receipts, profile
- **Staff** — manage residences, tenants, bookings, payments, utilities,
  maintenance, announcements
- **Admin** — everything Staff can do, plus staff management and reports/analytics
- **System** — rent reminders (SMS + in-app) and Paystack payment processing

### 3. ERD (`erd.mmd`)

All tables from `setup.sql` and the migration scripts:

`users`, `rooms`, `room_types`, `tenancies`, `rent_payments`,
`utility_bills`, `maintenance_requests`, `notifications`, `room_images`,
`booking_requests`, `tenant_agreements`, `announcements`,
`paystack_transactions`, `rent_reminder_log`.

Key relationships:

- `users` (tenant) 1—N `tenancies` N—1 `rooms`
- `users` 1—N `rent_payments` / `utility_bills` / `maintenance_requests`
  / `notifications` / `tenant_agreements` / `announcements`
- `rooms` 1—N `room_images` / `booking_requests`
- `room_types` 1—N `rooms` (classifies room types)

### 4. Site Map (`site_map.mmd`)

Navigation hierarchy with role-based access:

- **Public** — Landing page (`index.php`), Login, Register (disabled)
- **All logged-in users** — Dashboard, Payments, Utilities, Maintenance,
  Notifications, Profile, Receipts
- **Admin & Staff** — Residences, Tenants, Bookings, Maintenance, Payments,
  Reports, Announcements
- **Admin only** — Staff Management (`staff.php`)

### 5. Business Process Flowchart (`flowchart.mmd`)

Five core workflows through the system:

1. **Tenant Onboarding** — booking request → staff approval → tenant registration → room assignment → welcome SMS → first login
2. **Rent Payment** — online path (Paystack checkout → webhook → verify → record) and manual path (staff records → receipt)
3. **Utility Bill Payment** — staff records bill → tenant pays online (Paystack) or staff records manual payment → receipt
4. **Maintenance Request** — tenant submits → staff assigns technician → resolve or reassign → tenant closes
5. **Automated Rent Reminders** — weekly cron scans active tenants → SMS via mNotify + in-app notification

## Editing

Edit the `.mmd` files and re-render:

```powershell
$mmd = Get-Content .\erd.mmd -Raw
$enc = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($mmd))
$enc = $enc.TrimEnd('=').Replace('+','-').Replace('/','_')
curl.exe -o erd.jpg "https://mermaid.ink/img/$enc"
```

Or paste the `.mmd` content into https://mermaid.live and export directly.
