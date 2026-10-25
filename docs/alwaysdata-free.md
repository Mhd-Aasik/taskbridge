# Alwaysdata Free university demonstration

Readiness work only, checked 25 October 2026. No account, mailbox, DNS record,
remote database or public website was created or changed. Do not upload the local
`.env`, database, recovery backup, demo manifest or private uploads. This guide
extends [hosting](hosting.md), [email](account-email.md) and
[backup/recovery](backup-recovery.md); it does not authorize deployment.

## Current host capabilities

Official [Free pricing](https://help.alwaysdata.com/en/admin-billing/billing/public-cloud-prices/)
lists 1 GB disk, 256 MB RAM, one-quarter CPU and three rolling backup days. Files,
databases and mail share the disk quota. Free websites must use the supplied
`ACCOUNT.alwaysdata.net` address and non-profit use. A university demonstration
must remain synthetic and noncommercial; real marketplace sales/commissions are
outside this plan. [Free-use restrictions](https://help.alwaysdata.com/en/docs/admin-billing/profile/suspension/)
also prohibit generic always-running services and excessive resource use. No
background queue, WebSocket server or business scheduler is required by TaskBridge:
email is synchronous, messages use polling and feedback release is calculated on
reads. Do not start `composer dev`, Pail, a worker or the starter inspire schedule.

[PHP configuration](https://help.alwaysdata.com/en/docs/web-hosting/languages/php/configuration/)
documents PHP 8.2–8.5 among the selectable versions, native GD, PDO MySQL, fileinfo,
mbstring, XML and zip, and OPcache. Select a current patched PHP 8.2 build initially,
matching the exercised major/minor; verify CLI and web extensions separately.
The default 256M per-request limit is not a reservation of the account's entire
256 MB budget. Use one PHP version at account level; the host warns that separate
site versions increase RAM use. Apply the conservative
[PHP settings](../deployment/alwaysdata/php.ini.example) and check the effective
web limits. PHP extensions and minor patch availability on the actual account
remain a host-side preflight item.

[MariaDB support](https://help.alwaysdata.com/en/docs/web-hosting/databases/mariadb/)
lists 10.11, 11.4 and 11.8. The [official changelog](https://changelog.alwaysdata.com/)
states new Public Cloud accounts use 11.8 from 7 October 2026. Select/confirm that
family and obtain the exact server version before deployment. Use Laravel's
`DB_CONNECTION=mysql`, even though the server is MariaDB: the existing Admin
bootstrap explicitly supports the mysql connection and its advisory lock, whereas
the separate Laravel mariadb connection name has not been qualified here.
Database/user names and privileges come from the panel, not local credentials.

[SSH/SFTP](https://help.alwaysdata.com/en/docs/technical-specifications/login-details/)
use port 22 at `ssh-ACCOUNT.alwaysdata.net`. A PHP site is served by Apache with
its document root set to the release's **public directory only**. TLS is supported;
the supplied address uses the host's certificate configuration. Enable
[Force HTTPS](https://help.alwaysdata.com/en/docs/web-hosting/sites/ssl-tls/redirect-http-to-https/)
in the site SSL settings; this redirect happens at the host proxy. The final
certificate chain, scheme detection, cookies, CSRF and signed links remain
unverified until an authorized deployment. Do not trust arbitrary forwarded
headers or set all proxies trusted just to hide a mixed-scheme symptom.

## Build a clean release locally

Use a committed revision, a new empty output location and locked packages. The
[builder](../deployment/alwaysdata/prepare-release.ps1) exports tracked files only,
installs Composer with `--no-dev`, then runs `npm.cmd ci` and the Vite build in its
own source boundary. Composer must finish before Vite because Flux CSS is a
Composer dependency. The final runtime tree excludes `.git`, `node_modules`, tests,
`.env`, local uploads and logs. Original font/demo artwork licenses remain present.
The build uses in-memory SQLite and array transports; no local database is selected.

```powershell
Set-Location 'C:\Users\ab\Projects\taskbridge'
& deployment/alwaysdata/prepare-release.ps1 -Ref HEAD -Destination 'C:\Users\ab\Projects\taskbridge\storage\framework\alwaysdata-release-REPLACE_UNIQUE_ID'
if ($LASTEXITCODE -ne 0) { throw 'Release preparation failed' }
```

The script retains `work/` for inspection and outputs a curated `release/` plus
`release-measurement.json`. Upload only the curated release **after deployment is
separately authorized**. Do not upload the build directory, dependencies caches,
source archive, disposable MariaDB or verification reports. No Node installation
or Composer download cache is required on the host at runtime. If installing on
Linux instead, use `composer install --no-dev --prefer-dist --no-interaction
--optimize-autoloader` from the lockfile; do not update packages on the server.
Always run `composer check-platform-reqs --no-dev` using the host's actual PHP.

## Storage and memory budget

Allow two curated releases for code rollback, persistent database/private storage,
the host's own account files/mail, temporary multipart and Livewire upload copies,
bounded logs and a safety reserve. A proposed conservative allocation out of the
advertised 1,000 MB is: 250 MB for two releases, 100 MB for MariaDB growth, 200 MB
for private files, 100 MB for temporary uploads, 50 MB for logs/mail/account files
and 300 MB reserve. These are operator budgets, not measured host quotas or enforced
application caps. Compare the actual measured release with the allowance before
upload; keep backups off-host to avoid consuming the small quota. Monitor the panel's
total usage and `du -sk` on the account, not only the release tree.

The locally prepared runtime measured **39.4 MiB logical / about 59.1 MiB of
estimated 4 KiB file blocks per release**, before database/private data. See the
[exact measurements and scope](free-hosting-verification.md#production-release-footprint-and-checks).
Directory/inode overhead, host account files and actual quota accounting are not
included in that estimate. Check available quota for the temporary matched backup
set as well as the live release before creating a host-side backup; retain it
off-host after protected transfer and validation.

Photos/covers accept at most 2 MiB and 4096 × 4096 pixels. Covers preflight a
conservative decoded-image estimate and resize to at most 1600 × 1000; a 4096-square
cover needs roughly 300 MiB by that estimate and is intentionally rejected under
128M. Deliverables accept 10 MiB; images have a decoded-memory guard and a
40-megapixel ceiling, and ZIP expansion is independently bounded. Account photos
decode/re-encode without resizing and now perform the same conservative pre-decode
memory check. Compressed size alone does not predict their native GD memory.
Use demonstration images at most 1600 pixels on the long side,
avoid concurrent image uploads and measure actual web worker RSS before permitting
larger public traffic. `post_max_size=16M`, `upload_max_filesize=10M` and a 128M PHP
limit are initial demo settings, not a load-test result. Account-wide worker/Apache/
OPcache memory and simultaneous requests can still exceed 256 MB. Polling tabs also
consume the one-quarter CPU allowance. Keep the demo audience small and observe
the actual account's resource graph.

Source compatibility review: migrations use InnoDB foreign keys and CHECK
constraints, actions acquire row locks within transactions, Admin bootstrap uses
GET_LOCK/RELEASE_LOCK, and duplicate handling recognizes the mysql driver's 1062 /
SQLSTATE 23000 unique violations. Those facilities exist in the documented MariaDB
families ([constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint),
[locking](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update),
[duplicate error](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1000-to-1099/e1062)),
but source inspection does not prove the deployed schema or concurrent
behavior. Local Windows MariaDB 11.8.9 initialization did not complete in two guarded
attempts; no migrations/workflows ran on that engine. **MariaDB compatibility is
still an explicit deployment gate.** Before release, qualify a new disposable
MariaDB database on the actual host: all migrations, enforced foreign-key/CHECK
violations, duplicate/retry handling, accepted assignment terms, revision/review,
completion/payment/feedback and password/session revocation. The existing MySQL
race harness uses MySQL-specific performance_schema lock observations and must not
be relabelled a MariaDB pass or pointed at a production database.

## Later host setup (instructions, not executed)

Required account inputs are listed below. Keep the PHP site disabled until its
configuration, migrations and access checks are ready. Use a separate new database
and strong internet-facing participant/Admin passwords; retain the local demo
passwords untouched. Production refuses `taskbridge:demo`; do not weaken that guard
or temporarily label an internet deployment `local`. Start with an empty hosted
database and add authorized synthetic examples through normal workflows later.

Layout:

```text
/home/ACCOUNT/taskbridge/releases/COMMIT/    application + vendor + public/build
/home/ACCOUNT/taskbridge/shared/.env         protected new deployment secrets
/home/ACCOUNT/taskbridge/shared/storage/     persistent private files and runtime
/home/ACCOUNT/taskbridge/current             selected release symlink
```

The following shell commands apply only to that future account. Replace every
placeholder privately and inspect resolved paths. They do not clone/import local
data. The shared environment is created through a private SSH editor or hosting
secret mechanism from [the placeholder template](../deployment/alwaysdata/environment.example).
Generate a new deployment APP_KEY once, privately; preserve it across updates and
include it in protected recovery material. Never regenerate the local key.

```sh
umask 077
base="$HOME/taskbridge"
release="$base/releases/REPLACE_COMMIT"
shared="$base/shared"
test -f "$release/RELEASE_COMMIT" || exit 1
mkdir -p "$shared/storage/app/private/avatars" "$shared/storage/app/private/project-covers" "$shared/storage/app/private/deliverables"
mkdir -p "$shared/storage/framework/cache/data" "$shared/storage/framework/sessions" "$shared/storage/framework/views" "$shared/storage/logs"
test -f "$shared/.env" || exit 1
chmod 600 "$shared/.env"
# On a new uploaded release only; storage contains empty runtime directories.
test ! -L "$release/storage" || exit 1
test ! -e "$release/storage.dist" || exit 1
mv "$release/storage" "$release/storage.dist"
ln -s "$shared/storage" "$release/storage"
test ! -e "$release/.env" || exit 1
ln -s "$shared/.env" "$release/.env"
cd "$release" || exit 1
composer check-platform-reqs --no-dev || exit 1
```

Verify the intended database hostname, database name, user and MariaDB version in
the panel/CLI before migration. Leave DB_URL/DATABASE_URL overrides unset. Fresh
setup uses only the intended migrations and the two idempotent reference seeders:

```sh
php artisan config:clear || exit 1
php artisan migrate:status
# A fresh database may report the missing migration table. Inspect before continuing.
php artisan migrate --pretend || exit 1
php artisan migrate --force || exit 1
# Fresh setup only; do not automatically reseed every update.
php artisan db:seed --class=SkillSeeder --force || exit 1
php artisan db:seed --class=CategorySeeder --force || exit 1
php artisan config:cache || exit 1
php artisan route:cache || exit 1
php artisan view:cache || exit 1
php deployment/alwaysdata/preflight.php --database=REPLACE_ACCOUNT_taskbridge || exit 1
```

The [CLI preflight](../deployment/alwaysdata/preflight.php) checks production/debug,
the supplied HTTPS origin, key shape, cookies, extensions, GD formats, private
writable paths, built assets, expected database identity, server family, InnoDB and
migration counts. It performs no database writes or SMTP delivery. `--offline`
omits live database/SMTP configuration requirements; it is not a deployment pass.
Leave `MAIL_MAILER=array` until sender confirmation; full preflight deliberately
fails its SMTP-readiness check in that state. Pending email does not justify
fabricating delivery evidence.

Configure the PHP site at `/home/ACCOUNT/taskbridge/current/public`, the supplied
account address only, Force HTTPS and the chosen account-level PHP version. For
the first selection, create `current` as a symlink to the verified release. For
updates, retain a known compatible previous release, pause writes in maintenance,
capture/verify a consistent backup, migrate the new release, then replace the
symlink atomically using a temporary symlink and `mv -Tf` on the same filesystem.
Use release-specific `bootstrap/cache` and persistent private storage. Config and
compiled view caches may contain secrets/private paths: protect them and never
copy them from development. Preserve database rate-limit state; do not casually
run `optimize:clear`.

## Zero-cost SMTP preparation

Prefer the included Alwaysdata mailbox SMTP **if the account supplies an owned,
usable sender**. The official [mailbox setup](https://help.alwaysdata.com/en/docs/emails/create-an-email-address/)
and [SMTP settings](https://help.alwaysdata.com/en/docs/emails/use-an-email-address/)
document `smtp-ACCOUNT.alwaysdata.net`, port 465 implicit TLS (or 587 STARTTLS), with
mailbox address/password authentication. Use the actual mailbox assigned/created
for this account, or an existing owned domain's mailbox; no domain purchase or
paid trial is required or authorized here. Availability of an owned sender is
still an input, not an assumption that any `@alwaysdata.net` address may be used.
If no owned mailbox is available, leave external mail pending rather than inventing
a sender. Never use the local demo Gmail addresses without ownership confirmation.

If the user has no owned domain/mailbox on the host, a zero-cost alternative is an
explicitly confirmed personal Gmail account controlled by the user, using
`smtp.gmail.com:465`, `MAIL_SCHEME=smtps`, that exact mailbox as both SMTP username
and From address, and a dedicated app password stored only in the protected host
environment. Google's [app-password guidance](https://support.google.com/accounts/answer/185833)
requires 2-Step Verification; some organization, security-key-only or Advanced
Protection accounts cannot use it. Do not use the normal Google password, weaken
2-Step Verification or add a new OAuth integration for this deployment. Sender
eligibility, Google's current sending limits and the host's outbound SMTP/TLS
connection must be confirmed before enabling it. This is a prepared option, not
evidence of a successful SMTP connection or delivery.

After confirmation, configure `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtps`, port 465,
the actual SMTP host, mailbox secret and authorized From address in the protected
host environment, then rebuild only that release's config cache. Keep certificate
verification enabled. Do not set the unused MAIL_ENCRYPTION variable or leave a
stale MAIL_URL overriding the settings. Check host-managed SPF/DKIM/DMARC or an
owned domain's records using the panel; do not alter DNS in this readiness step.

External verification remains pending: confirm the intended test recipient's
consent, send a reset and optional verification message only after authorization,
inspect inbox/spam placement and HTML/plain-text fallback, and exercise the real
HTTPS reset link once. Check expiry/tampering/reuse rejection, current-email
binding, role redirect and old-session invalidation. Record only sanitized outcomes,
never tokens/passwords or message bodies. Existing local SMTP-capture tests prove
serialization and application link handling, not external delivery or sender ownership.

## Health, backup and rollback

After separate deployment authorization, check `/up` and `/login` over verified
HTTPS, HTTP-to-HTTPS redirection, certificate hostname/chain, local font/CSS URLs,
Secure/HttpOnly/SameSite cookies and successful CSRF-protected login. Confirm the
app detects HTTPS through the actual host topology; if it does not, determine the
trusted proxy addresses with host support before configuring them. Test unauthorized
requests to `/.env`, `/vendor/autoload.php`, `/storage/logs/laravel.log` and private
file paths return denial/404 without contents. Check authorized private downloads
and another account's denial through the existing endpoints. The public health
route alone does not test database, storage, email or authorization.

For a consistent hosted backup, pause writes and wait for in-flight uploads/writers,
then use the matching MariaDB client without inline secrets:

```sh
umask 077
backup="$HOME/taskbridge-backup-REPLACE_UNIQUE_ID"
test ! -e "$backup" || exit 1
mkdir "$backup" || exit 1
mariadb-dump --host=mysql-REPLACE_ACCOUNT.alwaysdata.net --user=REPLACE_BACKUP_USER --password --single-transaction --skip-lock-tables --skip-add-locks --no-tablespaces --hex-blob --default-character-set=utf8mb4 --result-file="$backup/database.sql" REPLACE_ACCOUNT_taskbridge || exit 1
tar -czf "$backup/private.tar.gz" -C "$HOME/taskbridge/shared/storage/app" private || exit 1
cp "$HOME/taskbridge/shared/.env" "$backup/environment.env" || exit 1
cp "$HOME/taskbridge/current/RELEASE_COMMIT" "$backup/RELEASE_COMMIT" || exit 1
sha256sum "$backup/database.sql" "$backup/private.tar.gz" > "$backup/SHA256SUMS" || exit 1
```

Protect and transfer that matched set to encrypted off-host storage, then remove
only a verified disposable local backup according to the retention policy. Never
put it in `public`. Host three-day backups are additional convenience, not tested
application recovery. Restore only into a separately named disposable database/
storage with identity guards; compare schema/row hashes and file manifests, then
exercise login/history/private downloads before any recovery cutover. MariaDB dump
options differ from MySQL: do not copy `--set-gtid-purged=OFF` into this command.

Code rollback selects the previous schema-compatible release and rebuilds its
configuration for the same deployment key/database/storage. Never automatically
run `migrate:rollback`: destructive down migrations can erase immutable history.
An incompatible schema needs a reviewed forward fix or a separately authorized
restore/cutover. Maintain maintenance until verified; resume only the intended site.

## Inputs and deployment checks still needed

- Alwaysdata account name, confirmation of Free/non-profit eligibility and supplied
  HTTPS address; SSH/SFTP access via a private credential/key mechanism.
- Exact PHP patch, web-SAPI GD/WebP/zip/extensions, effective upload limits and
  account resource usage; actual proxy/scheme behavior.
- New MariaDB database/user, exact server version and permitted privileges, with
  credentials entered privately; final release path/site configuration.
- A new deployment key and separate strong Admin/Client/Freelancer credentials,
  confirmed deployment email identities and synthetic-data plan.
- Owned authorized sender/mailbox, SMTP host/login/secret and explicitly confirmed
  consenting test recipient. Neither local Gmail address is assumed owned.
- Protected off-host backup destination/retention and later permission for public
  deployment, external test email and deployed HTTPS/workflow verification.

Measured preparation evidence and outstanding compatibility results are recorded
in [the readiness verification record](free-hosting-verification.md).
