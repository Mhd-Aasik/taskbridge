# Email-first registration release review

Prepared for PR/CI review only. The live baseline remains
`edbb67e3bdd99f9f3087982b9e5a964502119c88` at
`https://taskbridge-aasik.alwaysdata.net`. This task does not authorize deploying
the feature or changing live authentication policy.

## Flow and security

`/register` accepts name, normalized email and Client/Freelancer role only. It
creates a pending identity, not a user/session. Admin and initial passwords are
rejected. Generic responses and lookup-independent IP/address throttles avoid
account enumeration. Registration never overwrites an existing account.

The branded email carries a random 64-character token in a URL fragment; only
SHA-256 is stored. The browser removes the fragment from history and sends it
with the password in an ordinary CSRF POST. GET/prefetch never verifies or consumes
anything. Tokens expire after 60 minutes. Resend rotates the token with the same
pending identity; failed delivery rolls back rotation. A new registration may
correct a pending identity after throttling, never an existing account.

Completion conditionally claims the UUID/email/stored identity/role/hash/unexpired,
unused record and inserts the unique user in one transaction. Only one concurrent
completion can win. It hashes the password, records verified ownership, consumes
the token and redirects to login without signing in. Passwords require 12+
characters, upper/lowercase, numbers and symbols, at most 72 bytes.

Existing users retain passwords/ownership/history. Enforced unverified accounts
have only verification/resend/logout access. HTTP, Livewire, upload/preview,
download, actions and policies enforce this alongside suspension/access-version
and stale-session safeguards. Email changes clear verification and revoke old
sessions/remember tokens. No production backfill or demo setup is provided.

Mail URLs use trusted APP_URL; production requires HTTPS. Generated setup/reset
secrets stay out of request paths/queries, logs and validation flash data. Do not
enable request-body/APM capture for auth POSTs. Account messages reject log and
failover mailers. Use array capture in tests and real SMTP in production. Reset
links now use `/reset-password#token=...&email=...`; request a fresh reset email
for any outstanding old path-style link at cutover.

## Private SMTP and Admin handoff

Approved sender and sole external test recipient: `mohamedashikcool2@gmail.com`.
Only one SMTP preparation test and one existing-Admin verification message are
authorized. Other tests use isolated capture. Gmail App Passwords require 2-Step
Verification and can be restricted by account policy:
<https://support.google.com/accounts/answer/185833>.

Enter the App Password only in the prepared hidden local console; never paste or
commit credentials or account links. The operator uses pinned SSH and process-only
SMTP configuration on the existing release. It does not change persistent mail,
APP_KEY, Admin password or policy. Its private ledger reserves each send before
execution; uncertain outcomes must be inspected, never automatically repeated.
SMTP acceptance is not inbox delivery. Receive the genuine verification message,
follow it while signed in as Admin, then confirm the saved verification timestamp.

The new release resumes a valid canonical signed verification link after login
only when it belongs to that same account and current email. Other destinations,
expired/altered links and foreign hosts are discarded. Login itself never marks
email verified. The old live release requires reopening the email link after login.
An observed live rejection was caused by a copied closing parenthesis appended to
the signature; strict rejection is retained. Use the original email button or the
exact unmodified link, and never share the link in chat.

