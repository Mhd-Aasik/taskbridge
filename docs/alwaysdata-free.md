# Alwaysdata Free university demonstration

Readiness work only, checked 25 October 2026. The owner subsequently reported the
existing account `taskbridge-aasik`; the continuation below records authenticated
host inspection and the authorized private disposable database/release probe.
Public application activation and external email remain pending. Do not upload the local
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

- Panel confirmation of Free/non-profit eligibility, remaining quota and the
  supplied HTTPS address for the reported account `taskbridge-aasik`; SSH/SFTP
  access via a private credential/key mechanism.
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

## Continuation for the existing taskbridge-aasik account

Registration is complete according to the owner. Do not register another account.
SSH identity and the database host below are now confirmed. Site paths and the
canonical application origin remain prepared deployment targets, pending site
configuration and real HTTPS verification:

| Setting | Prepared value |
| --- | --- |
| SSH host / port | `ssh-taskbridge-aasik.alwaysdata.net:22` |
| SSH user | `taskbridge-aasik` |
| Canonical origin | `https://taskbridge-aasik.alwaysdata.net` |
| Site root | `/home/taskbridge-aasik/taskbridge/current/public` |
| Versioned releases | `/home/taskbridge-aasik/taskbridge/releases/COMMIT` |
| Persistent environment | `/home/taskbridge-aasik/taskbridge/shared/.env` |
| Persistent private files | `/home/taskbridge-aasik/taskbridge/shared/storage/app/private` |
| Database host | `mysql-taskbridge-aasik.alwaysdata.net:3306` |
| Mail transport before approval | `MAIL_MAILER=array` |

The local dedicated Ed25519 key is outside Git at
`C:\Users\ab\.ssh\taskbridge-alwaysdata-20261025_ed25519`; only the `.pub` file
may be installed on the host. Its public fingerprint is
`SHA256:Uyq+C/9wEGQxuibb9wV6z7KvciVmJG/zAYBuCm/8DiE`.
The automation key has no passphrase; the private file has restricted explicit
Windows ACLs for the owner, Administrators and SYSTEM. The owner subsequently
installed the public key; fingerprint-verified SSH authentication has now succeeded.
No existing key or global SSH configuration was overwritten.
The separate client config `C:\Users\ab\.ssh\taskbridge-alwaysdata.config`
selects only this key, refuses unknown host keys, disables agent forwarding and
password fallback, and bounds connection attempts. Invoke it with
`ssh -F C:/Users/ab/.ssh/taskbridge-alwaysdata.config taskbridge-alwaysdata`.
The server ED25519 fingerprint was compared with the authenticated panel and
pinned in its dedicated known-hosts file:
`SHA256:5i/vJYokzNsnXAeHkwzEm+3kxPQWwsRzwXFPQ7oOvNI`.
This is the **server** fingerprint; the different fingerprint above identifies
the local **client** key. The normal strict client configuration connects successfully.

