# CI/CD

Every push and pull request runs through `.github/workflows/ci.yml`
(GitHub Actions → the "Actions" tab of this repo):

| Job     | What it checks |
|---------|-----------------|
| `lint`  | Every `.php` and `.js` file parses (`php -l`, `node --check`) |
| `test`  | The PHPUnit suite in `tests/`, against a disposable database |
| `smoke` | The real app boots and serves `index.php`, `login.php`, `booking.php` on a live PHP server |
| `deploy`| Pushes the repo to production over FTP — **only on `main`, only if configured (see below), and only after the three jobs above pass** |

Nothing deploys anywhere by default. The `deploy` job checks for three
GitHub Secrets and skips itself entirely if they're missing.

## Enabling automatic deployment

1. Go to this repo on GitHub → **Settings → Secrets and variables → Actions → New repository secret**.
2. Add:
   - `FTP_SERVER` — your host's FTP hostname (e.g. `ftp.yourdomain.com`)
   - `FTP_USERNAME` — your FTP/cPanel username
   - `FTP_PASSWORD` — your FTP/cPanel password
   - `FTP_SERVER_DIR` (optional) — the remote directory to upload into (e.g. `/public_html/`). Defaults to `/` if not set.
3. That's it — the next push to `main` that passes lint, test, and smoke will upload the repo to your host automatically, using [SamKirkland/FTP-Deploy-Action](https://github.com/SamKirkland/FTP-Deploy-Action).

**Before enabling this**, be aware:
- It **overwrites files on your live site** on every push to `main`. Only enable it once you're comfortable with that.
- It does not run `database/migrations/`. Apply new migrations yourself via phpMyAdmin (see `database/migrations/README.md`) — this pipeline only ever touches files, never your live database.
- `Apt PK/`, `tests/`, `vendor/`, and the dev tooling files (`composer.json`, `composer.lock`, `phpunit.xml`) are excluded from the upload — only the live app files are deployed.
- Rotate `FTP_PASSWORD` immediately if it's ever exposed (e.g. pasted in chat, committed by mistake) — GitHub Secrets are encrypted at rest and masked in logs, but that only protects it going forward.

To disable automatic deployment again, delete the `FTP_SERVER` secret (or any one of the three) — the `deploy` job will go back to skipping itself.

## Running the tests locally

```bash
composer install
DB_NAME=pk_ams_test vendor/bin/phpunit
```

`tests/bootstrap.php` points the app at a disposable `pk_ams_test` database
(overridable via `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` env vars) and
resets it before every run — it will refuse to run against a database
literally named `pk_ams` as a safety guard against wiping real data.
