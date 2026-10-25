# Free-hosting readiness evidence — 25 October 2026

Starting checkout: clean `main` at **cb0350e**, tracking `origin/main`, with the
configured remote `https://github.com/Mhd-Aasik/taskbridge.git`. Work continues on
`chore/free-hosting-readiness`; no repository/ancestor AGENTS.md was found. No push,
public deployment, remote infrastructure change, purchase or external email was
performed. The local environment, dependencies, accounts and durable uploads are
preserved; the proposed lockfile patch is installed only in isolated verification.

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
