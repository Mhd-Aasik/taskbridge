# Setup and handoff verification

The documentation below was checkpointed as **`46a004d` — Document installation, user workflows and recovery**. The first-Admin gap described at that checkpoint is now addressed by [the console bootstrap](admin-bootstrap.md) on `fix/admin-bootstrap`. See the final verification section at the end for current results; the earlier installation/restore evidence remains historical.

## Checkpoint and scope

The 14 account-email files were reviewed against their recorded source hashes, JUnit report and successful checks, then committed as **`b86a6e0` — Add secure branded account emails and SMTP verification**. Work continued on **`docs/project-handoff`**. No repository/ancestor AGENTS.md was found. Credentials, MIME captures, logs and disposable verification artifacts were excluded from the commit.

This change is documentation only: root README, documentation index, installation, user guide, backup/recovery, hosting, this evidence record and the deferred manual checklist (**8 files**). There was no existing README or index in the inspected checkout, so both were added. No application behavior, environment, dependency lock, schema, mail configuration or frontend input changed. The first-Admin gap was documented rather than introducing an unauthorized promotion mechanism.

## Actually exercised

Verification used a source snapshot of the checkpoint under an ignored, marked temporary directory with its **own Git boundary**, separate `.env`, new test-only APP_KEY, storage and caches. It had no application credentials or private uploads. The established guarded harness provisioned a standalone **MySQL 8.0.43** instance bound to **127.0.0.1:33079**, with a separate datadir and database-restricted test account. All database identities/markers were checked before migration/import. Cached config and URL overrides were excluded; mail was `array`, so no actual email was sent in this handoff.

| Check | Actual result |
| --- | --- |
| `composer install --prefer-dist --no-interaction` | Passed, 104 locked packages installed in the disposable checkout; package discovery completed |
| `composer check-platform-reqs --lock` | Passed on PHP 8.2.12 with required installed extensions |
| `npm.cmd ci --no-audit --no-fund` | Passed, 94 locked packages; audit/funding output disabled only for this verification run |
| Fresh `.env` and `key:generate` | New disposable key generated once; original environment/key untouched |
| `migrate --pretend`, `migrate`, `migrate:status` | Fresh MySQL schema created successfully on the guarded test database only |
| `db:seed --class=SkillSeeder` and `CategorySeeder`, twice | Eight skills and eight categories; exact reference rows/timestamps unchanged on repeat; zero users/projects/proposals |
| `artisan list --raw`, `schedule:list`, `route:list --json` | Commands/routes inspected; only the starter hourly `inspire` schedule; no Admin bootstrap command |
| `config:cache`, `route:cache`, `view:cache` | Passed; guarded application connection rechecked with cached configuration |
| `down`, `up`, `config:clear`, `route:clear`, `view:clear`, `optimize:clear` | Passed inside the disposable checkout only |
| `npm.cmd run build` | Passed after install and Git-boundary isolation; manifest has one CSS entry, `assets/app-N6nZSU65.css`, matching the checkpoint asset; no empty JS chunk |
| `artisan serve --host=127.0.0.1 --port=18089 --no-reload` | Temporary server returned HTTP 200 for `/up` and `/login`; TaskBridge content checked with programmatic requests; process stopped |
| Isolated SQLite test recipe, filtered to `WorkflowLifecycleTest` and `AccountEmailTest` | **10 tests / 646 assertions**, zero failures/errors/skips, exit 0 |
| PowerShell-enumerated `node --test` frontend files | **13 passed**, zero failures/skips/cancellations, exit 0 |

The archive initially inherited ignored-parent source-scanning behavior and emitted a smaller CSS asset. Creating its own Git boundary, as a normal clone has, resolved that verification-environment issue. No application build configuration was changed to mask it.

The full **2,314-test / 13,913-assertion** SQLite result at the email checkpoint remains valid for unchanged application/test inputs: zero failures/errors/skips and exit 0, JUnit and text report agree. Repository-wide Pint and PHP/compiled Blade syntax results are reused for those unchanged files. The full regression run is **not** described as newly executed; the next project stage owns that final run. These checks do not imply browser or email-client rendering coverage.

## Backup/restore exercise

A single-database dump was made with native `mysqldump --result-file`, `--single-transaction`, `--skip-lock-tables`, `--skip-add-locks`, `--no-tablespaces`, `--set-gtid-purged=OFF`, `--hex-blob` and UTF-8. Automated authentication used private temporary client option files, not inline passwords or a real account. The guide uses interactive `--password` prompts for operators; prompting itself was not automated.

The source had **35 tables**: 33 migrated application tables, the instance safety marker and one synthetic non-domain probe table. The probe included Sinhala/Tamil text, an emoji, quotes/newlines, all 256 byte values, `9999999999.99` as DECIMAL(12,2), and a calendar DATE. No demonstration account/project was created in MySQL. Application data was otherwise only the reference catalogue and migration metadata.

