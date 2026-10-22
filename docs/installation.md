# Installation and updates (Windows PowerShell)

## Prerequisites

Use a dedicated checkout owned by your Windows account. Replace **every** `REPLACE_...` value privately; these are placeholders, not working credentials. Commands assume the repository root unless stated otherwise. SQL blocks belong in a MySQL client, never PowerShell. Check `$LASTEXITCODE` after native commands and stop on failure.

- PHP 8.2 or newer compatible with `composer.lock`, Composer 2, Git, Node 22/npm, MySQL 8.0.16+ with InnoDB and enforced CHECK constraints. Verified locally: PHP 8.2.12, Composer 2.10.3, Node 22.14.0/npm 10.9.2, MySQL 8.0.43. Vite's installed engine range is `^18 || ^20 || >=22`; Node 22 is the exercised choice.
- Enable PHP `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `PDO`, `pdo_mysql`, `pdo_sqlite`, `Phar`, `session`, `tokenizer`, `xml`, `xmlwriter`, `zip`. Some are built in or polyfilled; enable native mbstring/ctype where available. GD must support JPEG, PNG and WebP. ZipArchive is used for deliverable ZIP validation. SQLite is for isolated tests; MySQL is the application database. PCNTL is unavailable on ordinary Windows PHP and is not required by the documented server command.
- Ensure `php`, `composer`, `node`, `npm.cmd`, `mysql` and `mysqldump` resolve to the intended installations. Enable extensions in the **CLI and web** PHP configurations; one does not prove the other.

```powershell
php -v
php --ini
php -m
composer --version
node --version
npm.cmd --version
mysql --version
```

## Fresh installation only

Clone the authorized repository to a new directory (replace the repository URL and path). No real `.env`, uploads or database are included in Git.

```powershell
git clone REPLACE_REPOSITORY_URL C:\Projects\taskbridge
Set-Location C:\Projects\taskbridge
composer install --prefer-dist --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer install failed' }
composer check-platform-reqs --lock
if ($LASTEXITCODE -ne 0) { throw 'PHP platform requirements failed' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'npm install failed' }
if (Test-Path -LiteralPath .env) { throw 'An environment already exists; use the update instructions' }
Copy-Item -LiteralPath .env.example -Destination .env
```

Composer's `post-autoload-dump` performs package discovery. `composer install` in a cloned repository does not run `post-create-project-cmd`; do not substitute `composer create-project`. The locked free Flux package needs no private Flux license. The old CI lint workflow still contains optional Flux credential setup; it is not part of these installation commands.

### Database provisioning

The example below creates **new** `taskbridge_local` resources. Stop if the name already exists; never reuse an existing installation's database for a fresh setup. Log into an authorized MySQL provisioning account with a prompted password:

```powershell
mysql --host=127.0.0.1 --port=3306 --user=REPLACE_PROVISIONING_USER --password
```

Run separately in the MySQL client, replacing the password privately. The application account has privileges only on this database, no global privileges or grant option. This local account can run migrations; a hosted runtime account can instead have DML-only privileges with a separate database-scoped deployment account for schema changes.

```sql
CREATE DATABASE taskbridge_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'taskbridge_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_RANDOM_PRIVATE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
ON taskbridge_local.* TO 'taskbridge_app'@'127.0.0.1';
```

`DROP` is needed by schema rollback tooling, not ordinary application requests. Do not grant access to other databases. Use the deployment identity for migrations if runtime is restricted further. MySQL host matching and provider provisioning differ; confirm the actual matched account with `CURRENT_USER()`.

Edit **only the new** `.env` privately. `.env.example` starts with Laravel/SQLite defaults; replace the existing keys, including commented DB keys, rather than appending conflicting duplicates:

```dotenv
APP_NAME=TaskBridge
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=taskbridge_local
DB_USERNAME=taskbridge_app
DB_PASSWORD="REPLACE_WITH_RANDOM_PRIVATE_PASSWORD"
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=log
MAIL_FROM_NAME=TaskBridge
PHP_CLI_SERVER_WORKERS=1
```

Quote/escape environment passwords according to dotenv syntax. Never paste actual values into shared logs or source control. Remove unintended shell-level `DB_URL`/`DATABASE_URL` and `APP_CONFIG_CACHE` overrides; this repository reads `DB_URL`, which can override discrete DB settings. Use a fresh PowerShell session and a checkout with no copied `bootstrap/cache/config.php`. Do not print config dumps containing secrets. `MAIL_MAILER=log` captures sensitive mail links in local logs, **not external delivery**.

Generate a key **once, for this new installation only**, then inspect the selected connection using the application user's prompted MySQL login:

```powershell
php artisan key:generate
if ($LASTEXITCODE -ne 0) { throw 'Key generation failed' }
mysql --host=127.0.0.1 --port=3306 --user=taskbridge_app --password --database=taskbridge_local --execute="SELECT DATABASE(), CURRENT_USER(), @@port;"
php artisan migrate --pretend
php artisan migrate
if ($LASTEXITCODE -ne 0) { throw 'Migration failed' }
php artisan db:seed --class=SkillSeeder
php artisan db:seed --class=CategorySeeder
php artisan migrate:status
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Asset build failed' }
php artisan serve --host=127.0.0.1 --port=8000
```

Before `migrate`, confirm that those environment settings and the displayed database identity match the new database. `--pretend` can create the migrations bookkeeping table; it is not a substitute for checking identity. Stop on any seeding error. SkillSeeder and CategorySeeder insert missing reference slugs only; repeated seeding preserves saved names/descriptions/category activation and creates no users/projects. DatabaseSeeder calls only these two, but targeted commands make the intent explicit.

Serve binds loopback for development and stays in the foreground (Ctrl+C stops it). For CSS hot reload, use a second terminal at the same root with `npm.cmd run dev`; for built assets it is unnecessary. Do not use the bundled `composer dev` as the Windows recipe: it also launches a queue listener and Pail (PCNTL dependency) via a shell `npm` command. Current features need neither a queue listener nor Pail. No `storage:link` is needed for the private photo, cover or deliverable endpoints. Do not link private storage publicly.

### First administrator

**Setup gap:** there is no first-Admin Artisan command, Admin seeder, role-changing UI or documented recovery bootstrap in this checkout. `/register` permits only Client and Freelancer. Seeders intentionally create no accounts. A fresh installation can exercise both participant roles, but cannot enter Admin pages until a separately authorized, reviewed provisioning mechanism is implemented. Do not promote a user with an ad hoc SQL/Tinker recipe, use a factory in production, or weaken registration. No real account was created/modified for this handoff.

## Updating an existing installation

Back up the database, private uploads and protected environment/key together first; see [backup and recovery](backup-recovery.md). Review the release and migration SQL. Preserve `.env`, **the original `APP_KEY`**, database identity and private volumes. Do not copy `.env.example` over an existing environment, run `key:generate`, `migrate:fresh`, `migrate:refresh`, `db:wipe` or factory/demo seeders. A changed key can invalidate encrypted data, sessions and signed links.

From the intended release directory, install lockfiles, build, apply only pending migrations and refresh only that release's caches during a controlled deployment. See the [hosting sequence](hosting.md). Reference seeders can be repeated deliberately to add missing catalogue entries; they do not synchronize over Admin edits. Do not automatically re-seed merely because code changed.

## Isolated tests

Use a **separate temporary checkout**, not the running application's environment/storage. Install dev dependencies and build there first. `phpunit.xml` selects SQLite `:memory:`, array mail/session/cache and synchronous queues; PHPUnit environment entries do not override every inherited variable. In a new PowerShell terminal inside that temporary checkout, explicitly isolate before running:

```powershell
$testRoot = (Get-Location).Path
if (Test-Path -LiteralPath bootstrap/cache/config.php) { throw 'Use an uncached disposable checkout' }
Remove-Item Env:DB_URL,Env:DATABASE_URL -ErrorAction SilentlyContinue
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:APP_CONFIG_CACHE = Join-Path $testRoot 'storage/framework/testing/no-config.php'
$env:APP_ROUTES_CACHE = Join-Path $testRoot 'storage/framework/testing/no-routes.php'
$env:VIEW_COMPILED_PATH = Join-Path $testRoot 'storage/framework/testing/test-views'
if ((Test-Path $env:APP_CONFIG_CACHE) -or (Test-Path $env:APP_ROUTES_CACHE)) { throw 'Unexpected test cache' }
New-Item -ItemType Directory -Force -Path $env:VIEW_COMPILED_PATH | Out-Null
php artisan test --compact
if ($LASTEXITCODE -ne 0) { throw 'Tests failed' }
$jsTests = @(Get-ChildItem tests/Frontend/*.test.cjs | ForEach-Object { $_.FullName })
node --test @jsTests
php vendor/bin/pint --test
git diff --check
```

Close this terminal afterward so test overrides do not leak into server/deployment commands. Fake storage stays within the disposable checkout. Run suites sequentially; shared fake-storage directories are not safe for overlapping runs. `AccountSmtpTest` starts a temporary loopback sink for `.test` addresses only; it has no relay and cleans up. Ordinary tests do not depend on a MySQL service. Real concurrency tests are separately opt-in; follow [their guarded harness](mysql-verification.md), never point them at the application database.

The [handoff record](handoff-verification.md) distinguishes commands exercised now from the complete regression/build results reused from the unchanged checkpoint.
