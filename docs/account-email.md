# TaskBridge account emails

Current live status is recorded in [deployment completion](deployment-completion.md):
revision `67c9ab660811` is deployed with verified SMTP, mandatory verification before
login, preserved Admin/data and a passed-and-cleaned genuine signup test. Dated
pending statements below retain their original historical scope.

The email-first release supersedes historical optional-verification behavior below.
See [the current release review](email-first-registration.md): new users finish
password setup and verification before login; existing unverified users use a
guest-only token/CSRF POST flow. No signed GET may verify an account.


For the 25 October university hosting preparation, see the
[Alwaysdata SMTP plan](alwaysdata-free.md#zero-cost-smtp-preparation): included
host SMTP with an authorized owned mailbox, or an explicitly confirmed personal
Gmail sender eligible for a dedicated app password. Credentials remain private;
sender/recipient confirmation, actual external delivery and real HTTPS reset-link
verification are still pending. Neither local demo Gmail identity is assumed owned.

## Scope and checkpoint

MySQL verification was checkpointed as `12f3bdc` (`Add isolated MySQL concurrency verification`). The eight harness scripts, two documents and successful result records were reviewed; credentials, messages, runtime logs and disposable files were excluded. Account email work is on `feature/account-email`, intentionally unstaged/uncommitted.

The running application's `.env`, mail credentials and mail configuration are unchanged. No migration, demonstration account, external email, publication or deployment is needed. Existing accounts keep their permissions. `User` still does not implement `MustVerifyEmail`: registration emits its normal Registered event but does not automatically send verification mail or require verification. Unverified owners can explicitly request optional verification from their account Profile page or the existing notice. Updating their email clears `email_verified_at`; old-address links fail the current email-hash check. No automatic mail is sent on email change.

## Presentation and links

Existing framework `ResetPassword` and `VerifyEmail` notifications remain synchronous; no queue worker is required. Their supported URL/presentation hooks use `AccountEmailLinks` and the shared `emails.account` Markdown view. TaskBridge wordmark/subject/footer, clear action, fallback URL, expiry and unexpected-request guidance appear in responsive framework HTML and plain text. No photo, role, profile or project data is included. The reset URL retains the email query field required by Laravel's broker; delivery addresses and security tokens are necessarily present in the mail envelope/link.

Reset expiry reads the selected password broker's `expire` value (`config/auth.php`, currently 60 minutes). Verification uses `auth.verification.expire` with the existing 60-minute default. These same values generate the links and their visible expiry copy. Password-reset token validation/consumption, access-version increments, remember-token rotation, session revocation and suspension preservation remain in `ChangeAccountPassword`.

Account email links use a dedicated URL generator bound to **configured `APP_URL`**, not the incoming request host or proxy headers. Only an HTTP(S) origin without credentials/query/fragment is accepted; configured port/base path is retained. This does not globally rewrite application navigation. Verification remains signed, expiring and authenticated for the correct account/current email. Reusing an already successful verification link is idempotent while valid: it redirects through the role-aware dashboard without rewriting verification time or firing a second effective verification. It is not a consumed reset token. Verification links issued for the same unchanged address remain usable until expiry; a resend does not revoke every earlier signed link.

Verification now locks/reloads the persisted account and rechecks the URL's current email hash before marking it verified. Previously a User loaded before an email change could pass request authorization using the old address and subsequently mark the changed address verified. The stale-authenticated-User regression is an isolated simulated interleaving, not a new parallel MySQL verification test. The account lock agrees with profile changes and revocation; completed verification retries preserve the original timestamp.

Verification requires login to the matching account. The existing login resolver deliberately consumes intended destinations and goes to the role dashboard; if a verification link was opened while logged out, log in and reopen the email link. This preserves the existing safe role-aware login behavior. Password-reset pages remain guest-only.

## Request limits and SMTP errors

`SendAccountEmail` applies a shared cache-backed **five password-reset requests per IP per minute before account lookup**; the broker additionally retains its per-address 60-second throttle. Known, unknown, throttled and transport-failure reset requests display the same generic status. Response timing is not constant-time: local/provider latency can differ, so this is not a claim to eliminate every timing side channel. Configure trusted proxies correctly before relying on client IP limits in hosting.

Verification requests share a **one-attempt-per-account-per-minute** limit across the notice and account Profile pages. The action resolves the current authorized account server-side. Throttling/failure produces an inline `verification` error; stale success flashes are cleared before attempts. Transport exceptions expose neither provider details nor tokens. Reset errors retain the generic response to avoid enumeration. Other programming/database errors are not silently swallowed. Failed delivery can leave a newly generated reset token subject to the normal one-minute broker throttle; the owner can retry later. No verification/account state is changed by a send failure.

The cache backend must be shared by application processes for cross-process limits (the existing deployment configuration is retained). Array cache in isolated tests verifies semantics, not a distributed throttling deployment. SMTP/provider monitoring must use sanitized operation metadata; do not log exception payloads, full bodies or security URLs.

## SMTP setup for a future environment

These are placeholders to fill privately in the environment; they were **not applied** to the running application:

```dotenv
APP_NAME="TaskBridge"
APP_URL=https://app.example.test
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.provider.example
MAIL_PORT=587
MAIL_USERNAME=REPLACE_PRIVATELY
MAIL_PASSWORD=REPLACE_PRIVATELY
MAIL_FROM_ADDRESS=accounts@your-owned-domain.example
MAIL_FROM_NAME="TaskBridge"
```

Use the provider-authorized sender address/domain, with SPF/DKIM/DMARC and any sender verification required by that provider. Sender address/name come from the existing `mail.from` configuration; the template does not override an administrator's sender identity. Do not commit real credentials or paste them into tool output.

The installed Laravel SMTP configuration uses **`MAIL_SCHEME`**, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, optional `MAIL_EHLO_DOMAIN`, and optional **`MAIL_URL`**. `MAIL_ENCRYPTION` is not read by this project's `config/mail.php`. The installed Symfony transport supports:

- `smtp` on the provider's submission port (commonly 587), with automatic STARTTLS when advertised. Automatic negotiation alone is not a guarantee that TLS is required.
- `smtps` on the provider's implicit-TLS port (commonly 465).
- If the provider requires STARTTLS enforcement, the supported DSN option `require_tls=true` can be supplied through `MAIL_URL`, for example `smtp://USERNAME:PASSWORD@smtp.provider.example:587?require_tls=true`. URI-encode credentials privately. `MAIL_URL` can override individual settings; avoid leaving a stale URL configured. Never disable certificate validation for public SMTP.

For local development, `APP_URL` must match the scheme, host, optional port/base path actually used to access TaskBridge (for example `http://taskbridge.test` or `http://127.0.0.1:8000`). For hosting, use the canonical public HTTPS origin, correct proxy/HTTPS configuration and a stable application key. Wrong origins break signed-link usability; changing the signing key can invalidate links. Do not derive the origin from an arbitrary inbound Host header.

After changing environment settings in the intended environment, refresh configuration deliberately:

```powershell
php artisan config:clear
# For a deployed environment that uses configuration caching:
php artisan config:cache
```

These are documentation commands, not commands executed against the running application's configuration during this work. Restart any long-lived application processes as appropriate. Account notifications themselves remain synchronous.

`MAIL_MAILER=log` records messages locally; it **does not deliver externally** and may store sensitive tokens/URLs in logs. Protect those logs and never commit them. `array` captures only in process. A local SMTP capture proves the application's actual SMTP serialization/transport and link construction, not DNS, provider authentication, TLS certificates, external delivery, inbox placement or spam handling.

## Automated verification and local capture

`AccountEmailTest`, `AccountSmtpTest` and existing reset/verification/recovery/settings/suspension tests cover generic responses, both limits, expired/tampered/reused links, current-email invalidation, optional access, role-aware redirects and safe failures. Existing recovery coverage preserves reset-token single use and revocation semantics.

The actual SMTP test starts a temporary PHP socket sink on an OS-assigned **127.0.0.1** port. The sink accepts only reserved `.test` senders/recipients, has no outbound connections, AUTH or relay implementation, bounds reads and stores temporary MIME files only under a unique ignored testing directory. Process-scoped Laravel SMTP config uses no credentials/TLS, only that loopback service. SQLite `:memory:` fixture users are never persisted to MySQL.

The test sends framework notifications via the actual Symfony SMTP transport, validates sender, recipient, subjects, multipart HTML/plain-text, expiry, fallback and trusted URLs, then exercises captured reset and verification URLs via automated HTTP/Volt. Stopping the sink exercises genuine connection-refusal handling for both verification and recovery. A separate fake-dispatcher test verifies that internal transport details are not disclosed. Captured messages/URLs/tokens are never printed; `finally` stops the process and deletes every capture/readiness file and directory. Test messages are not committed.

One complete run finished with **2,312 passing tests and one SMTP-test failure**, caused by the test's decoding of MIME headers as quoted-printable body data. A random hex-prefixed boundary could be corrupted. `CapturedAccountEmail` now parses unfolded headers unchanged, splits the raw boundary and decodes each body by its declared transfer encoding. A deterministic folded/hex-boundary regression plus actual SMTP checks passed **8 tests / 109 assertions**, exit zero (`storage/logs/email-parser-targeted.*`). The fixture explicitly reloads the User before clearing verification state for closed-port checks. These are test fixes; the application did send valid multipart mail in the failed parser run. That failed run is not the final passing result.

The affected run passed **41 tests / 325 assertions**, exit zero, with no failures/errors/skips (`storage/logs/email-targeted-complete.*`). It includes actual SMTP capture, both MIME bodies, configured subjects/sender/recipient, usable plain-text fallback, captured links, real closed-port failures and the stale-email regression. Earlier test assertion problems (reading a flash after Livewire had consumed it and counting the fake's outer notification-type map instead of sent notifications) were corrected. The sink's idle bound was increased to accommodate cold Blade compilation; no application transport rule was weakened.

Source review also corrected the newly exposed optional controls' legacy grey/green styles to existing TaskBridge muted/teal styles, visible keyboard focus and theme-aware success feedback. The resend control explicitly uses `type="button"` so it cannot become the Profile form's default submission. Earlier full runs were explicitly interrupted before those final interface corrections and are not counted as successful; their processes ended before subsequent runs started. Runs never overlap. Final verification uses new text/JUnit/exit records and final input hashes. Browser appearance is still unverified.

Final complete SQLite `:memory:` verification passed **2,314 tests / 13,913 assertions**, with **zero failures, errors or skips**, exit status zero, in 685.59 seconds. Text summary, JUnit and captured exit status agree (`storage/logs/account-email-full.txt`, `.xml`, `.exit.txt`). All **12 final implementation/test source hashes** match the completed run (`account-email-final-inputs.json`); subsequent edits only finalized documentation.

Repository-wide Pint, all **12 PHP/compiled Blade syntax checks**, tracked/untracked whitespace checks and `npm.cmd run build` passed. The production manifest retains one nonempty CSS entry and no empty JavaScript chunk. Final evidence is under ignored `storage/logs/account-email-*`; no report or generated asset is staged.

Actual SMTP verification includes two locally captured messages (one reset and one verification) in the final SMTP test, with configured sender/recipient, subjects, both decoded bodies and usable action/fallback links checked. The captured links were exercised through HTTP/Volt and closed-port failures tested for both sends. The process and capture directory/messages are gone after completion; an explicit process/directory check found zero remaining capture resources.

The final read-only baseline comparison matches all original rows/columns in **25 MySQL domain tables** and all private-file hashes (**one avatar, zero cover/deliverable files**). All three real accounts remain active at access version zero with null suspension metadata. Runtime sessions are measured separately: **four total, two authenticated**. No migration, real account change or real mail occurred. The running mail transport is still `log`, and verification remains optional. See ignored `account-email-preservation.txt` / `account-email-environment.txt` for sanitized evidence.

HEAD remains `12f3bdc` on `feature/account-email`. The **14 verification/implementation/documentation files remain unstaged and uncommitted**. External provider delivery, provider authentication/TLS/DNS and browser/email-client rendering remain pending; none is established by the local sink. No browser preview was retried.

## Changed files (14)

- New services: `app/Actions/Accounts/SendAccountEmail.php`, `app/Support/AccountEmailLinks.php`.
- Framework hooks and persisted-state verification: `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Auth/VerifyEmailController.php`.
- Presentation and controls: `resources/views/emails/account.blade.php`, `resources/views/livewire/auth/{forgot-password,verify-email}.blade.php`, `resources/views/livewire/settings/profile.blade.php`.
- Tests: `tests/Feature/Auth/{AccountEmailTest,AccountSmtpTest}.php`, `tests/Support/{CapturedAccountEmail,smtp-capture}.php`.
- Documentation: this file and `docs/manual-review.md`.

No environment credentials, generated MIME, logs or runtime artifacts are included.
