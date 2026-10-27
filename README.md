# TaskBridge

TaskBridge is a private Client/Freelancer workspace with project discovery, proposals, assignment, planning, immutable deliverable reviews, completion, external-payment records, messaging and administration. Payments are recorded after they happen outside TaskBridge; the application does not move money. The deployed application requires email verification before normal login: register name/email/role, receive a setup link, set a password and verify through a protected POST, then sign in. Registration/setup never log users in automatically.

The checked-in locks currently resolve Laravel **12.70.0**, Livewire **4.4.8**, Volt **1.11.2**, free Flux **2.20.1**, Tailwind **4** and Vite **6.4.4**. PHP **8.2+** and MySQL **8.0.16+** are required for the verified local setup; actual isolated verification used PHP 8.2.12, Node 22.14.0 and MySQL 8.0.43. The actual Alwaysdata qualification passed on MariaDB 11.8.9 with PHP 8.4.27; this is separate from the local MySQL evidence. Use current patched server versions when deploying.

## Start here

- [Current deployment completion](docs/deployment-completion.md): verified live revision, email-first flow, real delivery/login checks, backup/rollback and remaining coverage limits.

- [Installation and safe updates](docs/installation.md): Windows PowerShell, locked dependencies, MySQL, reference seeders, tests and supported first-Admin bootstrap.
- [Local demonstration](docs/demonstration-data.md): explicit, guarded setup using existing participants, original covers and normal workflows.
- [User guide](docs/user-guide.md): actual Client, Freelancer and Admin pages and restrictions.
- [Backup and recovery](docs/backup-recovery.md): consistent backups and disposable restoration only.
- [Hosting](docs/hosting.md): web root, storage, secrets, SMTP, deployment and runtime requirements.
- [Alwaysdata Free readiness](docs/alwaysdata-free.md): isolated release preparation, measured resource evidence, HTTPS and owned-sender email requirements for a university demonstration.
- [Documentation index](docs/README.md): feature specifications and verification evidence.
- [Handoff verification](docs/handoff-verification.md): exactly what was exercised and remaining setup blockers.
- [Deferred manual review](docs/manual-review.md): browser, mobile, keyboard and environment checks still pending.

Use `composer install` and `npm.cmd ci`, not dependency updates, for reproduction. Do not run commands from a fresh-install guide against an existing database. Never regenerate an existing installation's `APP_KEY`, reset its database, or expose `storage/app/private` through the public web root.

After fresh migrations, an authorized operator can run **`php artisan taskbridge:bootstrap-admin`** from an interactive terminal. It creates only the first Admin, refuses any existing Admin, never promotes an account, and prompts for a hidden password and explicit confirmation. Registration offers Client and Freelancer only; reference seeders create no accounts. See [first-Admin setup](docs/installation.md#first-administrator) and [bootstrap safeguards](docs/admin-bootstrap.md).

The deployed application revision `67c9ab660811` passed final main CI: **2,391 tests / 14,857 assertions**, two real MariaDB account-completion races and lint. HTTPS, session/CSRF, assets, private access, unchanged verified-Admin login and one genuine external setup-email/verified-login flow passed; the approved test account was removed. Successful unchanged npm and patched Composer audit results are retained in [readiness verification](docs/free-hosting-verification.md), rather than repeated or presented as a newer audit. See [deployment completion](docs/deployment-completion.md) for actual evidence and ongoing operation, and [final local verification](docs/final-completion.md) for preserved demonstration data.
