# Isolated MySQL concurrency verification

## Checkpoint and scope

Hardening was checkpointed as `44d023d` (`Harden authentication and verify project workflows`), after reviewing the 15 files, unchanged verified input hashes, complete JUnit/exit records (2,306 tests / 13,804 assertions, zero failures/errors/skips), formatting, syntax, whitespace and build results. Verification development is on `feature/mysql-verification`; its files remain unstaged/uncommitted. No application fix or business-rule change was required by these scenarios. Email verification remains optional and payments remain external full-payment tracking.

## Actual local setup

- MySQL Community Server **8.0.43**, separate process on **127.0.0.1:33079**, initialized with `--no-defaults`, a new data directory, loopback binding and MySQL X disabled. The existing MySQL80 service was neither restarted nor reconfigured.
- Database: **`taskbridge_test_mysql_20261022`**, with an explicit `verification_safety` table/marker. Data, credentials, synchronization files and uploaded test files live only beneath ignored `storage/framework/testing/mysql-verification-20261022`.
- The existing application account has only `USAGE` globally and database privileges on `taskbridge.*`; it cannot provision another database. No privileges were added to it. The installed server binaries enabled a separate instance instead, without Docker/container tooling.
- A new `verification` account has privileges only on the disposable schema. A separate randomized root password is used only on that isolated instance for lock-wait observation and shutdown. Credentials are generated in PHP, never passed on process command lines, and are deleted with the disposable directory.
- Laravel loads no application `.env`, pins its configuration-cache and route-cache paths to absent disposable paths, rejects unexpected files there, and explicitly overrides database, array mail/session/cache, synchronous queue, log and private storage settings before provider boot. Neither the running application's configuration nor its private storage is edited.

## Commands (PowerShell, repository root)

```powershell
& tests/MySql/setup.ps1
php tests/MySql/guards.php
php tests/MySql/run.php
# Additional runs append new committed fixtures; no reset/truncation is used.
php tests/MySql/run.php
& tests/MySql/cleanup.ps1
```

Setup requires the installed `C:\Program Files\MySQL\MySQL Server 8.0` binaries, PHP with PDO MySQL, an unused port 33079 and a nonexistent disposable directory. It refuses reuse. Initialization/startup failures leave the marked directory for diagnosis rather than guessing cleanup targets. The first setup was performed with equivalent explicit commands; the checked-in setup/cleanup scripts were subsequently exercised through a fresh full lifecycle.

Before migration, connection use or cleanup, the harness requires the exact test-only database name, explicit marker, loopback port and resolved server `@@datadir`; it queries `DATABASE()` and the database safety table. `taskbridge`/3306 and mismatches are refused. `DB_URL` and `DATABASE_URL` overrides are rejected. The bootstrap overrides inherited `DB_DATABASE` and config-cache paths; six safety probes passed, including attempted application-database selection and inherited production cache path. These probes restore their temporary configuration in `finally`.

Only normal additive `migrate --force` runs against the marked disposable database. No `migrate:fresh`, truncate, reset, application seeding or historical backfill is used. `phpunit.xml` is unchanged: this standalone suite is outside `tests/Unit` and `tests/Feature`; ordinary SQLite tests gain no environment-dependent skips.

## Synchronization and assertions

The coordinator commits fixtures before launching independent PHP worker processes using Symfony Process. Workers bootstrap independent Laravel/PDO connections and authenticate fixture users with session access-version stamps. A filesystem readiness/release barrier pauses each action immediately before its first locking SQL statement. The coordinator holds a separate project/user row lock, releases the barrier, and waits until **actual `performance_schema.data_lock_waits` rows** show all competing workers blocked in InnoDB. It then releases its lock. Polling intervals are bounded readiness checks, not the evidence of concurrency.

Worker limits are 45 seconds, InnoDB lock waits 15 seconds, barrier readiness 20–25 seconds and lock observation 12 seconds. Processes are stopped and coordinator transactions rolled back in `finally` on failure. Unexpected database errors fail the run with exit status one. Existing action-level three-attempt deadlock retries remain in place; the suite does not swallow arbitrary conflicts or change lock order. A naturally occurring deadlock is not guaranteed or claimed.

