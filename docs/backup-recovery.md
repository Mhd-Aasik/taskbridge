# Backup and recovery (Windows)

Current live status is recorded in [deployment completion](deployment-completion.md):
revision `67c9ab660811` is deployed with verified SMTP, mandatory verification before
login, preserved Admin/data and a passed-and-cleaned genuine signup test. Dated
pending statements below retain their original historical scope.

Treat a backup as a matched set: **MySQL database + durable private uploads + protected environment/key + source commit/lockfiles/build manifest**. Database rows alone cannot reconstruct files; files alone cannot reconstruct ownership or immutable history. Store backups outside the web root/repository, on encrypted access-controlled storage with a documented retention policy and a second protected/off-host copy. Keep access to secrets narrower than access to source. Do not commit backup SQL, `.env`, mail captures or private uploads.

## Consistent capture

Arrange a write-quiescent maintenance window for every application instance and let in-flight requests finish before the dump/file copy. Stop any separate writers/imports. `php artisan down` affects incoming application requests; it does not stop existing transactions, a separate process or an operator writing SQL. Do not perform DDL during a `--single-transaction` dump. All current domain tables should use InnoDB. The dump's transaction is database-consistent, but it cannot synchronize an independently changing upload directory; that is why the write pause matters.

The commands below are **operator instructions**, not evidence that the real application was paused/backed up in this task. Replace the path/database/user with the intended backup source; inspect the resolved identity first. Use a database-scoped backup account with SELECT and SHOW VIEW (and any additional privileges required if future releases add routines/events/triggers); no global administrative privileges are needed for the current table-only schema with `--no-tablespaces`. Use `--password` without a value to prompt; never pass inline passwords or `MYSQL_PWD`.

```powershell
$backupRoot = 'C:\TaskBridgeBackups\REPLACE_UNIQUE_BACKUP_ID'
$applicationRoot = 'C:\Projects\taskbridge'
if (Test-Path -LiteralPath $backupRoot) { throw 'Refusing to overwrite a backup' }
# Use an already encrypted, access-controlled parent directory.
New-Item -ItemType Directory -Path $backupRoot | Out-Null
mysql --host=127.0.0.1 --port=3306 --user=REPLACE_BACKUP_USER --password --database=REPLACE_SOURCE_DATABASE --execute="SELECT DATABASE(), CURRENT_USER(), @@port, @@version;"
# Confirm the displayed identity against the intended source before proceeding.
mysqldump --host=127.0.0.1 --port=3306 --user=REPLACE_BACKUP_USER --password --single-transaction --skip-lock-tables --skip-add-locks --no-tablespaces --set-gtid-purged=OFF --hex-blob --default-character-set=utf8mb4 "--result-file=$backupRoot/database.sql" REPLACE_SOURCE_DATABASE
if ($LASTEXITCODE -ne 0) { throw 'Dump failed; do not label this backup complete' }
robocopy "$applicationRoot/storage/app/private" "$backupRoot/private" /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /NFL /NDL /NJH /NJS
if ($LASTEXITCODE -ge 8) { throw 'Private-file copy failed' }
Copy-Item -LiteralPath "$applicationRoot/.env" -Destination "$backupRoot/environment.env"
Get-FileHash -LiteralPath "$backupRoot/database.sql" -Algorithm SHA256
```

**Do not replace `--result-file` with `>` or pipe the dump through `Out-File`/`Set-Content`.** Windows PowerShell can decode/re-encode native output and corrupt a SQL backup. MySQL writes its own bytes with `--result-file`; `--hex-blob` preserves binary columns. `--skip-add-locks` omits restore-time LOCK TABLES statements, allowing the restricted restore account to import without that extra privilege. Use the matching MySQL tools version. This is a single-database dump without `--databases`, so it does not direct restoration into the original database. Inspect a backup's provenance before executing any SQL; an untrusted dump is executable code.

