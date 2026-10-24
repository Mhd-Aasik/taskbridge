# Final review checklist

Bootstrap is checkpointed as **`a87ef49` — Add safe first-admin bootstrap**. Sidebar and reactive-control fixes were checkpointed on `fix/final-ui-review`; the timeout investigation is **4761ad4**. Current final work is on **`fix/final-completion`**. Historical evidence below retains its date and scope.

**The final-completion request authorized isolated browser testing.** Earlier denials were respected at their checkpoints; they no longer describe the new explicitly authorized review. Current agent interaction used only the permitted in-app browser, a guarded disposable MySQL database/private storage, dedicated `.test` accounts and loopback server. No browser mutations used the real application database. Physical-terminal bootstrap and full device/accessibility sign-off remain pending.

## Current evidence — 24 October 2026

The final complete isolated suite passed **2,349 tests / 14,302 assertions**, zero
failures/errors/skips, exit 0. The latest frontend build and 13 JavaScript tests
passed. Current audit retrieval failed (Composer registry timeout and npm connection
reset, including a retry); earlier zero findings below are historical. See
[final completion](final-completion.md) for backup, intended real demo changes,
preservation, code fixes and exact automated scope.

Actual isolated browser evidence so far:

- Client role-aware login and logout; dashboard at desktop System/OS Dark and
  390px Light, open mobile menu and working project navigation.
- At 320px Light: readable draft fields without horizontal page overflow,
  retained invalid-budget input/error, actual cover upload, draft save/reload,
  owner review and confirmed publication with working cancellation focus.
- At 768px Light: account settings upload/preview/save/replacement/removal and
  refreshed initials/avatar; the discovered post-save enabled-button defect was
  fixed and replacement/removal states were exercised again successfully.
- Public TaskBridge home at desktop Light and its working login destination;
  Freelancer login, dashboard Light/Dark, discovery and covered project detail,
  actual proposal submission and read-only persisted offer. At 390px Dark,
  assigned-project/planning/version-history pages, task status update (1/3 to 2/3
  completed), private version download and open mobile menu worked without page
  overflow on the measured planning view.
- Disposable Admin login, desktop dashboard Light/Dark, account list, 390px Light
  categories and scoped dispute list/detail were reviewed. System followed the
  current OS Dark preference and persisted on reload; live OS-preference changes
  remain untested. The real database had no Admin; browser fixtures do not
  establish a real Admin login. Some navigation waits expired before the eventual
  destination appeared; no application cause was confirmed.
- Light persisted across refresh, Instrument Sans was loaded locally and checked
  pages had no CSS style-preload tags. A single keyboard Tab/focus check passed;
  this does not establish full keyboard, screen-reader or touch approval.

Browser evidence files remain local under `storage/logs/final-screenshots`; they
are not fabricated or committed private uploads. The full ordered checklist below
describes what must be exercised beyond these specific observations. Mark neither
all roles/themes nor whole workflows passed from a representative screenshot.

The supplied later preload evidence identifies `/build/assets/app-*.css`; the
redundant hints were reproduced and removed without suppressing console messages.
The Chrome `injection-topics.js` CSP error is user-confirmed extension-origin;
Edge `ai-drainage.js` remains unidentified. No CSP was weakened. The original
60-second timeout remains **not reproduced; cause unconfirmed**. A preliminary
isolated upload HTTP 500 was a corrected test-harness temporary-disk configuration
issue, separate from the application Alpine/control findings.

## Evidence already available

