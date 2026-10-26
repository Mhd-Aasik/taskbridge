# Email-first registration verification record

Prepared 27 October 2026 on `feature/email-first-registration`, based on
`edbb67e3bdd99f9f3087982b9e5a964502119c88`, in an isolated worktree. No dependency
lockfile changes, unchanged audits, production migrations or policy changes.

## Actual local evidence

- PHP 8.4.27; locked Composer development installation completed (114 packages).
  `npm ci --no-audit --no-fund` completed (94 packages).
- The initial full run executed **2,365 tests / 14,093 assertions**, with 3 errors
  and 19 failures. These were inspected, not accepted as a passing result: obsolete
  immediate-registration/optional-verification expectations, stale verification
  snapshots, email-changing privacy fixtures, additional email revocation checkpoints
  and a missing EXIF extension in the portable test PHP.
- After correction, all auth and affected regression files passed:
  **214 tests / 1,752 assertions**, including image orientation with EXIF enabled,
  account/status integrity, local-demo safety, privacy/escaping, role history and
  the new registration/verification security paths. Unchanged successful tests
  from the broad run were reused; final full-suite acceptance is required in CI.
- A final malformed-input check passed **21 tests / 133 assertions**; malformed
  fields return validation errors without consuming tokens or flashing secrets.
- The independent two-process completion race passed four additional consecutive
  SQLite runs. CI also runs this test on its own disposable MariaDB 11.8 service;
  SQLite results alone are not claimed as MariaDB evidence.
- All three JavaScript fragment/history/form tests passed. Final Vite 6.4.4 build
  succeeded; generated account-link asset is 0.62 kB (0.39 kB gzip). Pint dirty-file
  formatting check and `git diff --check` passed.
- Loopback SMTP tests captured branded setup, reset and verification MIME messages
  using reserved `.test` recipients only, followed captured links and removed the
  captures. No external recipient was used by the automated regression suite.
- Original checkout remains on main; all **17 protected local files** match the
  existing preservation baseline. The isolated testing environment/key, vendor,
  build output, logs and private SMTP tooling are excluded from the commit.

Final PR checks are the authoritative full-suite/MariaDB/linter results; do not
approve deployment from local results alone. Use the PR's checks and matching head
revision, not an earlier workflow notification.

## Production and mail gates

A read-only pinned-SSH inspection confirmed the unchanged live `edbb67e` release,
APP_DEBUG=false, capture-only external mail configuration, one intended existing
Admin and three total existing accounts. The Admin was **unverified** at inspection.
No existing account/password/role was changed by implementation or testing.

The private App Password flow is prepared outside Git. Its sanitized ledger
separates the single approved SMTP test and single Admin verification send, reserves
each before attempting it and prevents automatic retries after uncertain outcomes.
Actual SMTP acceptance, mailbox delivery and saved Admin verification must be
checked from that flow's real result; preparation is not delivery evidence.
No mail to other existing users is authorized.

**Deployment/enforcement is pending.** Required before approval: working SMTP,
actual approved-recipient delivery, genuine existing-Admin verification, reviewed
successful PR checks, a fresh encrypted backup and the release-specific migration,
configuration/rollback review in [the runbook](email-first-registration.md).
Do not bulk-verify, reset the Admin password, import local accounts or enable the
production demo command. Existing encrypted backups/recovery identities and the
completed host/restore qualifications are preserved and not repeated.
