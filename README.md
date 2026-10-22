# TaskBridge

TaskBridge is a private Client/Freelancer workspace with project discovery, proposals, assignment, planning, immutable deliverable reviews, completion, external-payment records, messaging and administration. Payments are recorded after they happen outside TaskBridge; the application does not move money. Email verification is optional.

The checked-in locks currently resolve Laravel **12.70.0**, Livewire **4.4.7**, Volt **1.11.2**, free Flux **2.20.1**, Tailwind **4** and Vite **6.4.4**. PHP **8.2+** and MySQL **8.0.16+** are required for this documented setup; actual isolated verification used PHP 8.2.12, Node 22.14.0 and MySQL 8.0.43. These are tested versions, not a recommendation to freeze old security patch releases.

## Start here

- [Installation and safe updates](docs/installation.md): Windows PowerShell, locked dependencies, MySQL, reference seeders, tests and the first-Admin gap.
- [User guide](docs/user-guide.md): actual Client, Freelancer and Admin pages and restrictions.
- [Backup and recovery](docs/backup-recovery.md): consistent backups and disposable restoration only.
- [Hosting](docs/hosting.md): web root, storage, secrets, SMTP, deployment and runtime requirements.
- [Documentation index](docs/README.md): feature specifications and verification evidence.
- [Handoff verification](docs/handoff-verification.md): exactly what was exercised and remaining setup blockers.
- [Deferred manual review](docs/manual-review.md): browser, mobile, keyboard and environment checks still pending.

Use `composer install` and `npm.cmd ci`, not dependency updates, for reproduction. Do not run commands from a fresh-install guide against an existing database. Never regenerate an existing installation's `APP_KEY`, reset its database, or expose `storage/app/private` through the public web root.

There is currently **no first-Admin bootstrap command or seeder**. Registration offers Client and Freelancer only; reference seeders create no accounts. See the setup gap in [installation](docs/installation.md#first-administrator).

The latest complete SQLite regression result is **2,314 tests / 13,913 assertions**, zero failures, errors or skips, at email checkpoint `b86a6e0`. This handoff does not represent a new complete regression run. Local SMTP capture verified serialization and links, not external delivery. No screenshots or browser checks are implied.