`robocopy /E` copies bytes without deleting destination files; unlike `/MIR` it does not erase unrelated data. A brand-new backup directory prevents stale files being mistaken for current ones. For the application data, durable subdirectories are `avatars`, `project-covers`, `deliverables`. Copying all `private` also captures any existing local artifacts/temp uploads; classify and protect them. Prefer backing up durable directories plus a manifest in an operational system. Do not publish temporary upload files or include runtime artifacts in a release. If secrets are supplied by the host rather than `.env`, export them through that host's protected backup mechanism, including the original APP_KEY; the example `.env` copy alone is insufficient.

Record commit/lockfile hashes, migration version, source server version and a manifest of durable files (relative path, size, SHA-256) alongside the backup. Compare the source and backup manifests while writes remain paused. Row counts alone are insufficient: record deterministic row hashes for the critical tables in a protected verification report; never print row contents or password/token/session payloads. Document runtime session/cache differences separately. Resume writes only after the set is complete and protected. Choose retention and restore-test frequency based on the acceptable data-loss window; retain at least one validated earlier set rather than replacing the only copy.

## Disposable restore only

Never import over `taskbridge`, another live database, existing uploads or current caches. Create a **separate loopback MySQL instance** and a new directory; the [existing harness](mysql-verification.md) provides identity/marker safeguards for this machine. Its hardcoded port/datadir and MySQL installation path must match before use; do not redirect it to a shared server. For generic recovery, the target name must explicitly start `taskbridge_test_restore_`, with a unique safety marker recorded both outside the dump and in the empty target database. Refuse a nonempty target (other than its marker), a mismatched host/port/datadir, or a missing marker.

Use a restricted restore user with schema/DML privileges **only** on that disposable database. No production credentials or copied cached config may be used. `DB_URL`/`DATABASE_URL` overrides must be absent. Confirm all of the following through the resolved connection **before** import:

```sql
SELECT DATABASE(), CURRENT_USER(), @@port, @@datadir, @@version;
SELECT marker FROM handoff_restore_safety;
SHOW TABLES;
```

The expected database, loopback port, exact new datadir and marker must match the separately recorded target. A database name alone is not a guard. Import a trusted single-database dump using MySQL's own `source` command, avoiding PowerShell text pipelines. This example assumes a no-space path and a target already provisioned/guarded as above; replace every placeholder. Keep the password prompted:

```powershell
mysql --host=127.0.0.1 --port=REPLACE_DISPOSABLE_PORT --user=REPLACE_RESTORE_USER --password --database=taskbridge_test_restore_REPLACE_ID --default-character-set=utf8mb4 --execute="source C:/TaskBridgeRestore/REPLACE_ID/database.sql"
if ($LASTEXITCODE -ne 0) { throw 'Restore failed' }
```

Do not include `--force`; SQL errors must fail the exercise. Restore files via `robocopy /E` into a newly created **disposable** private directory, check exit code, then compare all durable file relative paths/sizes/SHA-256 hashes. Compare table lists, schema/constraints, row counts and deterministic full-row hashes with the source snapshot. Test non-ASCII text, binary values, decimal amounts and dates as well as row counts. The target's separate safety table is the only intentional extra table.

Use a new checkout configured solely for that target, with array/log mail, no external transports and separate storage/caches. Restore the matching key through a protected mechanism if testing encrypted real backups; never print it. Disable unintended callbacks/scheduled jobs. Check migrations state and authorized account/history/download behavior using tests without changing original records. Do not backfill, recreate users or alter dates to make restoration pass. This handoff's synthetic exercise does not validate restoration of real production encrypted secrets or a future host's volume snapshots.

After verification, shut down **only the marked disposable server** by its validated identity, wait for its specific process/port to stop, re-resolve the exact expected directory, reject reparse points/out-of-root targets and remove only created test files/users/databases. Do not run generic recursive deletion or `DROP DATABASE` against a computed/unvalidated target. Keep a sanitized report without dumps, credentials or message bodies. An actual recovery cutover is a separate authorized operation after validation, not an overwrite step in this guide.

## Evidence and limits

See [handoff verification](handoff-verification.md) for the exact isolated dump/import and file-hash checks actually executed. The documented operational capture of a running installation, encrypted off-host retention, restoration of production secrets, hosting-specific disaster cutover and recovery-time objectives remain unverified. No real application's database or files were restored, reset or paused during this task.
