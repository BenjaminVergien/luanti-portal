# Deploy the portal alongside Foundry

This is a **manual runbook for a fresh installation**, not an installer or a
record of a tested deployment. It targets a single Ubuntu VM you control, PHP
8.5, Laravel 13 / Filament 5, SQLite, and your local Postfix service sending as
`portal@bensfoundry.de`. The game server and bridge remain separate services.
An external SMTP provider remains an alternative if direct delivery fails.

The chosen portal hostname is `portal.bensfoundry.de`. Replace the repository
URL and installation paths below with your own values. Serve this subdomain at
its root, not a subdirectory of the Foundry site.

## 1. Inventory and prepare the host

Before changing services, record the Ubuntu release, available memory/disk, the
current Foundry service/container and its ports, and the web server/reverse proxy
that owns ports 80/443. Back up its configuration and confirm Foundry works.

```bash
cat /etc/os-release
free -h
df -h
sudo ss -ltnp
systemctl --type=service --state=running
```

Keep the existing proxy and Foundry routing. Add a portal virtual host/site;
do not install a competing listener on 80/443 or replace the default site.
The Nginx example below is for an existing Nginx setup. If Caddy/Traefik/Apache
already terminates HTTPS, use its existing certificate/site workflow instead;
the portal needs PHP-FPM, not a long-running `artisan serve` process.

Required tooling:

- PHP **8.5 CLI and FPM**, using the same extensions/configuration. The current
  lockfile requires at least PHP 8.4.1; the root `^8.3` constraint alone is not a
  reliable deployment requirement.
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, iconv, intl, JSON,
  libxml, mbstring, OpenSSL, PDO/SQLite, session, tokenizer, xmlreader and zip.
  CLI `pcntl` is needed for queue timeouts; enable OPcache for FPM. OPcache is
  built into PHP 8.5, so no separate `php8.5-opcache` package is needed.
- Composer 2, Git, CA certificates and the SQLite CLI. OpenSSH client is required
  if the portal will provision or verify game VMs.
- A Node version supported by the locked Vite version on the **build machine**
  (Node 22.12+ or Node 24); Node is not needed to serve compiled assets.

The current host runs Ubuntu 24.04 with Nginx, Certbot and PHP 8.3-FPM already
installed. Install versioned PHP 8.5 CLI/FPM alongside it. Do not change
Foundry's Node installation or the PHP-FPM targets of existing sites.

Ubuntu 24.04's current configured sources do not provide PHP 8.5. The community
[Ondřej Surý PHP PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php) provides
co-installable versions. Add it with a lower APT priority to prefer the existing
distribution sources for packages they already supply. Allow the PPA's newer
`php-common` explicitly: PHP 8.5 requires at least `2:95~`, while Ubuntu's
`2:93ubuntu2` does not satisfy that dependency. This shared package is distinct
from the versioned PHP 8.3 interpreter and extensions.

```bash
sudo apt update
sudo apt install software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo tee /etc/apt/preferences.d/luanti-php >/dev/null <<'EOF'
Package: php-common
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: 501

Package: *
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: 100
EOF
sudo apt update
apt-cache policy php-common php8.5-cli php8.5-fpm
php -v
update-alternatives --query php
sudo apt-get -s install --no-install-recommends php8.5-cli php8.5-fpm \
  php8.5-curl php8.5-mbstring php8.5-xml php8.5-intl php8.5-zip \
  php8.5-sqlite3 git unzip sqlite3 ca-certificates openssh-client
```

Inspect the simulation for removals or changes to existing PHP 8.3/web-server
packages before installing. Record the current default CLI PHP/alternatives;
installation may select a newer default, so restore the previous default if
needed and use `php8.5` explicitly for portal commands. Do not run a broad
`apt upgrade` as part of this installation. After checking the simulation:

On this host, the simulation removes the unversioned `php-apcu` and
`php-imagick` wrapper packages while retaining `php8.3-apcu` and
`php8.3-imagick`. Mark those installed versioned extensions as manual so they
remain protected from later automatic cleanup. Preserve the existing PHP 8.3
CLI explicitly before installing the second version:

