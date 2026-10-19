# Luanti Portal

A staff portal built with Laravel 13 and Filament 5. It manages organizations,
world requests and private Luanti games through the separate `civicrm_bridge`
project. The application UI is German; this documentation is English.

Status reflects source and tests as of 2026-10-17, not verification of a live
deployment. **Implemented** means present in code; **Provisional** marks moving
parts or environment-specific assumptions; **Planned** identifies explicit future
work. Other missing capabilities are listed as known limitations.

## Current behavior

**Implemented:**

- Registration, email verification, organization creation/memberships, join requests,
  personal invitations, account administration and organization verification
  with verification time and actor.
- Global-admin server management: game hostname/port, encrypted bridge token
  and HTTPS connection checks. Published template ZIPs live in the portal's
  private library. New automatically provisioned servers receive every template
  from the library snapshot taken at creation; installation verifies their hashes
  and revisions before registering the commissioned server copies.
- Organization world requests, internal approval/rejection and non-overlapping
  reservations per server. Approved requests receive a private game instance
  copied from a published template revision, with separate accounts and saves.
- Game accounts separate from portal users, with participant/facilitator roles,
  encrypted stored initial passwords, asynchronous delivery and time-bound
  admission. Applicants and organization managers can manage eligible requests'
  accounts alongside internal staff.
- Hetzner VM creation, status recovery and deletion with ownership checks and
  per-VM SSH keys. An optional selected official Luanti image is installed on a
  fresh Ubuntu 24.04 VM and verified over SSH against a stored host identity.

**Provisional / moving parts:** UI organization, template publication and operator
upgrades, the local development reset, and Hetzner bootstrap. Image verification
checks Docker, the installed image ID and engine version; it does not deploy the
bridge, install game content, create a playable world, or configure DNS/TLS and
public endpoints. The bridge's Linux/systemd recipe is a separate deployment.

**Planned:** the bridge repository describes a future CiviCRM integration.
Contact mapping and participation/admission policy remain open; no CiviCRM
extension is implemented. This is not a committed portal delivery schedule.

## Components and game lifecycle

| Component | Responsibility |
| --- | --- |
| Portal | Portal identities/permissions, organizations, approvals, server reservations, game-account roster and orchestration |
| Bridge HTTPS API | Bearer authentication, durable job acceptance, delivery, polling and recovery |
| Bridge controller | Reviewed template publication, private copies, approved schedules and service transitions |
| Luanti mod | World-local authentication, role privileges, admission windows and account receipts |

Approval records an access window and selects a template revision. The scheduler
prepares/configures the private copy and starts it during that window; the queue
delivers account jobs. Dates are inclusive calendar dates in `Europe/Berlin`,
converted to UTC Unix seconds with an exclusive end at midnight after the last
day. Both the bridge schedule and the mod's account windows enforce access.

Date edits require reapproval while the previous approved window remains in
effect. Extending a booking reuses its save. A confirmed template change on the
approved server prepares a fresh copy, archives the old copy and recreates the
roster using stored initial passwords. Passwords changed inside the old game
are not transferred. Expiry stops a game and retains its save; archival prevents
reactivation and also retains the save.

The installer verifies commissioned templates through `/v1/world-templates`;
the portal uses account/lifecycle job endpoints for private games. HTTP
envelopes remain v1 while portal account and instance payloads/results use v2.
See the bridge's [integration contract](../civicrm_bridge/docs/INTEGRATION.md)
and [HTTPS API/deployment guide](../civicrm_bridge/docs/HTTPS_API.md). These sibling
links assume the shared workspace layout; the bridge is a separate project.

## Access boundaries

- `/admin`: internal administration for global admins and caseworkers.
- `/portal/register`: staff self-registration, initially without memberships.
- `/zugang`: memberships, organization search, join requests and invitation
  acceptance; requires verified email.
- `/portal`: organization area with organization switching.

Global admins manage servers/settings, create organizations, block portal
accounts and invite internal roles. Internal staff manage memberships and decide
world requests. Organization managers manage their organization's name, join
requests and staff memberships; only internal staff grant additional manager
memberships. Verified active users may also create their own organization and
receive its manager membership. Memberships are scoped to each organization;
internal staff record organization verification separately from email verification.

Invitations last seven days, are single-use and bind to the verified email
address. Acceptance rechecks the inviter's current permissions. The first
production global admin is created interactively with `php artisan admin:create`;
the command takes no password argument, preserves existing passwords and refuses
bootstrap once an active global admin exists. Further internal roles use invitations.

## Development and deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for a manual fresh production installation
alongside Foundry on an Ubuntu VM, local Postfix or external SMTP setup, background services,
acceptance checks, backups and rollback. No installer script is required.

See [DEVELOPMENT.md](DEVELOPMENT.md) for fresh-clone setup, portable Windows
commands, local defaults and the development-only template migration reset.
The installed package versions are pinned in the lockfiles; the current local
toolchain uses PHP 8.5.

Deployment requires HTTPS, a canonical `APP_URL`, a stable `APP_KEY`, a real mail
transport, a durable queue worker and `schedule:run` every minute. Portal-generated
links use the configured application origin. Retain database/queue state and a
shared lock-capable cache across workers. Back up the database and application
key privately: bridge tokens, initial passwords, provisioning keys and bootstrap
data depend on that key. Back up `storage/app/private/world-templates` with the portal database; retained
archives are needed to retry pinned server installations. Library updates apply
to future server creation and preserve existing server copies. Bridge world,
registry and receipt backups are separate from source control. See the bridge guide for service identities and permissions.

## Known limitations

- One game runs per configured bridge service/port; overlapping approvals on the
  same server are rejected. Participant count is request metadata, not an enforced
  account quota.
- Approval and job acceptance do not imply a running game or successful import.
  Partial account batches and unknown outcomes require inspection; retries reuse
  saved IDs/bytes. Unknown outcomes can block subsequent grants.
- The portal limits bridge responses to 256 KiB, validates HTTPS and does not
  follow redirects. API reachability/token checks do not prove game readiness.
- The bridge offers no password-reset, account-deletion/rename, roster-query or
  save-deletion API. Stored initial passwords are not current game passwords.
  Archived saves and completed receipt history have no automatic expiry policy.
- Hetzner VM deletion is blocked while portal template or game-instance records
  reference the server; a running VM or verified engine is not a playable game.
- Existing shared-world records have no automatic production conversion to
  templates/private games. The development reset is destructive and local-only.

Version application code, configuration templates, tests, documentation and
lockfiles. Keep credentials, databases, runtime data, dependencies and build
outputs out of Git. The bridge, workspace configuration and portable toolchain
are outside this repository.
