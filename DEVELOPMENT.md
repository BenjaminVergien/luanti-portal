# Local development

The portal uses Laravel 13 and Filament 5 with PHP 8.5 in this workspace. Exact
dependency versions are pinned in `composer.lock` and `package-lock.json`.
**Implemented** behavior and component boundaries are summarized in the
[README](README.md); local paths and deployment recipes below are **Provisional**
environment assumptions. Documentation does not certify a live deployment.

## Existing Windows workspace

PHP and Composer are portable tools in the sibling `.dev-tools` directory; Node
and npm are installed on the host. `dev.ps1` temporarily adjusts the process PATH
and Composer directories without changing the global PATH.

From the project directory:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1
```

This starts the local webserver, queue worker, scheduler and Vite. Stop them with
Ctrl+C. Internal administration is at http://127.0.0.1:8000/admin; the organization
area is `/portal`, registration `/portal/register`, and personal access `/zugang`.
The local global-admin account defaults to `dev@example.test`; its password is
stored only in the ignored `.env` as `DEV_ADMIN_PASSWORD`.

## Fresh clone with an installed toolchain

Requires PHP/Composer and Node/npm. Check PHP extensions against the installed
packages; do not infer runtime requirements from the local PHP version alone.

```powershell
git clone https://github.com/Stewie23/luanti-portal.git
Set-Location luanti-portal
Copy-Item .env.example .env
composer install
composer check-platform-reqs
npm ci --ignore-scripts
php artisan key:generate --no-interaction
New-Item -ItemType File -Path database/database.sqlite
php artisan migrate --no-interaction
```

Set a private `DEV_ADMIN_PASSWORD` of at least 16 characters in `.env`, then run
`php artisan db:seed --no-interaction` and `composer run dev`. The development
seeder runs only in `APP_ENV=local`, grants the global-admin role and preserves an
existing account's password. `.env.example` contains no password.

## Commands and background work

```powershell
# Tests and frontend build
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 test
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 build

# Artisan and Composer through the portable toolchain
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan migrate --no-interaction
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 composer check-platform-reqs

# First production global admin (interactive password prompt)
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan admin:create

# Restore the local development account after a database reset
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan db:seed --no-interaction
```

The following schedules are implemented in `routes/console.php`, with overlap
guards. The local runner starts the scheduler; deployments must invoke
`php artisan schedule:run --no-interaction` every minute.

| Command | Schedule | Responsibility |
| --- | --- | --- |
| `luanti:worlds:sync` | Hourly | Fetch published templates from configured bridge APIs; optional `--server=ID` for manual targeting |
| `luanti:accounts:recover` | Every minute | Redispatch account reconciliation after worker interruption |
| `luanti:games:reconcile` | Every minute | Prepare/configure private copies and reconcile approved running periods |

Account delivery runs on the queue and polls pending work. The bridge API also
reconciles configured instance schedules approximately every ten seconds while
running. Persisted jobs retain their exact retry bytes and IDs; unknown outcomes
need inspection rather than deletion/recreation of state.

## Template migration: development reset only

**Provisional / moving part:** the template/private-instance migration refuses to
run when legacy worlds or world requests exist. Existing saves are not implicitly
safe templates, and there is no automatic production data conversion.

For disposable local development data, first stop local workers/scheduler and
back up the SQLite database. With `APP_ENV=local`, explicitly discard the old
portal world data before migrating:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan luanti:worlds:reset-development --force --no-interaction
powershell -NoProfile -ExecutionPolicy Bypass -File .\dev.ps1 artisan migrate --no-interaction
```

The reset removes world/template records, requests, private-instance records,
game accounts, assignments, account/lifecycle jobs and queued/failed
`ProcessLuantiAccounts` entries. It preserves users, organizations and server
configuration. It does not stop remote games, delete server saves, or publish
templates. The command refuses non-local environments or a missing `--force`.
Restart development processes after the reset/migration is complete.

The bridge needs its reviewed controller and HTTPS API snapshots upgraded, then
clean templates published and synchronized into the portal. See the bridge's
[publication and upgrade procedure](../civicrm_bridge/docs/HTTPS_API.md).
Sibling links assume both projects are checked out beside each other.

## Local defaults and deployment assumptions

| Setting | Local default |
| --- | --- |
| Database | SQLite at `database/database.sqlite` |
| Queue, sessions, cache | Database |
| UI / timezone | German / `Europe/Berlin` |
| Mail | Log mailer; no external delivery |
| Webserver and Vite | Bound to `127.0.0.1` |

Invitation links in logs are private access links and expire after seven days.
Email verification uses the queue. Authorization applies locally too; there is
no unrestricted local-admin bypass.

For deployment, configure HTTPS, the canonical `APP_URL`, a real mail transport,
a durable queue worker, the minute scheduler, persistent database/queue state and
a cache that supports shared locks. Keep `APP_KEY` stable and back it up privately
with the database; replacing it breaks access to encrypted credentials and job
payloads. Back up bridge saves, registry and receipts separately.

Optional Hetzner provisioning needs a token in portal settings (or the
`HETZNER_API_TOKEN` fallback), access to the provider API/GHCR and, for verification,
OpenSSH on the portal host (`SSH_BINARY` overrides detection), writable private
temporary storage and SSH access to the VM's public IPv4 on port 22. Automatic
engine installation currently requires Ubuntu 24.04 and a selected official
Luanti image. Image verification is separate from deploying the Linux/systemd
bridge, game content and public endpoints.

Keep `.env`, credentials, SQLite files, logs, runtime data, `vendor`,
`node_modules`, published Filament assets and frontend builds out of Git. Commit
lockfiles and the empty `.env.example`. The bridge, parent VS Code workspace and
`.dev-tools` are separate from this repository.