```bash
sudo apt-mark manual php8.3-apcu php8.3-imagick
sudo update-alternatives --set php /usr/bin/php8.3
sudo apt install --no-install-recommends php8.5-cli php8.5-fpm php8.5-curl \
  php8.5-mbstring php8.5-xml php8.5-intl php8.5-zip php8.5-sqlite3 \
  git unzip sqlite3 ca-certificates openssh-client
php8.5 -v
php8.5 -m
php -v
php8.3 -m | grep -Ei 'apcu|imagick'
systemctl is-active php8.3-fpm php8.5-fpm
```

Confirm the install summary still matches the reviewed simulation. Do not run
`apt autoremove` during deployment; review that separate cleanup another time.

Install Composer using its [official instructions](https://getcomposer.org/download/)
and verify that it runs under PHP 8.5. The commands below assume Composer's PHP
executable is `/usr/local/bin/composer`; substitute its actual location if needed.

## 2. Prepare local Postfix and DNS

You have confirmed that outbound port 25 works and are setting up Postfix.
Laravel will submit to **127.0.0.1:25** without authentication or TLS; Postfix
queues the message and delivers it to recipient mail servers. This exception is
only for same-host loopback submission. It does not disable Postfix's outbound
TLS or permit remote unauthenticated submission. No inbound mail-server ports
need to be exposed for the portal to submit messages.

For an existing Postfix installation, inspect `sudo postconf -n` and preserve
other applications' requirements. For a fresh send-only setup, install Postfix
and a DKIM signer (`sudo apt install postfix opendkim opendkim-tools`), then
configure the following in `/etc/postfix/main.cf`, substituting the actual mail
hostname if different:

```ini
myhostname = mail.bensfoundry.de
myorigin = bensfoundry.de
inet_interfaces = loopback-only
inet_protocols = ipv4
mynetworks = 127.0.0.0/8
mydestination = $myhostname, localhost.localdomain, localhost
relayhost =
smtpd_relay_restrictions = permit_mynetworks, reject_unauth_destination
smtp_tls_security_level = may
```

Restart Postfix after changing `inet_interfaces` / `inet_protocols`. Direct
delivery uses IPv4 here; enable IPv6 only after its sending address, PTR and SPF
are configured too. Do not add `bensfoundry.de` to `mydestination` unless this
Postfix instance intentionally hosts incoming mail for that entire domain.

Before real delivery:

1. Set `mail.bensfoundry.de`'s A record to the VM's outgoing public IPv4 and its
   Hetzner reverse-DNS/PTR to that same mail hostname. Confirm forward/reverse
   resolution matches the SMTP hostname. Preserve Foundry's DNS records.
2. Authorize that IP in the existing **single SPF record** for `bensfoundry.de`.
   Merge it with any existing senders; do not publish a second SPF record or
   remove authorizations needed by existing mail.
3. Configure DKIM signing for `portal@bensfoundry.de`. One Ubuntu OpenDKIM recipe
   follows. If a signer is already installed, retain its keys/selector instead
   of generating a replacement.

   ```bash
   sudo install -d -o opendkim -g opendkim -m 700 /etc/opendkim/keys/bensfoundry.de
   sudo -u opendkim opendkim-genkey -b 2048 -d bensfoundry.de -s portal \
     -D /etc/opendkim/keys/bensfoundry.de
   sudo chmod 600 /etc/opendkim/keys/bensfoundry.de/portal.private
   ```

   In `/etc/opendkim.conf`, set these directives once, removing conflicting
   existing `Domain`/`Selector`/`KeyFile` or socket directives:

   ```text
   Syslog yes
   UserID opendkim
   UMask 007
   Mode s
   Socket inet:8891@127.0.0.1
   KeyTable /etc/opendkim/key.table
   SigningTable refile:/etc/opendkim/signing.table
   InternalHosts /etc/opendkim/trusted.hosts
   ```

   `/etc/opendkim/key.table`:

   ```text
   portal._domainkey.bensfoundry.de bensfoundry.de:portal:/etc/opendkim/keys/bensfoundry.de/portal.private
   ```

   `/etc/opendkim/signing.table`:

   ```text
   *@bensfoundry.de portal._domainkey.bensfoundry.de
   ```

   `/etc/opendkim/trusted.hosts`:

   ```text
   127.0.0.1
   ::1
   ```

   Keep those table files readable by `opendkim`. Check `systemctl cat opendkim`
   and any `/etc/default/opendkim` overrides so the service uses that configured
   socket. Add to Postfix's `main.cf`:

   ```ini
   milter_protocol = 6
   milter_default_action = tempfail
   smtpd_milters = inet:127.0.0.1:8891
   non_smtpd_milters = $smtpd_milters
   ```

   Publish the TXT record from `portal.txt` as
   `portal._domainkey.bensfoundry.de`. Never publish `portal.private`.
   Then validate the key and services:

   ```bash
   sudo opendkim-testkey -d bensfoundry.de -s portal -vvv
   sudo postfix check
   sudo systemctl enable --now opendkim postfix
   sudo systemctl restart opendkim postfix
   sudo ss -ltnp
   ```

   Confirm SMTP and the signer listen only on loopback, and that a signer failure
   causes temporary submission failure rather than sending unsigned messages.
4. Configure aligned SPF/DKIM and DMARC for `bensfoundry.de`. Start DMARC in
   monitoring mode (`p=none`) while checking legitimate senders and use a real
   monitored report address. Do not overwrite an existing DMARC policy blindly.
5. Make sure `portal@bensfoundry.de` receives replies/bounces through an existing
   mailbox or forwarding arrangement. Outgoing Postfix alone does not create a
   mailbox. Preserve existing MX records unless deliberately migrating inbound
   mail. Monitor local postmaster/root delivery errors as well.

Successful TCP connection or local SMTP acceptance does **not** prove external
delivery. Inspect `postqueue -p`, `journalctl -u postfix` (or `/var/log/mail.log`
when configured), recipient headers and spam folders. Verify SPF/DKIM/DMARC
results at two external recipient domains before onboarding real users.

### Alternative: external SMTP provider

If direct delivery is delayed or filtered, use a transactional provider's
authenticated SMTP on port 587 with STARTTLS. Create the account, check its
current quotas/sandbox recipient restrictions, verify the sender/domain and
publish the exact SPF/DKIM records it supplies. Keep existing MX records and
merge SPF authorizations. Inspect its bounce/suppression and delivery logs.

Replace the local mail settings in section 3 with:

```dotenv
MAIL_HOST=smtp.provider.example
MAIL_PORT=587
MAIL_USERNAME="PROVIDER_SMTP_USERNAME"
MAIL_PASSWORD="PROVIDER_SMTP_PASSWORD"
MAIL_AUTO_TLS=true
MAIL_REQUIRE_TLS=true
```

Keep `MAIL_SCHEME=smtp` and `MAIL_MAILER=smtp`. Port 587 does not require
Hetzner's port-25 unblock request; see [Hetzner's SMTP policy](https://docs.hetzner.com/cloud/servers/faq/).
This alternative requires no application-code change. Refresh cached config
and restart the queue worker after switching transport settings.

## 3. Install a fresh application

Use a dedicated unprivileged Unix account. Examples use `portal`, application
directory `/srv/luanti-portal/app`, and persistent database directory
`/srv/luanti-portal/data`. The account needs no sudo privileges. Keep backup
archives outside the application and outside every document root.

```bash
sudo useradd --system --create-home --home-dir /srv/luanti-portal \
  --shell /usr/sbin/nologin portal
sudo -u portal git clone YOUR_PORTAL_REPOSITORY_URL /srv/luanti-portal/app
sudo -u portal mkdir -p /srv/luanti-portal/data
cd /srv/luanti-portal/app
sudo -u portal cp .env.example .env
sudo chmod 640 .env
```

Edit `.env` privately **before running Composer's application hooks**. Retain
the other application defaults from `.env.example`, and set:

```dotenv
APP_NAME="Luanti-Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://portal.bensfoundry.de
APP_LOCALE=de
APP_TIMEZONE=Europe/Berlin
APP_KEY=
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/srv/luanti-portal/data/database.sqlite
SESSION_DRIVER=database
SESSION_COOKIE=luanti_portal_session
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
CACHE_STORE=database
INVITATION_CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1300

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_TIMEOUT=15
MAIL_AUTO_TLS=false
MAIL_REQUIRE_TLS=false
MAIL_FROM_ADDRESS="portal@bensfoundry.de"
MAIL_FROM_NAME="Luanti-Portal"
```

Remove any inherited `MAIL_URL`: it can override the separate SMTP connection
fields. The two TLS flags are explicitly false **only for local loopback
Postfix**; disabling automatic TLS avoids trying to validate a local snake-oil
certificate. For an external provider, enable both flags and use its real SMTP
credential. Quote dotenv values correctly, especially credentials containing
spaces, `#`, quotes or dollar signs. `MAIL_ENCRYPTION=tls` is not a setting used
by this project's mail configuration.

Generate `APP_KEY` once on this fresh instance, then preserve it permanently:

```bash
sudo -u portal touch /srv/luanti-portal/data/database.sqlite
sudo chmod 700 /srv/luanti-portal/data
sudo chmod 600 /srv/luanti-portal/data/database.sqlite
sudo -u portal php8.5 /usr/local/bin/composer install --no-dev --prefer-dist \
  --optimize-autoloader --no-interaction
sudo -u portal php8.5 /usr/local/bin/composer check-platform-reqs --no-dev
sudo -u portal php8.5 artisan key:generate --force --no-interaction
sudo -u portal php8.5 artisan migrate --force --no-interaction
sudo -u portal php8.5 artisan admin:create
```

`admin:create` deliberately requires interactive email/name/password input; do
not add `--no-interaction` or put the password in a command argument. Do not run
development seeders, `migrate:fresh`, or the development reset in production.

Build assets from the **same code and lockfiles** on the build machine:

```bash
npm ci --ignore-scripts
npm run build
```

Transfer the complete `public/build/` directory, including `manifest.json`,
`fonts-manifest.json`, and the font files in `assets/`. Keep the entire generated
asset set together. Composer's `filament:upgrade` hook publishes
Filament CSS/JS/fonts on the host; if using a prebuilt artifact, include those
published assets too. Do not upload `public/hot`, local `.env`, local databases,
`node_modules`, local logs, or development bridge tunnel files.

The `portal` user must be able to write `storage/`, `bootstrap/cache/`, and the
SQLite **directory** as well as its file. Keep private storage out of the public
web root; do not publish `storage/app/private` with `storage:link`.

```bash
sudo -u portal mkdir -p storage/app/private/world-templates
sudo chmod 700 storage/app/private
sudo -u portal php8.5 artisan optimize --no-interaction
```

## 4. PHP-FPM, web server and HTTPS

Create `/etc/php/8.5/fpm/pool.d/luanti-portal.conf`:

```ini
[luanti-portal]
user = portal
group = portal
listen = /run/php/luanti-portal.sock
listen.owner = ben
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 500
chdir = /srv/luanti-portal/app
catch_workers_output = yes
```

On this host, Nginx workers run as `ben`, so the socket owner is `ben` while
the PHP application processes still run as `portal`. Verify worker identity
with `ps -o user,pid,args -C nginx` rather than assuming Ubuntu's `www-data`
default. Adjust the socket owner/group to your actual web-server identity and size
`pm.max_children` to leave sufficient memory for Foundry and the queue worker.
The web server needs directory traversal/read access to `public/`; only the
portal PHP worker needs access to `.env`, the database and private storage.

```bash
sudo php-fpm8.5 -t
sudo systemctl reload php8.5-fpm
```

For an existing Nginx server, create an additional site with this application
location configuration inside its own `server` block. Provision the certificate
with the host's existing ACME process first. Redirect HTTP to this HTTPS host.

```nginx
server {
    listen 443 ssl;
    server_name portal.bensfoundry.de;
    # Use the certificate paths and TLS settings from the host's ACME setup.
    ssl_certificate /etc/letsencrypt/live/portal.bensfoundry.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/portal.bensfoundry.de/privkey.pem;
    root /srv/luanti-portal/app/public;
    index index.php;
    charset utf-8;
    client_max_body_size 64m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/luanti-portal.sock;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
}
```

For Caddy running directly on the host, an additional site can instead use:

```caddyfile
portal.bensfoundry.de {
    root * /srv/luanti-portal/app/public
    @private path /.env /.git/*
    respond @private 404
    php_fastcgi unix//run/php/luanti-portal.sock
    file_server
}
```

Use Caddy's actual identity for the FPM socket. If the existing proxy runs in a
container, the host Unix socket is not automatically accessible: use a dedicated
loopback application web server behind that proxy instead. Do not expose FPM to
the Internet. If TLS terminates at another proxy, pass the public host and scheme
correctly and configure only that proxy's address as trusted in
`bootstrap/app.php`; never trust arbitrary forwarded headers. The current app
does not configure trusted proxies by default. Test signed links through the
actual HTTPS route before launch.

Validate the active server's full configuration, then reload it using its
existing service workflow. With Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
curl --fail https://portal.bensfoundry.de/up
```

Match web-server and PHP `upload_max_filesize` / `post_max_size` limits to the
template ZIPs you plan to upload. Check Foundry after the reload. The `/up` route
alone does not prove database, mail, queue or game readiness.

### Reload Nginx after webroot certificate renewal

With `certbot certonly --webroot`, the certificate files renew independently
of Nginx. Keep the HTTP `/.well-known/acme-challenge/` location reachable and
add a deploy hook restricted to this certificate:

```bash
sudo install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
sudo tee /etc/letsencrypt/renewal-hooks/deploy/luanti-portal-nginx >/dev/null <<'EOF'
#!/bin/sh
set -eu
if [ "${RENEWED_LINEAGE:-}" = /etc/letsencrypt/live/portal.bensfoundry.de ]; then
    /usr/sbin/nginx -t
    /usr/bin/systemctl reload nginx
fi
EOF
sudo chmod 755 /etc/letsencrypt/renewal-hooks/deploy/luanti-portal-nginx
sudo certbot renew --cert-name portal.bensfoundry.de --dry-run --run-deploy-hooks
```

The dry run tests renewal and the reload hook without replacing the live
certificate. Check the existing Certbot renewal timer/cron remains enabled.
See [Certbot's hook and dry-run documentation](https://eff-certbot.readthedocs.io/en/stable/using.html#renewing-certificates).

## 5. Queue worker and scheduler

Create `/etc/systemd/system/luanti-portal-worker.service`:

```ini
[Unit]
Description=Luanti Portal queue worker
After=network-online.target
Wants=network-online.target

[Service]
User=portal
Group=portal
WorkingDirectory=/srv/luanti-portal/app
ExecStart=/usr/bin/php8.5 artisan queue:work database --sleep=3 --tries=3 --backoff=30 --timeout=1200 --max-time=3600 --memory=256
Restart=always
RestartSec=5
TimeoutStopSec=1260
KillSignal=SIGTERM
KillMode=mixed
UMask=0077

[Install]
WantedBy=multi-user.target
```

The longest provisioning job declares a 1200-second timeout and its own
single-attempt limit. The database reservation expiry is 1300 seconds, longer
than job execution. Keep `TimeoutStopSec` above 1200 so deployments can wait for
in-flight work. This is also why generic short-lived cron queue workers are not
the default here. One worker is sufficient for the initial small installation;
provision game servers before the presentation so a long provisioning job does
not delay authentication email.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now luanti-portal-worker
sudo systemctl status luanti-portal-worker
```

Create `/etc/cron.d/luanti-portal` (owned by root, mode 0644, trailing newline):

```cron
* * * * * portal cd /srv/luanti-portal/app && /usr/bin/php8.5 artisan schedule:run --no-interaction >> /srv/luanti-portal/app/storage/logs/scheduler.log 2>&1
```

Ensure Ubuntu's `cron` service is active. This minute schedule recovers account
jobs, reconciles game windows, and recovers provisioning/DNS cleanup. Do not run
multiple copies of this schedule or use the local `dev.ps1` stack in production.

## 6. Hosted acceptance checklist

Import the published world ZIPs in **Luanti-Weltvorlagen** before onboarding.
Educators request a template from the portal library without needing an existing
game server. A global administrator approves the request and confirms the paid
VM configuration (defaults: `cx23`, `nbg1`, Ubuntu 24.04). Luanti image, bridge,
GitHub access, game source and Hetzner access must be configured beforehand.
The queue creates one VM per approved library request; the minute scheduler
recovers provisioning and connects the confirmed template to its private game
instance. The requested template revision is retained even if the library changes.
Caseworkers can review and reject requests; paid VM approval requires a global
administrator. The approved game window stops the game, but the VM remains
billable until an administrator deletes it.

If provisioning fails, inspect the existing server entry and use **Serverbereitstellung
erneut prüfen** on the request. Existing VMs are reused, including recovery after
an uncertain creation response. A deleted VM is never silently replaced.

Use accounts/inboxes you control; check at least two external recipient domains.
Do not invite the interested parties until this checklist passes.

- [ ] Foundry still opens and its live connections work.
- [ ] `/up`, `/portal/register`, `/portal/login` and the admin login load over
  HTTPS with compiled styles and no Vite development-server requests.
- [ ] `/.env`, `/.git/config`, `/storage/app/private/` and a database URL do not
  expose files. Debug output is disabled and cookies are secure/host-scoped.
- [ ] Register from a clean browser, receive verification mail, inspect HTML and
  plain text, and follow the link while logged in. Check the Postfix delivery
  log (or provider log) and recipient headers for SPF/DKIM/DMARC alignment,
  not just a portal toast.
- [ ] Verification resend works. An expired link offers a route to request a new
  email; altered links are rejected. No mail links contain localhost or Foundry's
  hostname. Check spam folders and Postfix deferred/bounce records (or provider
  suppression/bounce records).
- [ ] Reset a portal password and the admin password via their respective login
  pages; the reset links work and expired/used tokens are rejected.
- [ ] Create a fresh organization, invite a second controlled address, register
  through that invitation, verify, and explicitly accept. Check the intended
  role and that another organization remains inaccessible.
- [ ] Confirm worker/scheduler activity, then restart the worker and repeat a
  verification email to prove delivery does not depend on an SSH terminal.
- [ ] Configure a real bridge/game server and published template. The
  development-only `https://localhost:18443` tunnel is not a hosted endpoint.
  Verify the HTTPS API connection and actual game host/UDP port separately.
- [ ] Request a library template as an educator/manager without an existing game
  server. Approve it as a global administrator, check the paid VM confirmation,
  and wait for automatic server provisioning and a running private instance;
  create participant/facilitator game accounts, wait for confirmed transmission,
  and log in through a real Luanti client with both roles.
- [ ] Verify out-of-window access is denied and a stopped game's save remains.
- [ ] On a disposable test VM, verify that deletion requires the exact server
  name and warns about losing game worlds and player progress. Wait for confirmed
  VM deletion and completed DNS cleanup, then remove its portal entry. Unused
  server template copies should disappear; existing requests and game history,
  and the original template ZIPs in the portal library, must remain readable.

An SMTP-accepted message can still be delayed, bounced or filtered later.
Verification/reset emails use the queue; invitations are submitted synchronously
and revoke their link after an uncertain/failed submission. Retry later through
the form rather than reactivating that revoked invitation. Mail failures still
consume invitation quotas.

## 7. Updates, backups and rollback

Before updating, record the deployed commit and take a private, restorable
backup. Keep copies off the VM as well. Retain the exact application/lockfiles,
vendor dependencies and compiled/published assets for the previous release.

1. Put the portal into maintenance mode (`sudo -u portal php8.5 artisan down`)
   from its application directory. Temporarily disable its cron entry, let any
   running scheduler finish, and stop `luanti-portal-worker` gracefully. Do not
   interrupt a provisioning job halfway through its shutdown grace period.
2. Create a root-only backup directory (`umask 077`) outside the document root.
   Back up `.env` including the original `APP_KEY`, all `storage/app/private/`
   content including retained world-template ZIPs, and the deployed release.
   Use SQLite's backup operation rather than a live plain file copy:

   ```bash
   sudo install -d -m 700 /var/backups/luanti-portal/BEFORE_UPDATE
   sudo sqlite3 /srv/luanti-portal/data/database.sqlite \
     ".backup '/var/backups/luanti-portal/BEFORE_UPDATE/database.sqlite'"
   sudo chmod 600 /var/backups/luanti-portal/BEFORE_UPDATE/database.sqlite
   sudo tar -C /srv/luanti-portal -czf \
     /var/backups/luanti-portal/BEFORE_UPDATE/app.tar.gz app
   sudo chmod 600 /var/backups/luanti-portal/BEFORE_UPDATE/app.tar.gz
   ```

   Replace `BEFORE_UPDATE` with a unique backup name. The archive includes
   secrets and potentially authentication links in logs: encrypt off-host copies
   and restrict access. Test restore on an isolated copy with mail/workers/cron
   disabled. Bridge saves/registry/receipts need separate backups on their host.
3. Deploy the reviewed commit and its matching built assets without replacing
   the persistent database, `.env`, or private storage. Install locked production
   dependencies as above; run `migrate --force --no-interaction`, then `optimize`.
   Never run `composer update` on the server.
4. Reload PHP-FPM for OPcache, start/restart the queue worker, restore the cron
   entry, and run `artisan up`. On normal rolling updates, `artisan queue:restart`
   asks workers to exit after their current job; systemd starts them again.
   Environment/SMTP changes require `config:cache` and worker restart too.
5. Check `/up`, login, a controlled queued email and Foundry before reopening
   the demonstration. Inspect failed jobs and service logs.

If rollback is needed, return to maintenance mode and stop the portal worker and
schedule. Restore the previous complete code/dependency/asset set. If migrations
changed database compatibility, restore its matching database/private-file
snapshot and `.env`/key together; do not blindly run `migrate:rollback`. Restore
ownership to `portal`, rebuild caches, reload FPM, restart worker/cron and leave
maintenance mode. Restoring the database can discard post-backup changes and
does not undo actions already accepted by a remote bridge. Inspect remote
state and uncertain jobs before resuming orchestration.

## 8. Troubleshooting

| Symptom | Check |
| --- | --- |
| Assets missing / Vite manifest error | Matching `public/build/manifest.json`, fonts and published Filament assets; no `public/hot`; rebuild/upload the complete asset set. |
| 502 or permission errors | FPM pool status/socket owner; app directory traversal; writable storage/cache and SQLite directory. |
| SQLite is locked | Avoid parallel deployment/scheduler processes; inspect long transactions and logs. Do not delete the database or queued jobs to recover. |
| Verification/reset email absent | Worker status, `artisan queue:failed`, local Postfix/signing service, `postqueue -p` and mail logs; for an external provider, credentials/verified sender/sandbox limits and delivery/suppression logs. |
| Local SMTP connection/TLS error | Same-host `127.0.0.1:25`, Postfix loopback listener, `MAIL_SCHEME=smtp`, and both TLS flags explicitly false; keep this exception local only. |
| External SMTP TLS/authentication error | Port 587, `smtp` scheme, required STARTTLS, current CA certificates, provider's SMTP credential; do not disable peer verification. |
| Link invalid or wrong origin | Exact HTTPS `APP_URL`, host/proxy routing, server clock, unchanged `APP_KEY`, refreshed config cache and restarted workers. |
| Invitation form reports a failure | Sanitized submission warning in portal logs; Postfix/signer or external provider status/configuration; wait for quotas before creating another invitation. |
| Email accepted but not in inbox | Postfix queue/deferred/bounce records or provider delivery logs, spam filtering and recipient headers/SPF/DKIM/DMARC; acceptance is not delivery. |
| Game remains pending | Scheduler/queue, bridge HTTPS endpoint/token and template commissioning; account acceptance alone does not start a game. |

Useful commands from the application directory:

```bash
sudo journalctl -u luanti-portal-worker --since '15 minutes ago'
sudo -u portal php8.5 artisan queue:failed --no-interaction
sudo -u portal php8.5 artisan schedule:list --no-interaction
sudo tail -n 50 storage/logs/scheduler.log
```

Treat application logs and failed-job payloads as private. Inspect an identified
failed job before a targeted `queue:retry UUID`; retries may resend an email.
Do not purge jobs or reissue uncertain bridge operations as a shortcut. Apply
the host's log rotation/retention policy to portal and scheduler logs.

References: [Laravel deployment](https://laravel.com/docs/13.x/deployment),
[queue timeouts and process monitoring](https://laravel.com/docs/13.x/queues),
[SMTP TLS and delivery semantics](https://symfony.com/doc/current/mailer.html),
[Postfix basic configuration](https://www.postfix.org/BASIC_CONFIGURATION_README.html),
[OpenDKIM configuration](https://manpages.ubuntu.com/manpages/resolute/man5/opendkim.conf.5.html).