Future private settings (not applied):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_REQUIRE_TLS=true
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=REPLACE_OWNED_GMAIL_ADDRESS
MAIL_PASSWORD="REPLACE_PRIVATELY_WITH_APP_PASSWORD"
MAIL_FROM_ADDRESS=REPLACE_SAME_OWNED_GMAIL_ADDRESS
MAIL_FROM_NAME=TaskBridge
```

Leave MAIL_URL unset; keep certificate verification enabled. Synchronous SMTP has
a 10-second timeout, with no background worker requirement. Google sending limits
and filtering remain external constraints.

## Verification

Use an isolated checkout/key/database with APP_ENV=testing, SQLite `:memory:` and
array cache/session/mail; never local, production or probe credentials. Install
lockfiles, build assets and run `node --test tests/JavaScript/*.test.mjs`,
`vendor/bin/pint --test` and `vendor/bin/phpunit`.

Tests cover valid/expired/tampered/substituted/replayed/resent links, real CSRF,
delivery rollback, password rules, preservation, roles and private/stale/suspended
entry points. Loopback SMTP accepts reserved `.test` recipients only and deletes
captures. Independent processes race on one token in disposable SQLite; CI repeats
that race on its disposable MariaDB 11.8 service with synthetic CI-only credentials.
This does not repeat completed host qualification or unchanged dependency audits.
Actual conclusions belong in `email-first-registration-verification.md`.

## Deployment gates and sequence (not executed)

1. Require reviewed PR/successful final CI. Build from the approved merge revision
   using the existing `deployment/alwaysdata/prepare-release.ps1` in a new directory.
   Review exact revision, locked no-dev dependencies, assets, secret exclusions,
   archive hash/size and proposed payload before later transfer approval.
   Destination: `/home/taskbridge-aasik/taskbridge/incoming/taskbridge-<revision>.tar.gz`;
   release: `/home/taskbridge-aasik/taskbridge/releases/<full-revision>`.
2. Preserve production APP_KEY, scoped TLS database, persistent private storage,
   Admin/password/history and local installation. Take a fresh encrypted off-host
   backup/integrity check before migration; retain previous release/site rollback
   configuration and completed restore evidence. Never upload recovery identities
   or import local demo accounts.
3. Establish working SMTP, actual external delivery and genuine existing-Admin
   verification before enforcement. Never use SQL/seeding to mark users verified.
4. Privately stage the new release with production/debug=false/trusted HTTPS URL,
   public-only document root, secure cookies and the existing protected shared
   environment/storage. Explicitly set `TASKBRIDGE_REQUIRE_VERIFIED_EMAIL=false`.
   Production defaults false if absent; local/tests default true. This staging
   switch preserves access during review; it is not mandatory enforcement.
5. Run only the additive migration against the confirmed production database:
   `php artisan migrate --path=database/migrations/2026_10_27_000001_create_pending_registrations_table.php --force`.
   Record migrate status; never fresh/reset/demo/seed. It adds the pending table
   and indexes without updating users. Prune expired pending records daily with
   `php artisan model:prune --model='App\Models\PendingRegistration'` (expiry plus
   one-day retention); this never deletes completed user accounts.
6. Configure SMTP privately/cache the new release, then run the read-only gate:
   `php artisan taskbridge:verification-readiness --admin-email=<confirmed-address>`.
   It checks the active verified Admin, HTTPS/debug, SMTP/TLS/private credentials
   and additive schema. It neither sends mail nor proves delivery. Stop on any
   failure or missing CI, delivery, Admin, backup or rollback evidence.
7. Obtain explicit enforcement/activation approval, then set the flag true,
   recache and rerun the gate with `--enforced`. Switch `current` atomically using
   the existing Free site/public root; preserve unrelated host resources.
8. Check HTTPS/redirect/assets/health, passwordless registration, restricted
   verification, real Admin login/role/session/CSRF, blocked private paths and
   sanitized logs. Do not create demo data. External new-user end-to-end mail
   requires additional recipient/send authorization beyond the two approved mails.

## Rollback

On critical failure enter maintenance first and preserve evidence/data. Restore
the prior `current` symlink, site configuration and cache. If mandatory verification
was enabled, keep public access in maintenance until an approved fix preserves
that policy: the previous release cannot enforce it. Never silently reopen
unverified access. Keep the additive table and newly completed users during code
rollback. Dropping the table invalidates pending links and requires data review.
Database restore is only for confirmed data damage under its guarded procedure;
never restore an older database merely to roll back code. Preserve APP_KEY,
uploads, encrypted backups and both recovery-identity copies.
