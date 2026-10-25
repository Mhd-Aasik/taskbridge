# Free-hosting readiness evidence — 25 October 2026

Starting checkout: clean `main` at **cb0350e**, tracking `origin/main`, with the
configured remote `https://github.com/Mhd-Aasik/taskbridge.git`. Work continues on
`chore/free-hosting-readiness`; no repository/ancestor AGENTS.md was found. No push,
public deployment, remote infrastructure change, purchase or external email was
performed. The local environment, dependencies, accounts and durable uploads are
preserved; the proposed lockfile patch is installed only in isolated verification.
This opening describes the original preparation. The continuation at the end
records the later account/access work and authorized GitHub publication separately.

## Dependency audits and minimal correction

The new metadata-only npm audit against the explicitly authorized official
registry succeeded: **exit 0, zero vulnerabilities at every reported severity**.
Its bounded configuration allowed one retry, a 15-second request timeout and
one-to-two-second retry delays. Ignored evidence: `storage/logs/hosting-npm-audit.*`.
This supersedes the earlier unavailable npm report at **cb0350e**.

Composer's first authorized official-advisory audit succeeded as a retrieval but
exited **1** with one medium finding: **GHSA-mhf7-xp6r-6qrc /
PKSA-xhv3-nr94-h8db**, `livewire/livewire` 4.4.7, affected >=4.3.0 and <4.4.8.
The [maintainer advisory](https://github.com/livewire/livewire/security/advisories/GHSA-mhf7-xp6r-6qrc)
describes reordered action/listener arguments or JSON execution order bypassing
`#[Authorize]` checks. Repository application sources do not use that attribute;
their authorization is performed inside actions. This source inspection narrows
known exposure but is not a substitute for patching the dependency.

An isolated `composer update livewire/livewire:4.4.8 --with-dependencies
--minimal-changes --no-install --no-scripts --no-interaction` changed **only**
Livewire, from 4.4.7 to **4.4.8**. No Composer constraint or npm lockfile changed.
A new `composer audit --locked --format=json` against the patched lock succeeded:
**exit 0, empty advisories/abandoned/filter arrays**. Composer is now verified for
this lockfile, superseding its earlier incomplete status. No TLS bypass, ignored
advisory or forced audit fix was used. Only dependency metadata was sent as audit
payload; no environment, credentials, source, records or uploads were submitted.

## Release and compatibility verification

The first isolated source snapshot came from cb0350e, without the real `.env`,
uploads, database or cached secrets. Locked development dependencies installed
successfully with patched Livewire. The complete SQLite suite passed **2,349 tests /
14,348 assertions**, no failures/errors/skips, exit 0, in 15:11.214. JUnit and text
agree. This run precedes the later photo-memory correction; it is not claimed to
include that correction's new test.

A genuine synthetic-image probe under `memory_limit=128M` reproduced a fatal GD
allocation in the existing profile-photo decoder for a 58,790-byte 4096-square PNG.
Fixture generation was performed separately with a larger CLI allowance, so that
failure belongs to application processing. An earlier combined fixture/probe had
failed while generating its input and was discarded as application evidence.
AccountPhotos now checks a conservative decoded/encoder-buffer budget before GD,
using the existing host memory limit rather than changing it. The targeted suite
then passed **43 tests / 335 assertions**, exit 0, no failures/errors/skips, including
the new test proving saved photo/file preservation and unchanged PHP memory settings
when memory is unavailable. Earlier full-suite evidence is reused for unchanged
domains; the focused suite and actual probe cover this subsequent change.

After correction, synthetic 1600 × 1000 cover/photo inputs both processed under
128M with a measured PHP-managed peak of **44,040,192 bytes (42 MiB)**. The same
4096-square cover/photo inputs returned validation rejection, peaking at
31,457,280 / 29,360,128 bytes respectively. These are local PHP allocation readings,
not Linux RSS, host worker totals, high-entropy-image benchmarks or concurrency
capacity. The proposed 128M worker limit leaves some account headroom but does not
prove two simultaneous uploads fit the Free plan. The suite's 188 MiB peak is
test-runner memory and is not presented as runtime memory. Actual host worker,
Apache/OPcache and concurrent-request measurements remain pending.

The frontend build completed after Composer made Flux CSS available and reproduces
`app-CgO014P4.css` plus the bundled Instrument Sans file. The initial overlapping
frontend attempt ran before that dependency extraction completed and failed to
resolve Flux CSS; it was a harness sequencing issue. The reusable builder runs
Composer before Vite. Its first production run also exposed Windows empty-valued
cache environment variables being interpreted as a directory; the builder now
removes inherited overrides explicitly and sets isolated storage/cache paths.
Windows drive-letter cache overrides also need Laravel-compatible relative paths;
production config/route cache generation passed with those paths. This is a local
verification/builder correction, not a claim of a host-side application defect.

### Production release footprint and checks

The committed builder completed at **fa19944** with `composer install --no-dev`,
locked frontend installation and Vite build. Production metadata contains **79
packages**, `dev=false`, with none of the 35 development-only lockfile packages
installed. The curated runtime has no `.env`, Git history, tests, node_modules,
hot file or public storage link. The final reviewed CLI reporting/cache-path
adjustments were synchronized into the local inspection artifact afterward;
application code, patched lockfile and built assets are unchanged and hash-matched.
Future deployment should rebuild the final reviewed commit rather than uploading
any work/cache directory.

| Local measurement | Actual result |
| --- | --- |
| Curated runtime files | **7,762** |
| Logical file bytes after final tool updates | **41,318,990 bytes**, about **39.4 MiB** |
| Approximate 4 KiB per-file block allocation | **61,927,424 bytes**, about **59.1 MiB**; excludes directory/inode overhead and is not the host's measured quota use |
| Two-release allowance at that file-block estimate | About **118.1 MiB**, within the proposed 250 MB release budget |
| Production config/route/Blade caches | Passed using an ephemeral key, isolated external runtime paths and no database connection |
| Internal production requests | `/up`, `/`, `/login`: **200**, TaskBridge homepage content checked |
| Internal HTTPS session cookie | Secure, HttpOnly, SameSite=Lax confirmed; real proxy/browser TLS remains pending |
| Production PHP-managed peak for internal requests | **26 MiB** initial pass, **24 MiB** with warmed views; not worker RSS or concurrent-host capacity |
| Offline CLI preflight | Passed; live database/session backend and SMTP explicitly reported **SKIP**, not verified |
| Production demo setup | Refused before connection to the deliberately unavailable disposable database; production guard remains intact |
| Builder safety guard | Existing destination refused without overwrite; PowerShell parser passed |

The proposed disk budget reserves separate space for database growth, private
files, upload staging, logs/mail/account files and headroom. Host quota is shared;
the 1 GB allocation is not all available to application uploads. Actual host RAM,
worker count, CPU behavior and platform filesystem allocation still need checking.
No Node build, Composer update or regression suite should be run on the constrained
host during the demonstration. The ready-to-inspect runtime remains ignored at
`storage/framework/alwaysdata-release-final-20261025/release`; it contains no real
installation secrets or user data.

Fresh JavaScript checks passed **13 tests**. Repository Pint, changed PHP syntax,
PowerShell parsing, documentation local-link paths and whitespace passed. Root
`.env`, managed demo manifest and durable-file SHA-256 values remained unchanged.
The original local vendor/build installation was not upgraded in place; the
checked-in lock and prepared release use patched Livewire. Unchanged historical
browser evidence was reused; no new public/browser deployment check is claimed.
The application and its recovery backup remain intact. Sanitized text/JUnit/exit,
audit, memory and footprint evidence remain ignored, outside commits.
After verification, inactive source/dependency build trees, synthetic image
fixtures/helpers and the isolated production caches containing the ephemeral test
key were removed with resolved-path/reparse-point checks. The curated release,
sanitized reports and original protected recovery backup are retained deliberately.

Final read-only probes of the original local development server did **not** return
an HTTP response: the bounded requests ended with HTTP 000, and the final curl
attempt exited 28 after 20.016 seconds with zero received bytes. The same original
PHP listener remains on `127.0.0.1:8000` (PID 12156, supervised by PID 13884). It was
not restarted or reconfigured; no cause is inferred from the timeout. This local
service observation is separate from the passing isolated production `/up` and
login requests. Local interactive-service health is therefore not marked passed.

### MariaDB status

Official host documentation lists MariaDB 10.11/11.4/11.8 and new-account 11.8.
The official Windows **11.8.9** portable archive was downloaded and its SHA-256
matched the published checksum:
`830c46727d9278eae212ae3eca44eeb9e71b2a68704e95f344a64fba7b1963f5`.
Two new isolated datadir initialization attempts stalled in bootstrap before system
tables completed. They were stopped by their inspected PID/executable/arguments;
no database was migrated or populated on that engine. Only the inspected disposable
binaries/archive/datadirs were removed after confirming no matching process/listener
remained. The application's MySQL service was preserved. Evidence:
`storage/logs/hosting-mariadb-unavailable.txt` and ignored bootstrap logs.

This is **unavailable MariaDB verification**, not an application incompatibility
finding and not a MariaDB pass. Source inspection of CHECK/foreign keys, transactional
row locks, advisory locking and mysql duplicate-error handling is recorded in the
runbook. Genuine migrations/constraint enforcement/workflow/race qualification on
a new disposable database in the actual MariaDB host family remains required. The
existing MySQL harness's engine-specific lock observations and SQLite tests cannot
substitute for it.

### Remaining host and email gates

No external message, SMTP login, account/DNS change or public deployment occurred.
The environment template defaults to array mail until sender/recipient confirmation.
Actual provider TLS/authentication, inbox/spam placement and real HTTPS reset-link
verification remain pending. No local Gmail ownership is assumed. The supplied
HTTPS address, proxy/cookie/CSRF behavior, host upload limits, real resource usage
and protected off-host backup/restoration remain unverified. Exact account inputs,
operating commands and the proposed storage budget are in the
[Alwaysdata runbook](alwaysdata-free.md).

## Continuation — existing account and authorized publication

Recovered clean `chore/free-hosting-readiness` at **59f04aa**, preserving
**a954d3f**, **fa19944** and **59f04aa**. The owner reported the existing account
**taskbridge-aasik** and authorized bounded host checks in a uniquely identified
disposable database, plus normal GitHub publication. A successful fetch found
`origin/main` still at **cb0350e**, an ancestor of the readiness branch.
Registration, successful unchanged audits, dependency installs and the heavy suite
were not repeated. Actual saved audit results remain **npm exit 0 / zero
vulnerabilities** and **Composer locked audit exit 0 / no advisories**.

No authenticated panel session was available. The login page was observed, then
browser recovery timed out. Expected SSH details, Free plan, remaining quota,
actual PHP/web limits, MariaDB version and server fingerprint are therefore
**not panel-verified**. No SSH authentication, remote database/user creation,
host upload, site change, public activation or real email was performed. Published
plan documentation is a planning input, not an observation of this account.

A dedicated Ed25519 key was created outside Git without overwriting another key;
its private contents were never displayed. Explicit Windows ACLs restrict it to
the owner, Administrators and SYSTEM. The separate strict SSH client configuration
passed `ssh -G`, selects only this key, refuses unknown host keys and disables
agent forwarding/password fallback. Public-key installation and independent
panel-to-server fingerprint comparison remain pending; no host key was trusted
by TOFU. Paths, public-key fingerprint and the concrete account deployment plan
are in [the account continuation](alwaysdata-free.md#continuation-for-the-existing-taskbridge-aasik-account).

### Local timeout and dependency comparison

Current inspection found **local vendor Livewire 4.4.7**, **composer.lock 4.4.8**
and **isolated release vendor 4.4.8**. The locked audit passes and verified release
use the patched version; the preserved local vendor is still mismatched. No local
dependency installation or configuration was changed to conceal this difference.

Two bounded probes in the user's network context established TCP connections to
the existing server, but **both `/up` and `/favicon.ico` returned HTTP 000, zero
bytes, curl exit 28 after eight seconds**. The original PHP server processes were
observed and preserved. A prior log records a 60-second fatal timeout in Composer's
class loader; that location does not establish the cause. No execution limit was
increased.

A disposable read-only CLI diagnostic used array session/cache/mail, separate
cache paths, a **15-second PHP limit** and **25-second process deadline**, without
a database operation or listener. In the user's context:

| Install | Console boot | Internal /up | Managed PHP peak |
| --- | --- | --- | --- |
| Preserved local vendor | 10.389 s | 200 at 12.433 s | 27,262,976 bytes / 26 MiB |
| Isolated production release | 5.922 s | 200 at 6.500 s | 25,165,824 bytes / 24 MiB |

Earlier restricted-context attempts exceeded diagnostic bounds; they are not host
or application incompatibility findings. Both dependency sets can complete CLI
boot. The existing HTTP-server timeout remains unresolved; neither its listening
port nor the Livewire mismatch is asserted as its cause. Ignored evidence:
`storage/logs/hosting-resume-{health-user,static-user,root-user-boot,release-user-boot}*`.

### Prepared checks, preservation and remaining gates

Added CLI-only `inspect-runtime.php` and guarded `mariadb-compatibility.php` under
`deployment/alwaysdata/`. The former reports extensions/limits/permissions without
reading environment secrets. The latter requires an exact disposable name,
matching private marker, selected-database identity, actual supported MariaDB and
initially empty database before migrations. It checks InnoDB, real rating CHECK/FK
constraints, 23000/1062 unique classification, savepoints, advisory locks,
two-connection row-lock exclusion and real synthetic proposal actions with
idempotent notification/activity writes. Schema remains for inspection; it never
creates/drops databases or runs the heavy suite. The runbook bounds execution at
90 seconds, statements at eight seconds and row locks at two seconds. **Actual
MariaDB execution is pending; these are prepared checks, not compatibility passes.**

The database preflight now supports `--mail-disabled` with `--database=...`, retains
database-session/schema checks and explicitly requires array mail. The isolated
offline check passed (exit 0). Full-mode checks against a deliberately unavailable
loopback database exited 1 as intended: array mail passed its mail gate and SMTP
failed that gate. These are negative checks, not successful database preflights;
no email was sent. See ignored `hosting-resume-preflight-*.txt` and
`hosting-resume-preflight-modes.json` under `storage/logs`.

All three CLI scripts passed PHP syntax; the deployment directory passed Pint and
Git whitespace checks passed. The mutating probe refused both an unsafe target
and a valid-looking name without its marker (exit 2 before application/database
access). Existing full-suite and photo-memory results above were reused.

All **346 compared tracked runtime/source files** hash-match the retained release.
The application/dependency/asset build remains the verified **fa19944** base with
reviewed deployment-tool overlays. `RELEASE_COMMIT` still identifies that base;
the artifact is not relabeled as a new archive. Updated curated footprint:
**7,764 files / 41,330,326 logical bytes / approximately 61,943,808 bytes in 4 KiB
file blocks** (39.4 / 59.1 MiB). It contains no environment, Git metadata,
node_modules, tests, hot file, public-storage link, secret config cache or probe
marker. Build the final reviewed revision with the existing builder before any
later production transfer; these tool/doc changes do not require another audit.

All **17 protected files**, including the local environment, demo manifest and
durable private uploads, still match their saved hashes. No local database,
account or demo-password change was performed. Pattern scanning and review of the
readiness changes found no credentials or forbidden tracked runtime data; five
matches were reviewed placeholder examples. Evidence remains in ignored
`storage/logs/hosting-resume-secret-review.json`. Outside-Git SSH files and runtime
diagnostic evidence are excluded from publication.

Still needed: authenticated panel access, public-key installation and verified
server fingerprint; actual Free/quota/runtime confirmation and scoped disposable
database credentials; then production database/configuration, a new privately
generated deployment key, separate strong deployed accounts and protected off-host
backup destination. Mail additionally needs an owned authorized sender, private
SMTP credentials and an approved recipient. **Public deployment, external TLS,
cookie/private-download workflows and external delivery remain incomplete.**

### GitHub publication result

After the checks and secret review, **addaa4a** committed the guarded host tooling
and updated readiness records. Remote ancestry was refreshed and both remote
and local `main` were ancestors. Local `main` was advanced without changing the
working checkout. A **normal atomic push succeeded** to
`https://github.com/Mhd-Aasik/taskbridge.git`: `main` advanced from **cb0350e** to
**addaa4a**, and `chore/free-hosting-readiness` was created at **addaa4a**. A
subsequent `ls-remote` confirmed both refs at
`addaa4af57567804dab472ccb96556fb820660d0`. No force-push or remote-history overwrite
was used. This publication includes the three earlier readiness commits. This
documentation follow-up records that observed result; host/mail completion gates
are unchanged.

## Authenticated host and local-runtime follow-up — 25 October 2026

Continued clean `chore/free-hosting-readiness` from **6572116**, without repeating
the successful unchanged npm/Composer audits, release build or heavy suite.

The owner installed the dedicated public key. The authenticated Alwaysdata panel
confirmed account/user `taskbridge-aasik`, SSH host
`ssh-taskbridge-aasik.alwaysdata.net` and server ED25519 fingerprint
`SHA256:5i/vJYokzNsnXAeHkwzEm+3kxPQWwsRzwXFPQ7oOvNI`. The network handshake
matched exactly before key authentication, and the key was pinned in the separate
known-hosts file. SSH authentication and subsequent normal strict-config commands
succeeded. Windows `ssh-keyscan` failed on its unsupported hybrid KEX method;
the standard SSH client completed a secure curve25519 handshake instead. No host
key/certificate verification was disabled and no unverified key was accepted.

The panel confirms **Free / EUR 0 / 1 GB disk / 256 MB RAM / 0.25 CPU**. Usage
graphs displayed N/A; the host quota command returned no numeric remaining-quota
report. Before resource preparation, the home tree measured **48 KiB** and the
database list was empty, leaving a large measured margin within the confirmed
plan capacity. This is an accounting estimate, not a live provider quota counter.

Actual native CLI runtime: **PHP 8.4.27**, all 19 required extensions present,
GD JPEG/PNG/WebP supported, memory **256M**, upload/post **256M**, execution limit
**0**, input limit **-1**. The loaded ini is the account's managed PHP ini; the
panel selects the 8.4 family and has no custom overrides. Home/admin/admin-tmp
permissions are **1770 / 0755 / 0770**. Web-SAPI effective limits, worker RSS,
concurrency and external HTTPS/proxy behavior remain unverified deployment checks.
No account PHP setting was changed. Evidence is in ignored
`storage/logs/hosting-qualification-runtime-{initial,complete}.txt`.

The panel reports MariaDB **11.8**; an unauthenticated protocol handshake obtained
the actual server string **11.8.9-MariaDB**. This establishes server identity/version,
not successful SQL qualification. A credential-free MySQL STARTTLS check verified
the server certificate chain and hostname with system roots: **TLS 1.3,
TLS_AES_256_GCM_SHA384, verification OK**, exit 0. Evidence:
`storage/logs/hosting-qualification-database-tls.txt`.

Created only the uniquely identified empty database
**taskbridge-aasik_tbprobe_20261025_b4e912** (panel object 499030), annotation
`Disposable TaskBridge qualification 20261025-b4e912. No production data.` It has
zero assigned users initially. A new SSL-required user form is prepared solely
for this database; private password entry/submission remains the owner's step.
No unrelated database/user/site was modified and no paid service was activated.
The outside-Git probe environment has a fresh temporary encryption key, a password
placeholder and restricted Windows ACLs. Its contents were not displayed.

### Local dependency alignment and HTTP recovery

Ran **composer install --prefer-dist --no-interaction --no-progress** from the
committed lockfile, including local dev dependencies. Actual result: **exit 0;
zero installs, one update, zero removals**, Livewire **4.4.7 -> 4.4.8**, followed
by successful optimized autoload/package discovery. The lockfile hash did not
change. Local installed metadata, composer.lock and the retained release now all
report **4.4.8**. No unrestricted update or new audit was run. Evidence:
`storage/logs/hosting-qualification-composer-install.*`.

Focused authentication/profile/photo tests passed **52 tests / 360 assertions**,
exit 0, in 268.47 seconds, using the repository's isolated SQLite test configuration
and array mail/session/cache. These do not substitute for MariaDB checks. Evidence:
`storage/logs/hosting-qualification-local-regression.*`.

Before restarting, the original listener established TCP but returned zero bytes
for `/up` and `/favicon.ico`: HTTP 000 / curl exit 28 after eight seconds each.
Existing server diagnostics show several historical 60-second timeouts at different
autoload locations. A short-lived comparison server with the same application
returned `/up` **200 in 1.650036 s** and favicon **200 in 0.006809 s**. That new
diagnostic process was then stopped by its exact PID.

Verified the original chain: artisan supervisor **13884**, command wrapper **8232**,
and listener **12156**, with the child command pointing to this TaskBridge router.
Only those identified processes were stopped; the user's parent terminal and
unrelated processes were preserved. After confirming the port was free, started
a hidden replacement bound only to **127.0.0.1:8000**, with separate protected log
files. Replacement supervisor/wrapper/listener are **20212 / 10828 / 9200**.
Actual post-start checks: `/up` **200 in 0.884635 s**, favicon **200 in 0.004209 s**,
curl exit 0 for both. No execution limit was raised. The original low-level stall
cause remains unproven; Livewire is not asserted as its cause. See ignored
`hosting-qualification-{server-comparison,restart-complete,local-server-processes}.txt/json`
and local server logs under `storage/logs`.

All **17 protected files** still match their saved hashes after install, tests and
restart, including `.env`, demo manifest and durable private files. No local
database migration, account/password modification or private-data transfer occurred.
The original local application key remains unchanged.

### Qualification transfer gate

Prepared a **9,524,926-byte** archive of the retained verified release, excluding
environment files, local database/private uploads, Git metadata, node_modules,
tests, secret config caches and public storage links. Automatic approval review
rejected its transfer because explicit approval for this application/dependency
payload and destination was not established. The action was not bypassed; no
release archive or probe environment was sent. The owner has been asked to approve
the exact transfer to the private probe directory and privately complete the scoped
database login. The guarded MariaDB migrations/constraints/transactions/workflow
probe has **not run** and remains incomplete, as does application host preflight.

The panel exposes an existing `taskbridge-aasik@alwaysdata.net` mailbox and SMTP
host, providing an owned-account sender candidate. Credentials, sender approval,
test recipient and real delivery remain pending. No SMTP login or message send,
public TaskBridge activation or HTTPS application verification was performed.

## Approved private transfer and host application preflight

The owner explicitly approved transferring the named application/dependency
archive and the new private probe environment to the isolated Alwaysdata directory.
The approved action then succeeded; the earlier automatic-review transfer gate
is resolved. Only the prepared release and its newly generated temporary probe
environment were sent. No running local `.env`, database records, accounts,
private uploads or recovery backup were transferred.

The **9,524,926-byte** archive's remote SHA256 matched the local SHA256 before
extraction. Exact target:
`/home/taskbridge-aasik/taskbridge-probes/20261025-b4e912`. Its identity marker
matches `taskbridge-aasik_tbprobe_20261025_b4e912`. Directory mode is **0700**,
including private storage and writable caches; the probe `.env` is **0600**.
Read-only inspection of managed Apache configuration confirmed the existing
document root is **/home/taskbridge-aasik/www/**. The probe is outside that web
root, and no site configuration or public activation changed. Evidence:
`storage/logs/hosting-qualification-transfer.txt`,
`hosting-qualification-archive.sha256` and `hosting-qualification-web-root.txt`.

On the actual PHP **8.4.27** host, the release runtime inspector confirmed all
native extensions and writable private/cache directories. Production platform
requirements passed with **composer --no-interaction --no-plugins --no-scripts
check-platform-reqs --no-dev**. This performed no install, update or repeated audit.
Installed release Livewire reports **4.4.8**.

Ran **timeout 30s php -d memory_limit=128M
deployment/alwaysdata/preflight.php --offline** under `/usr/bin/time`. Actual
result: **exit 0**, every applicable check passed, **1.63 seconds / 56,032 KiB
peak RSS (54.7 MiB)**. It verified production/debug settings, configured HTTPS
origin, the new temporary key, secure-cookie settings, mysql driver, sync queue,
19 native extensions, GD formats, private writable storage, nonempty compiled
assets and absence of a public storage link/hot file. The origin/cookie checks
inspect configuration, not a public HTTPS request. Database, database sessions
and SMTP configuration were explicitly skipped. Actual footprint of the probe
directory is **70,560 KiB / 68.9 MiB**, including the compressed archive and probe
environment. This is measured filesystem usage, not a provider quota counter or
a concurrency/load test. Evidence: `storage/logs/hosting-qualification-host-preflight.*`.

The scoped database password is still a placeholder, and the owner has been given
the precise private-entry step. No invalid-password connection was attempted and
the mutating MariaDB probe has **not run**. Its empty-database/identity guards,
SQL migration/constraint/transaction/locking/workflow checks and the subsequent
full read-only `--mail-disabled` database preflight remain incomplete. No heavy
suite, public application activation, SMTP login or external message was run.

## Private credential transfer and first SQL attempt — 25 October 2026

This supersedes the preceding password-placeholder/not-attempted checkpoint.
After the owner's saved-file reply, verified privately that the active password
is nonempty and no longer the placeholder. Checked the exact disposable database,
scoped user, array-mail setting and system CA path without displaying the password
or environment contents. Transferred only that newly prepared probe environment
to the approved marked directory, using verified/pinned SSH and an atomic rename;
mode **0600** was checked. Local/remote SHA256 comparison matched and emitted only
a boolean, not the digests. Evidence:
`storage/logs/hosting-qualification-environment-transfer.json`.

Ran the existing guarded command:

```sh
timeout 90s php -d memory_limit=128M deployment/alwaysdata/mariadb-compatibility.php --disposable=taskbridge-aasik_tbprobe_20261025_b4e912
```

Actual result: **exit 1**, mysql-driver check passed, then the initial database
identity query failed with **SQLSTATE HY000 / driver code 1045**. `/usr/bin/time`
reported **3.91 seconds / 60,384 KiB peak RSS (59.0 MiB)**. Exception messages,
SQL bindings, passwords and DSNs were not printed. Failure occurred before the
emptiness check, migrations or synthetic writes, so no schema/workflow/constraint
compatibility result can be claimed. This is an authentication/setup failure,
not evidence that migrations are incompatible. Evidence:
`storage/logs/hosting-qualification-mariadb.txt` and `.exit.txt`.

Authenticated panel inspection showed only the default database user
`taskbridge-aasik` and zero assigned databases. The required scoped user
`taskbridge-aasik_tbp_b4e912` was absent. After the owner requested form help,
brought the prepared Add user form into view with its exact name, SSL required
and all rights on only `taskbridge-aasik_tbprobe_20261025_b4e912`. The password
field was left empty for private entry/submission. A subsequent creation reply
was checked against a refreshed users list; the scoped user was still absent.
No further login retry, unrelated user modification or permission expansion was
performed. Private creation of that exact user with the already saved probe
password remains the essential input before another bounded attempt.

The guarded SQL qualification and full database preflight remain **incomplete**.
Existing successful audits, tests, platform/offline preflight and local HTTP
recovery results are reused; no dependency/application changes were made in this
follow-up. Public application activation and all external mail remain pending.
