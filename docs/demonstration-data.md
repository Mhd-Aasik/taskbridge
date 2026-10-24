# Explicit local demonstration setup

This is an opt-in local fixture workflow, separate from reference seeding and
installation. It represents no real commission, dispute or transfer of money.
Never run it as a deployment step or against production.

## Preconditions and command

Back up the selected database, private files and protected environment/key
together first; verify a disposable restoration as described in
[backup and recovery](backup-recovery.md). Confirm the resolved database identity
and configuration privately. Do not reset an existing installation.

The command requires console execution, effective `APP_ENV=local`, a `log` or
`array` mailer, existing active Client/Freelancer accounts, active categories and
skills from the reference catalogue. It creates no accounts, changes no login
credentials and sends no external mail. Registration or supported first-Admin
bootstrap remains separate. Account IDs below are placeholders, not defaults:

```powershell
php artisan taskbridge:demo --client=CLIENT_ACCOUNT_ID --freelancer=FREELANCER_ACCOUNT_ID
```

Replace both placeholders with verified numeric IDs for the intended existing
participants. An unavailable/wrong-role account, missing active category or
unsafe environment refuses setup. The command publishes a validated demonstration
Freelancer profile and synchronizes its selected reference skills; use dedicated
demo accounts, because their profile introduction is intentionally updated.
Existing account-photo-sharing consent is preserved.

`LocalDemoAccounts` is a separate guarded service used only for explicitly
authorized local identity reconciliation. It has no password command-line
interface, is not called by `taskbridge:demo`, and supplies no default credentials.
Email/password changes use the supported reset/revocation mechanism and clear
the old address's verification state. Credentials belong outside source control.

## Managed records and reruns

`storage/app/taskbridge-demo-v1.json` records selected account IDs and the eight
created project identities. Keep this private manifest with the installation's
backups; do not commit it, delete it to force setup or copy it to another database.
A nonblocking local file lock prevents overlapping setup in the same installation.
All database workflow changes share one transaction. Prepared identities are
recorded before commit, and normal file actions remove uploads after rollback.

A matching eight-project rerun returns without creating events, resetting dates,
changing files or overwriting later workflow changes. Changed titles/ownership,
missing/partial history or different selected accounts refuse automatic repair.
A prepared manifest with no surviving rows permits a rolled-back attempt to be
retried. This is a local operator tool, not a multi-host fixture coordinator or a
general reconciliation/reset command. A lost manifest requires operator review.

Deadlines are calculated from Asia/Colombo's actual calendar date on first setup.
They do not move forward on reruns; open examples will naturally expire. There
is no automatic republishing, historical backfill or timestamp fabrication.

## Catalogue and workflow coverage

| Project | Initial result | Fixed LKR budget | Deadline offset |
| --- | --- | ---: | ---: |
| Boutique hotel booking website | Private draft | 285,000.00 | 45 days |
| Restaurant ordering interface | Private draft | 145,000.00 | 30 days |
| Online store for a local retailer | Open, one submitted offer | 320,000.00 | 55 days |
| Appointment booking mobile application | Open | 410,000.00 | 60 days |
| Tea shop brand identity | Open | 95,000.00 | 28 days |
| Student task-planning dashboard | In progress | 185,000.00 | 35 days |
| Small-business portfolio website | Completed; receipt awaiting acknowledgment | 125,000.00 | 24 days |
| Sales reporting dashboard | Completed; receipt acknowledged | 215,000.00 | 40 days |

The three assigned examples use real proposal acceptance, immutable agreed terms,
one milestone and three tasks each. The active example has Todo/In progress/Done
tasks, a requested revision awaiting review and a private demonstration dispute.
Each completed example has approved latest work, completed tasks/milestone and
explicit Client completion. External-payment reports and mutual feedback use the
normal confirmation and blind-release rules. No money is transferred.

Initial setup produces four proposals, three assignments, nine tasks, six
deliverable versions, five reviews, six participant messages, two payment reports,
one receipt acknowledgment, four released ratings, one dispute, 48 activity events
and 37 notifications. These are fixture initial counts, not permanent application
statistics; subsequent interaction can change read state and permitted workflow.

Every new project has an original 1200 × 760 WebP cover from
[the source artwork](../resources/demo/README.md). Normal cover validation,
private storage and authorized endpoints apply, including draft privacy. Source
files are reusable assets; stored copies are private application data. No
`storage:link` or public uploads directory is needed.

## Evidence and limits

SQLite tests exercise the complete setup, exact-rerun idempotency, late failure
rollback/file cleanup, environment guard and selected-account isolation. An actual
disposable MySQL setup and unchanged rerun also passed before the authorized local
application setup. Original sample project/proposal records were preserved.
See [final verification](final-completion.md) for this installation's evidence,
backup, current browser coverage and unresolved deployment checks.
