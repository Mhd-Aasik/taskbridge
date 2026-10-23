# Hosting and release operations

This is preparation, not a deployment record. No hosting account, running service, external mail provider or public site was changed during handoff. Use [installation](installation.md) for dependency requirements and [backup/recovery](backup-recovery.md) before any release.

## Web root, uploads and secrets

Point the server document root to **`<release>/public`**, never the repository root. Serve Laravel through `public/index.php` with normal front-controller rewriting. Deny dotfiles, environment files, source directories, logs and backups at the web server. `vendor`, `storage`, `.git` and `bootstrap` must not be directly web-accessible. The public `/up` health route checks framework boot; it is not a complete database/private-storage/SMTP readiness probe.

Keep the existing application key in protected persistent configuration. Use one consistent key across releases/replicas; never run `key:generate` during updates. Persist `storage/app/private/avatars`, `storage/app/private/project-covers` and `storage/app/private/deliverables` with database backups from the same logical point. The configured disks have local roots; merely setting `FILESYSTEM_DISK=s3` does **not** move those explicitly named disks to S3. Multi-replica hosting needs shared durable storage for these roots and suitable Livewire temporary uploads, or a separately tested storage adaptation. Do not symlink private storage into `public`; current features use authorized controllers, not public object URLs.

Give the PHP process write access to **`storage`** (including logs, framework cache/views/sessions and upload temp directories) and **`bootstrap/cache`**. Use Windows ACLs or platform ownership for the application identity, not blanket world-write permissions. Protect `.env`, backups and generated `bootstrap/cache/config.php`: a cached config contains secrets. Keep code read-only to the web process where the hosting model permits it.

Set web PHP and proxy/body limits high enough for a 10 MB deliverable plus multipart overhead; `post_max_size` must exceed upload size. Photo/cover limits are stricter at 2 MB. The application still validates content, archives and dimensions. CLI limits do not prove web-SAPI limits. Monitor disk capacity and upload timeouts. Application validation is not antivirus scanning; an operational malware policy remains a hosting decision.

## Production environment

Replace examples privately, retain the key, and use the actual canonical domain:

```dotenv
APP_NAME=TaskBridge
APP_ENV=production
APP_DEBUG=false
APP_URL=https://taskbridge.your-owned-domain.example
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
```

Use an application-specific `SESSION_COOKIE` when multiple applications share a host. Configure HTTPS at the server and trust only the actual reverse-proxy hops; this repository does not preconfigure a hosting provider's proxy list. Verify scheme detection, cookies, signed verification links and CSRF with that topology. `APP_URL` controls trusted account-email links; it does not configure DNS, TLS or the web server. Preserve the UTC application timezone; calendar rules explicitly use Asia/Colombo.

Use a MySQL runtime account scoped to one database and separate migration privileges where practical. Database URL overrides (`DB_URL`, or stale host-platform `DATABASE_URL`) must not silently select another database. Keep password-reset tokens and users on the same database connection for transactional single-use consumption; database sessions on that same connection also share revocation rollback. Separate session backends rely on access-version enforcement if physical deletion fails; a split reset-token database has not been verified.

SMTP uses the actual variables in [account-email.md](account-email.md), including `MAIL_SCHEME`/optional `MAIL_URL`, not a `MAIL_ENCRYPTION` variable. Configure a provider-authorized sender, TLS, DNS authentication and a trusted HTTPS `APP_URL`. The log mailer stores tokens in logs and does not deliver. Provider authentication, TLS, delivery, spam placement and real email-client rendering are **pending**. Email verification remains optional; do not add `MustVerifyEmail` or require historical users to verify as part of deployment. Payment scope remains external tracking only.

## Deployment and caches

Use a reviewed release at a known commit, a protected maintenance window or equivalent release routing, and a consistent backup before applying schema changes. On a fresh deployment, an authorized operator can use the interactive [first-Admin command](installation.md#first-administrator) after migrations and identity checks. It requires secure terminal access; automation must not inject credentials or bypass its interactive confirmation. Skip it on updates and when any Admin exists. The following PowerShell sequence is a template for the **intended new release/environment**, not a command to run indiscriminately against the live checkout. Identity checks and backup approval precede it.

```powershell
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
if ($LASTEXITCODE -ne 0) { throw 'Dependencies failed' }
composer check-platform-reqs --no-dev
npm.cmd ci
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Assets failed' }
php artisan down
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Migration failed; keep maintenance and investigate' }
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Stop and investigate any failed command; resume only after readiness checks.
php artisan up
```

Check every native exit code in automation. Build Node assets in CI/release preparation where possible; serving compiled CSS does not require Node at runtime. Publish `public/build/manifest.json` with its matching asset files. The entry is CSS-only; Flux/Livewire scripts remain in the layouts. No empty placeholder JavaScript is required.

`config:clear`, `route:clear` and `view:clear` reverse those caches. Cached config must be rebuilt after environment changes; `.env` edits alone may not affect a cached app. `optimize:clear` also clears the configured default cache, so do not run it casually on live traffic: that can remove rate-limit state. Cache paths must belong to the intended release, not a running sibling checkout. Never copy cached secrets from development into a release. Restart only the hosting model's long-lived PHP/application processes after changes; this guide does not change services for you.

The migration history is additive, but a `down()` often drops columns/tables containing immutable business history. **Code rollback does not safely roll back schema/data.** Do not use `migrate:rollback` as an automatic deployment recovery strategy. Prefer a compatible previous release or reviewed forward fix. If disaster recovery is necessary, restore the matched database/files/key in a new isolated environment, verify it, then perform a separate controlled cutover. Do not restore over the running installation.

## Scheduler and workers

- Account email is synchronous through Laravel notifications; in-app notifications and activity are written in business transactions. No feature requires `queue:work` or a queue listener today, even though queue configuration/migrations and the starter `composer dev` script exist.
- Feedback release is derived on reads from the persisted completion time; no scheduled release write or worker is needed. Messages/notifications use authenticated polling, not a WebSocket daemon.
- `routes/console.php` has only the starter **hourly `inspire`** schedule. There is no business scheduler requirement; `schedule:list` confirms this. Backups, log retention, uptime checks and housekeeping need an operator's separately configured schedule, not an assumed application task.
- Avoid introducing queue/cron requirements during deployment unless a future reviewed feature needs them.

## Logging and incident recovery

With the shown configuration Laravel writes `storage/logs/laravel.log`; configure protected rotation/retention and monitoring. Restrict logs that can contain personal data or mail links. Do not publish debug exception pages, dump configuration or log credentials/tokens. Business and audit history are database records and must not be deleted as log housekeeping.

On an incident, preserve evidence securely, put the affected release into controlled maintenance if needed, check database identity and disk health, and use the [recovery procedure](backup-recovery.md) in a disposable environment. Keep original uploads and environment/key; never rotate keys or wipe tables as troubleshooting. Suspension and password recovery revoke sessions without erasing history; they are not substitutes for a reviewed incident response. Bootstrap cannot repair/replace an existing Admin; use normal password recovery for an existing account and a separately authorized incident process if that is unavailable.