Restore used a new `taskbridge_test_restore_...` database on that same **disposable** server, with its own marker and restricted restore account. Guards checked exact database prefix/name, port, datadir, matched account, marker and an empty target except for its marker. The dump was checked for database-switch statements. `mysql --execute="source ..."` imported without PowerShell re-encoding. All **35 source table row counts and full-row hashes matched**, as did effective columns and normalized schema/constraints. MySQL can add redundant explicit `CHARACTER SET` text to SHOW CREATE TABLE after import; normalization removed only that syntactic difference, and separate information-schema column comparisons retained charset/collation verification.

`robocopy /E` copied private test bytes into backup and a separate restore directory. Relative paths, sizes and SHA-256 hashes matched source/backup/restore, including the binary probe; a protected copy of the **test** environment was byte-identical. No original user file was copied into the exercise. A deliberate `SIGNAL SQLSTATE '45000'` import probe exited **1**, confirming SQL failures are detected. It did not alter records. The comparison was made at the dump's snapshot; later HTTP smoke requests could create disposable runtime sessions, not source domain changes.

Preliminary harness checks rejected an unsupported MySQL CLI option and caught raw DDL formatting differences. The harness was corrected, failure exit handling made explicit, and final restoration succeeded into a new empty guarded target. The documented `--skip-add-locks` avoids requiring restore-time LOCK TABLES privileges. Failed disposable targets were retained only until the isolated instance cleanup; none was reused as a live database.

This validates the documented **synthetic local** dump/import/file-copy mechanism. It does not validate a production backup, real encrypted application state, an off-host retention provider, disaster failover, a hosting-specific volume snapshot or recovery-time objective. Those operational checks remain deferred.

## Validation, cleanup and preservation

Documentation validation passed for **47 local links/anchors, eight PowerShell code blocks and 17 named routes** across the eight changed files; whitespace checks passed. Paths and command names were checked against repository source and the isolated command inventory. Commands requiring a real repository URL, operator credentials, provider configuration or hosting topology remain explicit placeholders; no real clone remote, secret entry, SMTP delivery or hosted deployment is claimed. The production `--no-dev` release recipe is source/command inspected, not a deployed release exercise.

Temporary source/dependency checkout, test environment/key, client credentials, dumps, probe files, restored files, SQL probes and MySQL databases/datadir are removed after verification. Only the marked disposable MySQL instance is shut down; the original MySQL service is unchanged. The temporary HTTP server is stopped. Sanitized textual verification reports remain ignored under `storage/logs`, outside source control.

Read-only final checks matched all **25 original MySQL domain tables**, account/profile metadata and private-file hashes with the captured baseline. The three real accounts remain active at access version zero; runtime sessions remain **four total, two authenticated**, accounted for separately. Original `.env`, dependency locks and existing cache files are byte-identical, with no added/removed original cache files. The original mail transport remains `log`. No migration/seed ran on the application database, and no real account, project, mail configuration or private file was changed.

## Remaining setup blockers and deferred checks

1. **First Admin (historical gap, now resolved below):** supported initial provisioning was absent at the documentation checkpoint. The later console-only bootstrap implementation below resolves provisioning, while Admin recovery remains deliberately outside its scope. Do not use production factories or ad hoc promotions.
2. **Hosting and external mail:** provider credentials, sender/DNS/TLS, canonical HTTPS URL, proxy/cookie behavior and external delivery/inbox placement remain unconfigured/unverified. Email verification remains optional and requires no queue worker.
3. **Operational recovery:** encrypted/off-host backup retention, real-key recovery, service cutover and recovery objectives remain pending. Only disposable local restoration was exercised.
4. **Browser review:** mobile, keyboard, light/dark, upload/proxy limits, email-client rendering and console/preload investigation remain on [manual-review.md](manual-review.md). No browser was opened or previously denied access retried.
5. **CI and release automation:** existing workflows target main/develop, use PHP 8.4, use `npm i`/`npm install` rather than `npm ci`, and the old lint workflow configures Flux credentials unnecessarily for the locked free package. They were inspected, not executed or rewritten. The documented Windows lockfile setup is the exercised installation path. The legacy welcome page was outside this documentation checkpoint and is replaced in the later final-completion work; planned capabilities in [workflow-audit.md](workflow-audit.md) remain deferred.

The documentation is intentionally **uncommitted and unstaged** on `docs/project-handoff`; no application setup fix was required.

## Bootstrap and final automated verification

The supported first-account command is `php artisan taskbridge:bootstrap-admin`. Installation, hosting, the role guide, README and index now describe its interactive restrictions; there is no account creation against the running MySQL database. No migration, dependency change, mandatory verification, queue worker or new product workflow was introduced.

