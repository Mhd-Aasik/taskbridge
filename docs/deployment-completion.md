# Deployment completion and handover

This is the current completion record for the approved Alwaysdata demonstration.
Earlier dated audit, installation and review records retain their original scope;
their pending deployment or optional-verification statements are historical.

## Live release and completed scope

- Public application: <https://taskbridge-aasik.alwaysdata.net>.
- Deployed application revision: `67c9ab6608114aedc6bb5b83528646c4ed83fb59`.
- [PR #2](https://github.com/Mhd-Aasik/taskbridge/pull/2) and
  [PR #3](https://github.com/Mhd-Aasik/taskbridge/pull/3) merged with history preserved.
- Final application-main [tests](https://github.com/Mhd-Aasik/taskbridge/actions/runs/40486920270)
  and [linter](https://github.com/Mhd-Aasik/taskbridge/actions/runs/40486920376)
  succeeded at that revision: **2,391 tests / 14,857 assertions**, plus two
  independent-process account-completion races on actual MariaDB 11.8.9.
  The race run reported 42 assertions; polling affects assertion counts.
- The local completion, managed demonstration, dependency-audit correction,
  host qualification, backup/recovery preparation and email-first release are
  complete within their recorded scopes. No local data was imported into hosting.

Production now enforces this sequence:

**Name/email/Client-or-Freelancer registration → setup email → password and
verification through a validated token/CSRF POST → login page → sign in.**

Registration and setup never authenticate automatically. GET never consumes a
token or verifies an account. Existing unverified users retain passwords/history
and use the guest verification/resend flow before normal login. Public Admin
registration remains forbidden. See [security details](email-first-registration.md).

## Host, artifact and preservation evidence

The authenticated panel confirmed the existing Free plan: 1 GB disk, 256 MB RAM,
0.25 CPU, with a pre-upgrade disk reading of 151.49 MB / 14.79%. The inspected
runtime was PHP 8.4.27 and MariaDB 11.8.9. Scoped production grants, required SSL
and active certificate-verified SQL TLS 1.3 passed before migrations. Earlier
MariaDB qualification and unchanged successful audits were reused.

The locked production archive was **9,533,693 bytes**, SHA-256
`cec466ccb9b1068057a1c5b9cce51e903e100197cb4594070ae8509f5851c8a8`.
It contains 41,370,006 logical extracted bytes / 7,784 files, patched Livewire 4.4.8
and verified frontend assets, excluding development dependencies, secrets,
configuration caches, databases and uploads. Actual new-release disk allocation
was 61,372 KiB; the TaskBridge tree measured 143,852 KiB. Filesystem allocation
does not replace provider-wide quota accounting.

Only the two intended token-table migrations ran: the production schema advanced
from 33 to 35 tables and 27 to 29 migration records. Every pre-existing
non-migration row/count/content/password/ownership fingerprint and both private-file
hashes matched the fresh snapshot. No seed, reset, backfill or bulk verification
was performed. The existing verified Admin, APP_KEY, database credentials,
private storage, local installation and recovery identities were preserved.

## Actual public, authentication and email checks

- Valid HTTPS hostname/certificate; HTTP-to-HTTPS 301; homepage, `/up`, login,
  passwordless registration and guest verification pages returned 200.
- Built CSS, font and account-link JavaScript matched the verified release bytes.
- Environment, Git, vendor, configuration, log and private-storage paths were
  blocked. A trailing-slash redirect was followed to its same-origin 404.
- Sessions were Secure, HttpOnly, SameSite=Lax and host-only. Missing-CSRF
  Livewire returned 419; a valid-session read-only component refresh returned 200.
- The owner signed into the existing verified Admin in the browser with its
  unchanged password. The active auth provider denied both existing unverified
  accounts under the deployed enforcement policy, without modifying or emailing them.
- Owned-sender Gmail SMTP authentication passed with required TLS and certificate
  validation. The earlier approved test and genuine Admin-verification delivery
  were reused; the Admin message was not resent.
- One separately approved setup message was sent to the owner's distinct test
  mailbox. Its genuine branded message was found in that recipient inbox.
  A private real-HTTPS operator verified read-only GET, verified setup POST,
  no automatic login, replay rejection (422), ordinary email/password Livewire
  login, Client role redirect/dashboard, other-role denial (403), and logout.
- The exact temporary test account and consumed token were then removed only after
  checking identity and absence of business/history dependencies. Other user rows,
  the verified Admin and private-file hashes were unchanged. No demo/business
  records were created. The one setup-message allowance is consumed.
- Final HTTPS health remained 200. The final sanitized log window contained no
  unexpected 5xx or new application error header; the replay/role 422/403 responses
  were expected. No raw security URLs, passwords, mail bodies or database rows
  are part of this public record.

These are scoped actual results. Setup/password/login were exercised by the
private HTTPS operator, browser Admin sign-in was observed, and served fragment
JavaScript was byte-verified against its existing passing isolated tests.
Other recipients' delivery and external password-reset delivery were not tested.

## Backup, rollback and provider runtime refresh

A fresh write-quiescent matched SQL/private-file/environment/revision backup was
encrypted to the existing public recovery recipient. Its 747,896-byte ciphertext
was downloaded to the owner's approved off-host location and remote/local hashes
matched. The existing encrypted identities and independently stored second copy
were preserved and hash-checked. Earlier actual isolated MariaDB restore/schema,
full-row-content and private-manifest checks were reused. Only confirmed temporary
plaintext backup intermediates were removed after preservation/download checks.

The first symlink switch still served the old HTTP registration flow, despite the
correct CLI revision. The reviewed rollback restored the old code/environment in
maintenance, retaining both additive tables and current data. The panel confirmed
one site only; after restaging under maintenance, a provider restart of that sole
TaskBridge site resolved the stale HTTP behavior. No execution limit or security
setting was weakened. Internal cache/path retention is an inference, not a proven
specific OPcache defect.

For subsequent deployments, retain maintenance while switching releases and
refreshing the provider web runtime. Verify actual HTTP behavior after restart;
a correct CLI symlink alone is insufficient. Alwaysdata Apache restart affects
all Apache sites in the account, so recheck scope if more sites are added. See
[the provider's restart documentation](https://help.alwaysdata.com/en/docs/web-hosting/sites/restart-a-site/).

Keep the previous release and protected environment available. On critical
failure, enter maintenance, restore the previous code/environment/cache and
retain additive tables and newer users/history/uploads. Old code does not enforce
the new login policy, so do not reopen unverified access without reviewed policy
resolution. Never restore an old database merely to roll back code.

The historical host `preflight.php` full-mail check assumes implicit TLS/465.
For this reviewed STARTTLS/587 deployment, runtime/extensions/assets checks used
its offline mode; actual scoped database/TLS and SMTP checks used the separate
live guards and `taskbridge:verification-readiness --enforced`, plus real SMTP
authentication/delivery. No passing full implicit-TLS preflight is claimed.

## Boundaries and ongoing operation

No essential private handoff remains for the approved deployment/test scope.
Detailed private execution evidence and recovery locations remain outside tracked
source. Passwords, environment files, recovery identities, backups and uploads
must stay outside Git.

Scheduled pruning/backup automation was not added; follow the documented manual
procedures and retention plan. No paid service or background worker was introduced.
Broader mobile/device/accessibility, load/crash and exhaustive concurrency matrices
remain coverage limits, not evidence of a confirmed application defect or blanket
approval. Deferred product features remain outside scope; see
[manual review](manual-review.md) and [workflow audit](workflow-audit.md).

The original local checkout was deliberately retained at its prior revision to
preserve the running local demonstration and configuration. The isolated feature
branch is preserved. Documentation-only follow-up revisions can differ from the
deployed application revision above and do not imply a new production rollout.
