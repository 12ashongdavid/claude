# Tenant Mobile App (PWA)

A tenant-facing Progressive Web App: pay rent and utility bills, submit
maintenance requests, and check notifications from a phone. It's
installable to the home screen and works like a native app, without an
App Store or Play Store submission.

It's two pieces, both in this repo:

- **`mobile-api/`** — a small PHP backend, separate from the website's
  `api/*.php`, that authenticates with a bearer token instead of a
  session cookie (a PWA may be opened from a different origin than the
  backend, where cookies don't reliably follow). It reuses the exact
  same database, helper functions (`computeTenantArrears()`,
  `paystackApiCall()`, `validateEmailDetailed()`, etc.), and business
  rules as the website — a tenant's balance, payment history, and
  ownership checks are all computed the same way in both places.
- **`mobile-app/`** — the installable PWA itself: plain HTML/CSS/JS, no
  build step, no framework. Open `mobile-app/index.html` in a browser
  and it runs.

Only tenants can sign in here — admins and staff keep using the website.

## Try it locally

You need PHP with `pdo_mysql` and a MySQL/MariaDB server the app can
reach with the credentials in `config/database.php` (defaults to
`root`/no password on `localhost`, matching a fresh XAMPP install).

```bash
php -S 127.0.0.1:8899
```

Then open `http://127.0.0.1:8899/mobile-app/index.html` and sign in with
a seeded tenant account (`tenant1` / `Admin@123` from `setup.sql`, or any
tenant an admin has created). The app auto-creates the database and
tables on first request, same as the website.

## Deploying it

1. **Apply the new migration** if you're upgrading an existing database:
   `database/migrations/017_api_tokens.sql` (or `setup.sql` for a fresh
   install — it already includes the `api_tokens` table).
2. **Upload both folders** — `mobile-api/` and `mobile-app/` — as
   siblings of the website's files (e.g.
   `public_html/mobile-api/` and `public_html/mobile-app/`, next to
   `public_html/index.php`). `mobile-app/js/config.js` assumes this
   layout by default (`API_BASE_URL = '../mobile-api'`); if you deploy
   the PWA on a different domain than the backend, change that constant
   to the backend's full URL instead.
3. **Set the mobile app's public URL** in `config/mobile_auth.php` —
   `MOBILE_APP_URL` defaults to `SITE_URL . '/mobile-app'`, which is
   correct for the layout above. Paystack redirects the tenant's browser
   here after checkout, so it has to be right or that redirect will 404.
4. **Confirm the Authorization header reaches PHP.** Many shared hosts
   strip it by default; `mobile-api/.htaccess` works around this on
   Apache/cPanel (the common case here) via a rewrite rule. If your host
   uses something else (nginx, a different PHP handler), you'll need the
   equivalent — the failure mode is every request past login coming back
   "Missing authorization token."
5. **Icons and branding** live in `mobile-app/icons/` — replace them with
   real artwork whenever you'd like (they're currently a simple
   navy/gold placeholder in the site's brand colors).

## How the pieces fit together

- `config/mobile_auth.php` — issues and looks up bearer tokens
  (`api_tokens` table, hashed like a password so a database leak
  doesn't hand out usable tokens), plus the `MOBILE_APP_URL` /
  `MOBILE_PAYSTACK_CALLBACK_URL` constants.
- `mobile-api/_bootstrap.php` — every endpoint's shared setup: CORS
  (safe here since a bearer token is never attached to a request
  automatically the way a cookie is), JSON headers, and
  `requireMobileAuth()`, which every endpoint except `auth.php` calls
  first.
- `mobile-api/auth.php` — login (returns a token) and logout (revokes
  it).
- `mobile-api/dashboard.php`, `payments.php`, `utilities.php`,
  `maintenance.php`, `notifications.php`, `profile.php` — one file per
  screen in the app, each scoped to the logged-in tenant's own data only.
- `mobile-api/paystack_verify.php` — where Paystack redirects the
  tenant's browser after checkout. There's no bearer token available at
  that point (Paystack doesn't forward one), so it records the payment
  the same way the website's webhook does, then bounces the browser back
  into the PWA with the outcome in the URL (`?paid=1` / `?paid=0`) since
  there's no session to stash a flash message in.
- `mobile-app/js/api.js` — the only place that touches `fetch()` or the
  stored token; every screen goes through `Api.get()` / `Api.post()`.
- `mobile-app/js/app.js` — a small hand-rolled router (`goTab()`) and one
  render function per screen. No build tooling on purpose, so there's
  nothing to install to work on this — edit the file, reload the page.
- `mobile-app/service-worker.js` — caches the app shell for offline
  opening; API calls always hit the network, never the cache.

## What's intentionally not here

This is the tenant experience only, matching how the feature was scoped.
Admin/staff tools (managing tenants, rooms, reports, etc.) stay on the
website. If that ever needs to change, the same bearer-token pattern
extends cleanly — `requireMobileAuth()` would just need a role check
added, the way the website's own pages use `requireRole()`.