| Evidence | Actual coverage and limits |
| --- | --- |
| Earlier bootstrap complete suite | **2,330 tests / 13,994 assertions**, zero failures/errors/skips, exit 0 at that checkpoint; superseded by the current full suite above. HTTP/Volt tests are not browser approval. |
| Bootstrap | **16 tests / 81 assertions**; validation, hashing, optional verification, login/Admin dashboard, cancellation/non-interactive refusal, existing-Admin refusal, protected fields and rollback. Three genuine two-process MySQL rounds each produced one first Admin and one refusal. Prompt tests simulate a terminal; physical hidden-input rendering is unreviewed. See [bootstrap](admin-bootstrap.md). |
| Code/frontend checks | 13 JavaScript tests, repository-wide Pint, five new PHP syntax checks, whitespace, frontend build/manifest, Composer validation/platform checks passed. Locked Composer all/runtime-only and npm audits returned zero findings at that checkpoint. JavaScript tests use simulated geometry; source colour calculations do not prove rendered contrast or appearance. |
| Local email/setup/recovery | Actual loopback SMTP capture used only `.test` recipients and exercised captured links. Isolated locked installation, migrations/reference seeds, caches, automated HTTP smoke and 35-table/file-hash restoration passed. These do not prove external delivery, production restore or browser/email-client rendering. See [email](account-email.md) and [verification](handoff-verification.md). |
| Preserved real data | All 25 MySQL domain-table/private-file hashes matched the baseline; three accounts active at access version zero; `.env`/key/config caches unchanged. Runtime sessions were separately counted: four total, two authenticated. Disposable MySQL/capture resources were removed. |
| User-confirmed interactions | Freelancer browsing/filter/reset and account photo upload/replacement/removal/avatar refresh/persistence were confirmed. These limited confirmations do not establish all roles, devices, themes or keyboard paths. |
| Visual coverage | Client desktop Dark and Client mobile Light/Dark with the menu open were reviewed on an earlier dashboard iteration; later changes need current review. The user has now reported screenshot-based review of the corrected Freelancer desktop sidebar in Light and Dark: scrollbar appearance, account footer and logout visibility. Other-role current visual review and remaining Freelancer checks stay pending. |
| Additional mobile evidence | User-supplied mobile Light screenshots are recorded as static screenshot evidence only. They do not establish successful mobile menu/scrolling/keyboard interaction, System-theme behavior, photo-control interaction or the corrected appearance after the Alpine fix. Current mobile interaction and the other-role/theme matrix remain pending. |