The command uses hidden password/confirmation without a visible fallback, a default-No creation confirmation, server-controlled Admin/security fields and transactional insertion. Any Admin, including an unavailable account, blocks creation. The MySQL advisory lock is held through commit/rollback; SQLite acquires its writer reservation before checking. No existing account is promoted or replaced. See [admin-bootstrap.md](admin-bootstrap.md) for password rules, concurrency design and recovery limits.

Current automated checks:

| Check | Result |
| --- | --- |
| Complete SQLite `:memory:` regression suite | **2,330 passed / 13,994 assertions**, zero failures/errors/skips, exit 0; duration 754.16 seconds |
| Focused bootstrap SQLite/console/HTTP tests | **16 passed / 81 assertions**, exit 0 |
| Genuine MySQL 8.0.43 concurrency | **3/3 rounds passed**; each observed two distinct pending named-lock requests, exactly one first Admin and one refusal |
| Actual piped CLI and `--no-interaction` | Both refused immediately with exit 1 using SQLite `:memory:`; no prompts, table access or account creation |
| Existing JavaScript tests | **13 passed**, zero failures/skips/cancellations |
| Repository-wide Pint | Passed in check mode |
| New PHP syntax | Five files passed; no Blade inputs changed |
| Frontend build | Passed; one nonempty CSS manifest entry `assets/app-N6nZSU65.css`, no empty JavaScript chunk |
| Composer validation | `composer validate --strict` passed |
| Installed Composer platform requirements | Passed, including development packages, on PHP 8.2.12 |
| Locked Composer audit, all packages and runtime-only | Both completed successfully: **zero advisories and zero abandoned packages**, exit 0 |
| Locked npm audit | Completed successfully: **zero vulnerabilities at all severities**, exit 0 |

The final complete suite's JUnit, text output and captured exit status agree. All five new PHP/application/test/harness files match their pre-suite hashes; no application/test changes followed that run. No audit check was disabled to obtain a pass; no dependency upgrade was needed. Composer's lock has 79 runtime and 35 development packages. Runtime classification includes Laravel/Livewire/Flux and their dependencies; development includes PHPUnit, Pint, Pail, Sail, Faker, Mockery and Collision. npm declares its packages under `dependencies` rather than `devDependencies`; many (Vite, Tailwind, Rollup and related platform variants) are build tools, not a Node service deployed with TaskBridge. The npm lock audit reported 90 prod entries and zero dev entries; its total includes optional/platform packages, not only packages installed on Windows. Both complete audits cover development/build dependencies as well as runtime; zero findings required no risk exceptions.

Preliminary bootstrap failures were fixture-comparison/test-console setup problems, corrected without relaxing authorization, password requirements or expected results. MySQL workers ran the actual creation action in separate processes/connections; terminal prompts are simulated only by SQLite prompt tests. Actual external SMTP delivery, hosted HTTPS/proxy/cookies, web upload limits, encrypted off-host retention/production restore and physical terminal/browser/email-client review remain pending. Existing optional verification, external-payment scope and all deferred checks are preserved.

The final read-only preservation check matched all **25 MySQL domain tables and private-file hashes**. All three original accounts remain active at access version zero; runtime sessions remain four total, two authenticated. Original `.env`/APP_KEY and cached configuration files match their captured hashes and original file set. Mail remains `log`, sessions remain database-backed, and verification remains optional. No application migration, seed or account creation was run. The marked disposable MySQL instance, credentials, worker/barrier/results files and fixture storage were shut down and removed by the existing guarded cleanup; port 33079 and its test directory no longer exist. No real session was revoked or inspected.

Final scope at that stage was **13 files**: `app/Actions/Accounts/BootstrapAdmin.php`, `app/Console/Commands/BootstrapAdminCommand.php`, `tests/Feature/Auth/BootstrapAdminTest.php`, `tests/MySql/bootstrap.php`, `tests/MySql/bootstrap-worker.php`, `README.md`, and `docs/{README,admin-bootstrap,handoff-verification,hosting,installation,manual-review,user-guide}.md`. It was subsequently checkpointed as **a87ef49**. No lockfile or environment change was needed. Tracked/untracked whitespace, documentation links/anchors and PowerShell blocks passed validation. Temporary compiled views and actual-SMTP captures were removed/confirmed absent. Those preservation and audit statements describe that checkpoint, before the later authorized demo changes.

## Final local completion

The [24 October final-completion record](final-completion.md) is the current
verification reference: **2,349 tests / 14,302 assertions**, zero failures/errors/
skips, current build/JavaScript checks, the authorized demo data/account changes,
33-table real-snapshot restoration in isolation and partial actual browser review.
It also records current audit-network failures, rather than reusing an old clean
audit as a new result. Existing MySQL concurrency and loopback SMTP results remain
valid scoped evidence; they were not rerun against development records.
