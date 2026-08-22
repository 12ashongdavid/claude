# PK's Luxury Apartments — Apartment Management System

## Requirements

- **XAMPP** (PHP 8.0+, MySQL/MariaDB)
- **Apache** with `mod_rewrite` enabled
- **PHP Extensions:** `pdo_mysql`, `fileinfo`, `gd` or `imagick` (for image uploads)

---

## Installation on a New Machine

### 1. Install & Start XAMPP

- Download XAMPP from https://www.apachefriends.org
- Install to `C:\xampp` (default)
- Open **XAMPP Control Panel** and start:
  - **Apache** (Click "Start")
  - **MySQL** (Click "Start")

### 2. Copy Project Files

Copy the entire project folder (`Apt PK`) into:

```
C:\xampp\htdocs\Apt PK
```

The final path should be: `C:\xampp\htdocs\Apt PK`

### 3. Create the Database

Open your browser and go to:

```
http://localhost/phpmyadmin
```

- Click **New** on the left sidebar
- Database name: `pk_ams`
- Collation: `utf8mb4_general_ci`
- Click **Create**

Then click the **SQL** tab and paste the entire contents of `setup.sql`, then click **Go**.

Alternatively, import via the XAMPP shell:

```
C:\xampp\mysql\bin\mysql -u root < "C:\xampp\htdocs\Apt PK\setup.sql"
```

> `setup.sql` is self-contained (all tables, columns, and enums, plus seed data).
> For **existing** databases, apply the ordered scripts in `database/migrations/`
> instead — see `database/migrations/README.md`.

### 4. Configure Database Connection

The file `config/database.php` already has the default settings:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pk_ams');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Change these if your MySQL uses a different user/password.

### 5. Set Up Upload Directories

Ensure these folders exist and are writable:

```
uploads/rooms/
uploads/profiles/
```

The `uploads/` folder must have write permissions. On Windows/XAMPP this is usually automatic, but if uploads fail, right-click the `uploads/` folder → Properties → Security → give **Users** Modify permissions.

### 6. Enable Apache mod_rewrite

In XAMPP Control Panel, click **Apache → Config → httpd.conf** and make sure this line is **uncommented** (no `#` at the start):

```
LoadModule rewrite_module modules/mod_rewrite.so
```

Also change this line (around line 260):

```
AllowOverride None
```

to:

```
AllowOverride All
```

Then restart Apache.

### 7. Access the Application

Open your browser and go to:

```
http://localhost/Apt PK
```

You should see the landing page.

---

## Default Login Credentials

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `Admin@123` |
| Staff | `staff1` | `Admin@123` |
| Tenant | `tenant1` | `Admin@123` |
| Tenant | `tenant2` | `Admin@123` |

**Change passwords on first login.**

---

## File Structure

```
Apt PK/
├── config/database.php       # DB connection & helpers
├── includes/                 # Header, footer
├── api/                      # RESTful API endpoints
├── css/style.css             # All styles
├── js/app.js                 # JS: sidebar, modals, toasts, CSRF
├── uploads/                  # User uploads (rooms, profiles)
├── index.php                 # Landing page
├── login.php                 # Login page
├── register.php              # Tenant self-registration
├── dashboard.php             # Role-based dashboard
├── rooms.php                 # Room management
├── tenants.php               # Tenant management
├── bookings.php              # Booking requests
├── payments.php              # Rent payments
├── utilities.php             # Utility bills
├── maintenance.php           # Maintenance requests
├── notifications.php         # Notification center
├── profile.php               # User profile
├── reports.php               # Analytics & reports
├── receipt.php               # Rent payment receipt
├── utility_receipt.php       # Utility bill receipt
├── booking.php               # Redirects to index.php
├── setup.sql                 # Database schema + seed data (fresh installs)
├── database/migrations/      # Ordered upgrade scripts for existing databases
└── .htaccess                 # Security headers, rewrite rules
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Database connection failed" | Check MySQL is running in XAMPP Control Panel |
| 404 on pages | Enable `mod_rewrite` in Apache httpd.conf |
| Uploads not saving | Give write permissions to `uploads/` folder |
| Blank page | Enable PHP error display in `php.ini`: `display_errors = On` |
| CSS not loading | Open browser dev tools → Network tab → check CSS URL |
| Old data showing | Drop database `pk_ams`, recreate, re-import `setup.sql` |

---

## PHP CLI Path (for password hashing)

If you need to generate bcrypt hashes manually:

```
C:\xampp\php\php.exe -r "echo password_hash('Admin@123', PASSWORD_DEFAULT);"
```