Detailed evidence: [final verification](handoff-verification.md#bootstrap-and-final-automated-verification), [workflow audit](workflow-audit.md), [MySQL verification](mysql-verification.md). Older 2,108/2,306/2,314-test results are historical, not the latest suite.

Preparation checks on **23 October 2026** passed: 24 documentation files, 70 local links/anchors, 11 PowerShell example blocks, whitespace, the unchanged production manifest and verified bootstrap-source hashes. Read-only preservation again matched all 25 domain tables/private files and the original configuration; accounts/sessions remain as recorded above. Only this checklist changed. The unchanged full-suite/build/audit results are reused, not reported as newly run; no browser was opened.

## Review order (remaining work)

Record viewport/device, role/page, theme, actions, expected/observed behavior and evidence. Mark an item passed only after performing it; keep blocked/not-run items explicit. Shared evidence must exclude passwords, reset/signature tokens, message bodies, payment references and private paths.

For physical bootstrap review, run Artisan in a **separate temporary checkout configured only for the disposable connection and storage**. Starting the MySQL harness alone does not redirect this checkout's `.env`. Confirm resolved host/port/database/datadir/marker, exclude cached application configuration and database-URL overrides, and use the [guarded setup](mysql-verification.md) and [installation isolation guidance](installation.md#isolated-tests). Do not run creation from the running application's checkout.

1. **Isolation and physical-terminal bootstrap.** Establish a disposable database/storage with identity guards before creation. In an actual Windows/hosting terminal, check hidden password and confirmation without visible fallback, validation, default-No cancellation, displayed details and deliberate confirmation. Use only `.test` fixture addresses and unique test passwords. Confirm one unverified Admin can sign in, another attempt refuses, and existing participants remain unchanged; piped input/`--no-interaction` refuse. Record terminal/OS and clean up only marked resources. Automated prompt tests and MySQL concurrency do not complete this physical check.
2. **Shared shell, authentication and account controls.** Start with `/login` and each role's dashboard, logout then another role's login, stale intended destinations, Back/Forward and Livewire navigation. Check monogram/wordmark/tagline proportions, account/avatar/theme menu, role badge, dashboard/settings/password links and separate Freelancer profile link. Review name/email saves independently of photo Save/Remove, initials/preview/loading/errors and persistence. Check optional verification controls, password confirmation/recovery, remember-me, stale sessions and suspension/reactivation using disposable accounts. Wrong-role access stays denied; active counterparties retain their own access.
3. **Client workspace.** Review dashboard, **Your projects** (`/client/projects`), draft create/edit, owner review and proposal list/detail; then **Find freelancers** (`/client/freelancers`). Exercise cover choose/discard/preview/save/replace/remove, budget/deadline alignment, private-draft copy, publish confirmation, inactive-category feedback, filters/reset/sort/pagination, real counts and empty states. Check advertised/offer comparison, acceptance cancel/confirm/retry and assignment refresh. Include large LKR amounts, long escaped text, Sri Lankan dates and stale drafts; published briefs/agreed terms remain locked. Directory access reveals only eligible profiles, released reputation and consented photos, without direct hire.
4. **Freelancer workspace.** Review dashboard, `/freelancer/profile`, preview/publish/unpublish and separate photo-sharing consent; then **Browse projects**, details/proposal, **My proposals** and **Assigned projects**. Check skills/rate/URL validation, saved/private visibility, stale edits and fresh-request removal of directory/photo access after unpublishing/consent revocation. Exercise combined filters/reset/sort/pagination, discovery eligibility, advertised/offered amounts, completion-date limits, duplicate feedback/View your proposal, retained input and history after discovery closes. Include Not rated yet/released-only reputation and unspecified optional rates.
5. **Both participants' assigned lifecycle.** Follow the coverage table below: planning/tasks → deliverables/revisions/reviews → explicit completion → external payment/receipt → messages/notifications/activity → feedback/disputes. Check each role's controls/history and keep task progress, version approval, project completion and payment distinct. The [lifecycle tests](../tests/Feature/WorkflowLifecycleTest.php) and [user guide](user-guide.md) supply expected behavior, not manual-pass evidence.
6. **Admin workspace.** Review dashboard, `/admin/categories`, `/admin/accounts` with detail/audit history, `/admin/disputes` and scoped context/downloads. Check filters/reset/sort/pagination, counts, category creation/editing/immutable slugs and activation impact/stale confirmations. On disposable participants, check suspension/reactivation target/internal-reason labelling, retries/stale confirmation, old-session denial, fresh login and preserved counterpart/history. Admins get no status controls for Admin targets, role editing, impersonation or general private project/photo access. Check case clarification, conflict-of-interest denial, permanent resolution/dismissal, and no participant cursor/unread changes from Admin viewing. Last-active-Admin deletion refuses before cleanup.
7. **Device/theme/accessibility and console sign-off.** Apply the matrix below throughout steps 2–6, then repeat affected pages after fixes. Check cancel/confirm/focus return, invalid/retained/loading/success states, long content and empty lists. Finally capture exact console/preload evidence. The limited Freelancer desktop screenshot coverage below does not complete a role's full visual/interaction review.

## Shared device, theme and interaction matrix

| Dimension | Pending checks |
| --- | --- |
| Desktop | Current Client dashboard System/OS Dark and Freelancer/Admin Light/Dark representative views were reviewed at 1280 × 800. Full-page/short/zoomed layouts and all-role scrolling interactions remain pending. Earlier sidebar screenshot evidence remains scoped to its date. |
| Mobile | Current 390px Client Light menu, Freelancer Dark assignment/planning/version history/menu and Admin Light categories/dispute views were reviewed; Client draft/review/publication was exercised at 320px and settings at 768px. Remaining widths/landscape, touch scrolling/dragging, all-role forms, long tables/pagination and full interaction coverage remain pending. |
| Light / Dark / System | All three choices were selected in representative views; Light and System persistence were observed on refresh, with System following the current OS Dark preference. Live OS changes, theme flashes, every-role/page combinations and complete logout/login persistence remain pending. Source contrast calculations remain separate from rendered checks. |
| Keyboard/assistive output | Tab/Shift+Tab order, visible focus, labelled controls, Enter/Space, dropdown Escape, modal initial focus/trap/cancel/focus return, skill/rating/status controls, mobile menu, errors/loading/success announcements and readable zoom. Attributes alone do not prove behavior. |
| Forms/uploads | Required/boundary/type errors, retained values, separate saves, pending-upload protection, previews/cache refresh, retry/network feedback, double-submit protection and escaped text. Photos/covers: genuine JPEG/PNG/WebP ≤2 MB, ≤4096 × 4096. Deliverables: allowed content ≤10 MB, long filenames, malformed/archive/unsupported rejection. CLI PHP limits do not establish browser/proxy limits. |
| Confirmations | Publication, acceptance, reviews, completion, payment/receipt, feedback, disputes, category/account status and notification mark-all: clear permanent effects/compared terms/target identity, cancellation without mutation, confirm/loading/retry, keyboard focus and announcements. |

## Assigned lifecycle details (step 5)

| Area | Manual assertions to retain |
| --- | --- |
| Planning/tasks | Client detail create/edit/cancel, Freelancer read-only details, both-party task status/reopening; assignment/milestone date bounds, overdue labels, deterministic grouping/order, overall/milestone progress despite filtering, stale retained input, completed-work locks/history. No invented percentages or automatic approval. |
| Deliverables/reviews | Optional milestone, upload progress, secure download, immutable versions; required change-request/optional approval remarks, latest-version stale refusal, revision note, superseded unreviewed versions, states/counts across pages, approval blocking only that deliverable's new versions. Completed history/downloads persist. |
| Completion | Client-only readiness links/counts, empty-milestone outcome note, all tasks including ungrouped Done, all latest versions approved, explicit milestone/project confirmation, active-dispute blocker, immutable time/note, no reopening or automatic payment. |
| External payment/receipt | Pending versus Client-reported Paid versus separate receipt acknowledgment; saved full agreed LKR amount, method/paid-on date/reference/note, permanent confirmation, original retry times, both-role history/links. No transfer, installments, corrections or refunds. |
| Messages | Both-party compose/send/validation/rate limits/retries; chronological/older/newer/unread navigation, scroll anchoring, arrivals/background polling, viewport-gated read acknowledgment, badges across navigation and historical access. No read receipts/attachments/edit/delete. Admin access only through authorized disputes, without cursor changes. |
| Notifications | Bell/mobile bounds, private generic previews, actor exclusion/eligible Admin recipients, All/Unread/pagination, read/unread/open/error states, safe inaccessible destinations, captured mark-all with new arrivals. Message cursors remain independent. |
| Activity | Client-only pre-assignment activity, assigned Freelancer's shared interval, historical/suspended-counterparty access, labels/names/Sri Lankan times, authorized links/pagination and honest empty state. No sensitive free text or backfill. |
| Feedback/reputation | Permanent 1–5 score/comment, radio keyboard access, exact Sri Lankan window, own history, blind counterpart until both submit or exact 14-day expiry, missing metadata, generic alerts, released-only role-specific aggregates/Not rated yet without private past-project links. |
| Disputes | Participant open/confirm/statement/filter/history, one active case, retained validation/retries/history after completion; Admin clarification/rationale/permanent resolution/dismissal. Active cases block new completion while work/payment/history continue; decisions do not transfer funds or rewrite terms. |

## Screenshot-based sidebar observations (23 October 2026)

The user reported two screenshot observations: an oversized bright-white native sidebar scrollbar in Light mode, and Log out absent from the same footer area where it appeared in Dark mode. These are screenshot-based reports, not browser interactions performed by this agent.

Source inspection found that installed Flux adds `overflow-y-auto` to the sticky sidebar while TaskBridge also scrolls its inner navigation. The outer sidebar can therefore scroll branding/footer along with its contents. Flux's native dark colour scheme is applied to `:root.dark`; the sidebar's local `dark` class alone does not set it in Light mode. Logout is present unconditionally as a POST/CSRF form, and no Light-only hiding rule was found. Its exact screenshot position/clipping and computed colours cannot be established without browser evidence; low contrast was not confirmed as the cause.

The shared fix constrains outer sidebar overflow, keeps the existing non-shrinking branding/account footer, and scrolls only the available navigation area. That native scroller has a thin navy/slate scrollbar, a dark colour scheme, keyboard focus and an accessible region label; no wheel/touch/key event interception is added. Logout uses explicit sidebar text/hover/focus colours and Flux's supported start alignment. Branding, typography, role links, mobile toggle/backdrop, logout endpoint and dashboard content are unchanged. Source-only ratios are **11.14:1** for `#cbd5e1` text on `#101f35`, and **3.48:1** for the `#64748b` thumb against the same track; these are not rendered/browser measurements.

**Screenshot review recorded:** the user reported review of the corrected **Freelancer desktop sidebar in Light and Dark modes**, covering the discreet scrollbar appearance, account footer and visible Log out control in both screenshots. This records screenshot-based visual review, not browser interaction or a logout click performed by this agent.

Still **pending**: mobile/menu-open layouts, keyboard/focus, mouse/touch/scrollbar-drag scrolling, System theme/OS changes, short/zoomed viewports and current Client/Admin visual checks. Confirm branding/footer/logout remain reachable during actual navigation scrolling, theme changes and menu open/close, and exercise logout separately. Other Freelancer pages and the full device/theme matrix remain pending. The existing affected-test/build results are reused because the sidebar markup and CSS are unchanged; no new browser access or full-suite run is claimed.

Affected existing checks passed **56 tests / 658 assertions**, zero failures/errors/skips: dashboard rendering, navigation, theme controls, login redirects/authentication and logout across roles. `npm.cmd run build`, the production manifest/scoped stylesheet inspection, repository-wide Pint, changed Blade compilation/PHP syntax and whitespace checks passed. No class-name-only tests were added. Existing database/private-file and configuration preservation checks passed; no account, route, authorization or logout action changed. The earlier pending checklist is preserved alongside these new observations.

At this sidebar check, runtime sessions were separately measured as **five total, three authenticated**, rather than the earlier checkpoint's four/two. Session IDs/payloads were not inspected or revoked. Domain/file hashes still match, all three accounts remain active at version zero, and the running configuration remains unchanged.

## Photo controls and console evidence (23 October 2026)

**Confirmed application Alpine error:** the supplied screenshot shows `Invalid or unexpected token` for `uploading || @js(! $account->avatar_path)` on the photo Remove button. This is an application template defect, separate from the extension finding. Source inspection and isolated HTTP/Volt-rendered HTML reproduce literal Blade syntax in the forwarded Flux attribute; Node evaluation fails with `SyntaxError`. The project draft Save button has the same confirmed defect with `@js($categories->isEmpty())`. A retained console entry after settings-to-dashboard navigation does not establish that the dashboard generated it.

The focused fix uses locked, server-derived Livewire booleans (`hasSavedPhoto` and `hasActiveCategories`) in valid `$wire` expressions, with supported server-side `:disabled` binding. Each render derives these values from authorized current records. No private photo path is serialized into either boolean or binding. This avoids freezing the initial state after uploads, replacements, removals or later refreshes. Existing loading attributes, validation, authorization, storage cleanup, CSP and account/profile behavior are preserved. The remaining `@js` uses are on native avatar HTML attributes; actual rendered photo/settings HTML contains no literal directives.

Before the fix, **two isolated rendered-HTML tests failed** JavaScript evaluation. After the fix, **nine rendered-binding tests / 208 assertions passed**, including all three roles, initial/existing photos, actual upload event handlers, validation/storage failures, successful replacement/removal, subsequent Livewire snapshots and refreshes, locked-state tampering and category availability changes. The evaluator compiles initial rendered bindings once and applies subsequent server snapshots, checking against stale initial state. Node execution is not Alpine DOM/browser interaction.

Final affected verification passed **136 tests / 955 assertions**, zero failures/errors/skips, exit 0: the new binding regressions plus existing account photo, profile/password settings, Client draft editor and cover-saving tests. The existing **13 JavaScript tests**, evaluator syntax, repository-wide Pint, both compiled Blade templates/new PHP test syntax and whitespace checks passed. `npm.cmd run build` passed with one nonempty CSS manifest entry (`app-BxufKdqY.css`), without a redundant JavaScript chunk. The earlier complete-suite result remains a checkpoint result; no new full-suite run or browser execution is claimed.

Read-only preservation matched all **25 MySQL domain tables and private-file hashes**. All three accounts remain active at access version zero, and `.env` (including the key) and every bootstrap/cache file match their starting hashes. Runtime sessions were counted separately as **one total, one authenticated** at both ends of this check; earlier sidebar session counts describe an earlier runtime snapshot. No migrations, real-account/file changes or configuration changes were made.

**User-reported browser follow-up:** the user reports a **clear console after a fresh Incognito load of Profile settings**. This is evidence for that load only, not browser interaction performed by this agent or approval of every role/theme/upload state. **Client project-editor browser verification remains pending**, despite its passing automated rendered-binding tests.

**Extension-origin CSP error:** the normal-browser extension error remains a separate finding. Capture its exact message/source and reproduction conditions when browser review is authorized; do not attribute the confirmed application Alpine error to the extension. No CSP weakening or console suppression is part of this fix.

At this historical checkpoint the **unused-preload URL was still missing**. Later final-completion evidence identified `/build/assets/app-*.css` and reproduced redundant CSS hints; the current fix/evidence is recorded above. The empty JavaScript chunk was a separate already-corrected issue. Broader Chrome/Edge console verification remains pending; no warning suppression or meaningless JavaScript is used.

**Deferred browser checks:** inspect the corrected settings controls in each role/theme and mobile Light layout; exercise no-photo/existing-photo state, choose/upload/error/cancel/save/replace/remove and repeated navigation/refresh, including loading protection and visible feedback. Check console origin with preserved logs and a fresh page separately. Mobile scrolling/menu, touch/keyboard/focus, System theme and remaining role visuals are still pending. No browser, preview or alternative automation was opened for this fix, and untested interactions are not marked passed.

For this checkpoint, only the follow-up documentation changed after the successful checks above. The unchanged application/test inputs reuse those test, JavaScript, build, Pint and syntax results; whitespace is checked again before committing.

## Intermittent Livewire delay investigation (24 October 2026)

The user reports that the earlier 60-second Livewire timeout has cleared and the application works again. Investigation outcome: **not reproduced; cause unconfirmed.** No application code, server setting or configuration correction is supported by the available evidence.

Read-only Laravel log inspection found one `Maximum execution time of 60 seconds exceeded` entry at **23 October 2026, 19:09:09 UTC / 24 October, 00:39:09 Asia/Colombo**, pointing to Composer's `ClassLoader.php:429`. That is where the execution limit was encountered, not proof of an autoloader defect. The entry supplies no useful call stack or per-operation timings to identify the delayed Livewire action, database query or file operation.

The active listener is PHP's local development server on `127.0.0.1:8000`. Three sequential, unauthenticated, cookie-free GETs to the existing `/up` route returned **HTTP 200**, taking **1.130 s, 0.489 s and 0.532 s** total. The health route is outside the web/session middleware group; each probe used a three-second connection bound and ten-second total bound. These were health-only timing checks, not browser access, page previews or a replay of the failed POST.

A separate CLI sample measured **60.403 ms** for Composer autoload, **485.191 ms** for application bootstrap, **15.472 ms** for MySQL connection, and **0.328 / 0.233 / 0.224 ms** for three `SELECT 1` reads. These describe current startup/connectivity, not the failed request or authenticated Livewire rendering. MySQL **8.0.43** reported zero slow queries since its current startup; its active error log had no entries within five minutes of the incident, and the inspected slow log had no new incident-time entries. These observations do not exclude earlier contention or cumulative short operations.

Lock-wait inspection is unavailable to the restricted application account: a diagnostic read of `performance_schema.data_lock_waits` was denied. That diagnostic exception is separate from the original timeout; no privileges were changed and the query was not repeated. The configured PHP error-log file is absent, Apache's inspected logs predate the incident and are not this PHP listener's request log, and no useful development-server request trace was available from the inspected task terminal. There is insufficient historical evidence to attribute the delay to SQL locks, PHP autoloading, session contention or host load.

No POST was replayed, no pasted cookies or authenticated requests were used, and no execution limit, sessions, running services or caches were changed. Before/after read-only comparisons matched all **25 domain tables and private-file hashes**; `.env`/key and bootstrap cache hashes/file lists remain unchanged. Runtime sessions were separately counted as **one total, one authenticated** at both ends. The original timeout remains the only 60-second timeout log entry after these checks; the denied diagnostic read is logged separately. The successful unchanged application tests/build/Pint/syntax results above are reused; only this investigation record changes. Browser/manual checks remain deferred. If the delay recurs, useful evidence would be the exact Sri Lankan timestamp, route/action without request secrets, matching server timing and exception frames; the earlier preload resource URL and extension-origin console finding remain separate unresolved evidence.

## Remaining non-browser/environment coverage

Completed genuine MySQL scenarios are scoped, not blanket concurrency approval: competing acceptance/forged ownership, duplicate submission, revision versus latest review, completion versus a new unfinished task, reset-token single use, password/suspension revocation before stale writes, transactional upload rollback/file cleanup, and first-Admin contention. The workflow harness passed 42 scenario executions plus two rollback checks; bootstrap passed three additional rounds. See [exact assertions and limits](mysql-verification.md#synchronization-and-assertions) and [bootstrap](admin-bootstrap.md).

Still pending targeted MySQL coverage includes feedback, category edits/activation, profile visibility/consent/photo changes, milestone/task edits/moves/status cycles, cover replacement versus publication, version allocation/retries beyond the tested review race, payment/receipt combinations, disputes, message/read cursors, notification read/deduplication/deletion, simultaneous Admin deletion and broader activity-event domains. Some notification/activity assertions passed in the completed races; that does not complete every combination. Existing SQLite interleavings remain simulations. These are remaining environment coverage, not a mandate to expand testing or rerun successful scenarios against real records.

The earlier non-browser checklist reconciliation reused unchanged code/build/audit results and found no application defect at that stage. The later screenshot-confirmed Alpine defect and its new verification are recorded above. No schema change, seed, bootstrap creation or disposable service restart is needed for the photo-control fix. Broader concurrency/load/crash coverage stays explicitly pending.

## Pending deployment verification (separate from UI review)

- **External SMTP:** provider credentials, authorized sender/SPF/DKIM/DMARC, authentication/TLS, trusted public HTTPS links, external delivery/inbox placement and email-client HTML/plain-text/action/fallback rendering. Local log/loopback capture does not prove these. Verification stays optional; no new queue worker.
- **Hosting:** public-only web root, private-volume persistence/protection, writable directories, APP_URL, HTTPS/proxy/cookies/CSRF/sessions, actual web/proxy upload limits and any malware policy. See [hosting](hosting.md).
- **Production recovery:** encrypted/off-host retention, real-key recovery, consistent live backups, protected restore/cutover and recovery objectives. Disposable synthetic restoration is not production recovery. See [backup/recovery](backup-recovery.md).

Distinguish existing-scope defects from deferred features. Cancellation/reassignment/reopening, proposal editing/withdrawal/individual rejection, milestone/task deletion/reordering, payment processing/refunds, message attachments/WebSockets and Admin role editing/impersonation remain outside implemented scope. See [user guide](user-guide.md) and [workflow audit](workflow-audit.md).