Sign in privately to the existing [administration panel](https://admin.alwaysdata.com).
Confirm the selected account and its Free plan, quota usage/remaining capacity,
SSH user/host and server fingerprints under Remote access > SSH/SFTP.
[Alwaysdata documents that fingerprint location](https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/).
Compare the panel's SHA256 fingerprint against the SSH server key before trusting
it; a network `ssh-keyscan` result alone is not verification. Install only the
dedicated public key for the confirmed SSH user. Never paste a password, OTP,
private key or database/SMTP secret into documentation or chat.

For the one private access handoff: sign in, select `taskbridge-aasik`, open
Remote Access > SSH/SFTP > **SSH Keys** (key icon) for the confirmed user, select
**Add a SSH Key**, and paste the complete single line from the local `.pub` file.
Save that public key only. These are the host's
[documented public-key installation steps](https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/use-keys/).
Leave the panel signed in so its account/quota and server fingerprint can be
verified before any SSH authentication or resource creation.

After access and quota are confirmed, copy the curated release into a unique
**non-public probe directory**, without a configured public site. Run
`php deployment/alwaysdata/inspect-runtime.php` there. This read-only script
reports native extensions, CLI limits and directory permissions without booting
the app or reading its environment. Also inspect the account's selected web PHP
version/ini settings in the panel; CLI observations do not establish web limits.
Use the host's CLI PHP selection for subsequent commands and compare it with the
web version. No web diagnostic route or phpinfo page should be published.

Only then create a uniquely named database/user limited to this disposable probe,
using the exact name permitted by the panel, such as
`taskbridge-aasik_tbprobe_20261025_a1b2c3`. The required naming pattern ends in
`_tbprobe_YYYYMMDD_HEX6`. This example is not an allocated database. Store its
credentials and an ephemeral probe encryption key privately in the isolated
directory, with `APP_ENV=production`, `APP_DEBUG=false`, the expected HTTPS origin,
`DB_CONNECTION=mysql`, array mail/cache/sessions and sync queue. Do not reuse the
local or future production database or encryption key. Remove database URL
overrides and stale config caches. Set `umask 077`, create
`.taskbridge-disposable` containing **only that exact database name**, then run:

```sh
# Replace the example with the exact new, empty database confirmed in the panel.
timeout 90s php deployment/alwaysdata/mariadb-compatibility.php --disposable=taskbridge-aasik_tbprobe_20261025_a1b2c3
```

The probe refuses a missing/mismatched marker, wrong database, non-MariaDB engine,
unsupported server family or nonempty database before running migrations. It
never creates/drops a database or calls migrate:fresh/rollback. It runs the
checked-in migrations, checks InnoDB, enforces actual rating CHECK/FK constraints,
exercises the mysql driver's duplicate classification, savepoint rollback,
bounded GET_LOCK and two-connection FOR UPDATE exclusion, and uses the real
proposal submission/acceptance actions with idempotent notification/activity
checks. Synthetic workflow records roll back; schema remains for inspection.
One synthetic category is briefly committed to test row locking and then removed
on success. A failure may leave probe-only schema/records for review. The outer
90-second deadline, eight-second statement limit, two-second row-lock wait and
one-second advisory wait bound shared-host work. Do not repeat it in the same
nonempty database, run the heavy test suite or point the MySQL-specific
performance_schema harness at MariaDB. Delete only this identified disposable
database/user/directory after reviewing the outcome and confirming identities.

This script has passed syntax/format and refusal checks locally. **The guarded
host qualification now passes on MariaDB 11.8.9**, after the owner created the
scoped SSL-required user. The first authentication failure is historical; the
successful run and final read-only inspection are recorded below. The script
does not establish broad workflow/concurrency compatibility by itself.

For the eventual production database, set `SESSION_DRIVER=database` and keep
`MAIL_MAILER=array` until mail approval. The read-only database preflight now
supports `php deployment/alwaysdata/preflight.php --database=EXACT_DATABASE --mail-disabled`:
it still checks the database/session configuration and explicitly requires array
mail. Omit `--mail-disabled` only after the sender, secret and test recipient have
been approved and SMTP is configured. Neither mode sends a message.

Public site creation/activation, Force HTTPS changes, production database/account
creation, new production APP_KEY and strong deployed credentials remain pending
compatibility and configuration verification. Preserve the same persistent key
and private storage on subsequent updates. The backup/rollback procedures above
apply to these concrete paths; confirm the protected off-host destination before
cutover. No actual external email or public HTTPS workflow has been verified.

## Authenticated host checkpoint — 25 October 2026

The existing account is confirmed as **Free, EUR 0, 1 GB disk, 256 MB RAM,
0.25 CPU**. The panel's consumption graphs returned N/A and `quota -s` supplied
no numeric report. Before a release transfer, SSH measured **48 KiB** under the
account home (including account files/mail, excluding the off-host backup mount),
and the database list was empty. This establishes a large margin for the proposed
roughly 60 MiB probe release; it is not a provider-generated remaining-quota figure.

The panel selects PHP **8.4** with an empty custom ini; SSH runs **8.4.27** using
`/home/taskbridge-aasik/admin/config/php/php.ini`. All 19 extensions checked by the
CLI preflight, including GD/JPEG/PNG/WebP, PDO MySQL and zip, are native and present.
Actual CLI settings are `memory_limit=256M`, upload/post limits `256M`, execution
limit `0` and input limit `-1`. Home mode is `1770`, admin `0755`, admin/tmp `0770`.
The conservative deployment ini remains prepared, not applied. Web-SAPI limits,
worker memory/concurrency and external proxy/cookie behavior remain separate
deployment checks; CLI findings must not be substituted for them.

The panel reports MariaDB **11.8**; the database protocol handshake reports
**11.8.9-MariaDB**. A credential-free OpenSSL MySQL STARTTLS probe verified the
certificate chain and `mysql-taskbridge-aasik.alwaysdata.net` hostname with system
trust roots: TLS 1.3 / TLS_AES_256_GCM_SHA384, verification OK. No TLS protection
was disabled. The subsequent authenticated SQL connection also confirmed
**TLS 1.3 / TLS_AES_256_GCM_SHA384** with PDO server-certificate verification
explicitly enabled and the system CA bundle.

Created only the new empty database
`taskbridge-aasik_tbprobe_20261025_b4e912` (panel object 499030), annotated as
disposable qualification. It initially granted no user rights. The owner has now
created `taskbridge-aasik_tbp_b4e912`; actual authentication and server-reported
grants confirm rights only on this exact database and server-enforced SSL.
Underscores in the database grant are escaped, so they are not wildcard grants.
Alwaysdata's all-rights setting includes a schema-scoped grant option; there are
no global privileges beyond USAGE. Raw grant authentication clauses/hashes were
not displayed. The protected
outside-Git environment `C:\Users\ab\.ssh\taskbridge-probe-20261025-b4e912.env`
contains a new temporary key and the owner's privately saved probe password.
It uses the system CA bundle and array mail. Do not copy the running local `.env`.

The panel also contains the existing mailbox `taskbridge-aasik@alwaysdata.net`
and lists `smtp-taskbridge-aasik.alwaysdata.net`. This provides a concrete sender
candidate without buying a domain. Sender authorization, private mailbox/SMTP
credentials and the intended consenting recipient must be confirmed before mail
is enabled. No mailbox settings or external mail have been changed.

The initial release-transfer request was stopped by automatic approval review.
The owner then explicitly approved the exact archive/probe-environment payload
and destination. The **9,524,926-byte** archive and new private probe environment
were transferred only to
`/home/taskbridge-aasik/taskbridge-probes/20261025-b4e912`; SHA256 matched before
extraction. The running local environment, database and uploads were excluded.
The directory and writable application directories are `0700`; its `.env` is
`0600`. Managed Apache configuration confirms the existing web document root is
`/home/taskbridge-aasik/www/`, so the probe is outside it. No site was activated
or changed.

Actual host `composer check-platform-reqs --no-dev` passed, with plugins/scripts
disabled and no dependency installation or audit. The prepared CLI preflight
passed **offline**, using a 128M PHP limit and a 30-second external deadline:
**1.63 seconds / 56,032 KiB peak RSS (54.7 MiB)**. It verifies production/debug
settings, native extensions, private writable storage, secure-cookie configuration,
sync queue and matching built assets. Its database/session/SMTP checks explicitly
remain skipped. The release reports Livewire **4.4.8**. The whole probe directory
uses **70,560 KiB (68.9 MiB)**, including the compressed archive and private
configuration; this is filesystem usage, not the unavailable provider quota counter.

The password placeholder is confirmed gone without displaying its value. The
updated probe environment was transferred only into this marked directory with
mode `0600`; private local/remote SHA256 comparison matched, with neither contents
nor digest values displayed.

The initial guarded SQL attempt used the existing 90-second deadline and a 128M PHP
limit. It passed the mysql-driver check but exited **1** on the initial database
identity query: **SQLSTATE HY000, driver code 1045**. It took **3.91 seconds /
60,384 KiB peak RSS (59.0 MiB)**. No migrations or synthetic workflow writes ran.
At that checkpoint the scoped user was absent. This authentication/setup gate is
now resolved; it was not a migration or MariaDB incompatibility.

Before migrations, read-only checks confirmed the exact configured/authenticated
user and database, TCP host/port with no URL/socket override, readable CA bundle,
active certificate-verified TLS, SSL-required user, grants only on the exact
escaped disposable database, and zero tables. The first read-only grant guard
also rejected the schema-scoped grant option unnecessarily; sanitized metadata
confirmed it did not widen database scope, and that extra restriction was removed.

The existing one-shot MariaDB script then passed **all checks, exit 0**, under
the same 90-second deadline and 128M PHP limit: **8.21 seconds / 74,696 KiB peak
RSS (72.9 MiB)**. All **27 migrations / 33 InnoDB tables** applied. Actual CHECK
and FK constraints, duplicate classification, savepoint/outer rollback, advisory
and two-connection row locking, proposal submission/acceptance and idempotent
notifications/activity passed.

The full read-only `preflight.php --database=taskbridge-aasik_tbprobe_20261025_b4e912
--mail-disabled` then passed **exit 0**, **2.15 seconds / 61,652 KiB peak RSS
(60.2 MiB)**. Final inspection used a read-only transaction and confirmed **zero
rows across all non-migration tables**; only the 27 migration records remain.
Schema, scoped user and private probe directory are retained for inspection. Do
not rerun the one-shot probe against this now-populated schema.

The private release footprint remains **70,560 KiB (68.9 MiB)**. MariaDB reports
**1,785,856 bytes (1.70 MiB)** for table data plus indexes; this excludes provider
logs/overhead and is not an exact remaining-account quota counter. Directory and
environment modes remain `0700` / `0600`. Evidence:
`storage/logs/hosting-qualification-mariadb-qualified.*`,
`hosting-qualification-database-preflight.*`, `hosting-qualification-grant-metadata.txt`
and `hosting-qualification-environment-transfer.json`.

**The bounded MariaDB qualification and database CLI preflight are complete.
Web-SAPI behavior, public HTTPS activation, deployed accounts/private-endpoint
checks, production backup/restore and external email remain pending.** No heavy
suite, public site change, SMTP login or message send was performed.