| Scenario | Actual assertions |
| --- | --- |
| Competing acceptance | Two owning-Client selections compete for different offers; a second Client's concurrent forged acceptance is forbidden. Exactly one assignment/accepted offer, other submitted offer rejected, exact original selected decimal/date terms, unchanged original offers and exact-retry assignment attributes/time; one assignment activity event. There cannot be two legitimate Client owners of one project. |
| Duplicate submission | Two simultaneous submissions by the same Freelancer return the same proposal. Exactly one row and one Client notification; subsequent retry with empty replacement input preserves all original fields and submission time. |
| Revision versus latest review | A real private-file upload races approval of the prior latest version. Either upload succeeds and stale approval fails, or approval succeeds and upload fails. No approved superseded version; both outcomes were observed. |
| Completion versus unfinished work | Completion races creation of an unfinished task. Exactly one effective winner; completed projects never contain the new unfinished work. Both winner orders were observed. |
| Reset-token reuse | Two independent reset actions race the same token. One returns success, the other invalid token; token consumed, access version increments exactly once. |
| Password revocation then stale write | A worker loads its User/session stamp and pauses before its first locking read; another connection commits password revocation. Released stale worker receives 403 and creates no task. |
| Suspension then stale write | The same ordered interleaving uses the actual authorized Admin suspension action. The suspended old session cannot write. These two revocation tests deliberately enforce commit-before-write, rather than racing to see whether a pre-revocation write can win. |

A separate injected activity failure occurs after storing a genuine upload inside a MySQL transaction. Business/version rows, notifications, activity and files must all return to their pre-action snapshots. Across all accumulated fixtures, notification/activity keys have no duplicates and deliverable storage files exactly match persisted version paths, with no orphans or missing files.

These tests call the real reusable actions, not HTTP endpoints. The existing HTTP/Volt lifecycle and request-middleware tests remain the complementary entry-point coverage. Private uploaded bytes are real disposable files; no real application file is used.

## Results and preservation

The final harness passed **two consecutive complete runs**, each with seven scenario variants over three rounds: **42 scenario executions plus two late-failure rollback/file checks**, exit status zero for both. Local evidence: `storage/logs/mysql-races-pass-d.txt` / `.exit.txt` and `mysql-races-pass-e.txt` / `.exit.txt`. These are standalone scenario counts, not PHPUnit test/assertion totals. The distinct competing offers have different precise budgets and deadlines; selected terms are checked against the original pre-race snapshots. Three earlier complete runs also passed while the assertions were being expanded; they are not included in the final 42 count.

All six safety probes passed. Repository-wide Pint, syntax for all six new PHP files and both PowerShell scripts, and tracked/untracked whitespace checks passed. No generated report, credential or runtime file is included in the 10-file Git scope.

Cleanup succeeded twice, including a successful fresh setup/guard/scenario run between cleanups. The final disposable directory no longer exists and its server is stopped. The existing MySQL80 service/application database remained usable for the final read-only baseline comparison. Preliminary harness problems (Windows path-separator normalization, notification column name, classification of policy exceptions, expected injected failure type and CLI failure exit handling) were corrected in the harness; none demonstrated an application regression.

The read-only application baseline comparison matches all **25 domain tables**, original account/profile/project/proposal values and private-file hashes (**one avatar; zero cover/deliverable files**). All three real accounts remain active at access version zero. Runtime sessions are separate: **four total, two authenticated**, unchanged in aggregate. No migration ran against the application database and no demonstration records, notifications or mail were created there.

No application, normal SQLite-test, route, schema, frontend or dependency input changed after checkpoint. Therefore its full passing **2,306-test / 13,804-assertion SQLite suite**, JavaScript 13-pass result and successful frontend build are reused, rather than reported as newly executed. Repository-wide Pint, new PHP/PowerShell syntax and whitespace checks are run for these verification files.

## Remaining limits

Repeated controlled contention is stronger evidence than SQLite interleavings, but is not an exhaustive scheduler, sustained-load, crash-recovery, network-partition or all-deadlock-order test. Other concurrency domains (feedback, categories, message cursors, notification read state, milestone edits and simultaneous Admin deletion) still need separate targeted MySQL coverage. HTTP cookies/session-backend transport behavior remains covered by existing SQLite request tests and deferred environment review, not by a real browser here. No browser access, real mail, publication or deployment was attempted.

Cleanup first revalidates the exact marked server/database, issues authenticated shutdown, waits for the disposable port/process to stop, resolves the expected absolute workspace directory and checks its marker before recursive removal. Only that created instance's database, users, credentials, private fixtures and files are removed. Ignored textual result logs remain outside that directory as local evidence; none are staged.

## Changed files

`tests/MySql/{environment,provision,guards,run,worker,shutdown}.php`, `tests/MySql/{setup,cleanup}.ps1`, this document and `docs/manual-review.md` (10 files). All are verification/documentation changes; application behavior is unchanged.
