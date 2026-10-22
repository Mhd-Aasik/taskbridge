# Role-aware login destinations

Discovery was checkpointed as `10085a1` (`Add freelancer profile publishing and client discovery`) on `feature/freelancer-discovery`. The login correction remains separate from that checkpoint.

## Confirmed cause

Laravel records a guest's protected-page request in the current session's `url.intended`. The Volt login component previously called Livewire's `redirectIntended()`, whose installed implementation pulls that stored URL before using the dashboard fallback. Successful Client authentication could therefore navigate to `/freelancer/projects`; the Freelancer role middleware correctly returned 403. The same defect was reproduced for a Freelancer with a Client URL and an Admin with a Client URL. Authentication itself succeeded, explaining why returning through the navigation could then work.

The named `dashboard` route already selects `/client/dashboard`, `/freelancer/dashboard` or `/admin/dashboard` from the saved role, with unknown roles denied. Shared dashboard links already use this resolver. Logout already logs out the current guard, invalidates its session and regenerates its CSRF token. A stale or cached navigation link followed after logout can set a new intended destination; logout alone cannot determine the role of the next account to authenticate.

## Correction and preserved safeguards

After successful credential authentication and the existing session regeneration, login now consumes only `url.intended` and explicitly redirects to the named dashboard route. Livewire navigation remains enabled. All intended destinations are discarded, including another role's URL, a same-role URL, an external URL, a retired page and malformed stale session data. Other current-session values, including the account-access stamp, remain intact.

Credential validation, throttling, account-row locking, active-account checks, Login-event access-version validation, remember-token protections, session regeneration and logout behavior are unchanged. Direct wrong-role requests continue returning 403. Password confirmation and email-verification workflows are unchanged. The fix performs no global or cross-account session deletion.

## Automated and manual verification

`tests/Feature/Auth/LoginRedirectTest.php` covers actual guest requests before Client/Freelancer/Admin login, consumed destinations, the dashboard resolver and Livewire navigation effect, current-session regeneration and unrelated values, external/stale destinations, a Freelancer logout followed by a stale navigation request and Client login, direct wrong-role denial, suspended login/remember-token rejection, invalid credentials and unknown-role corruption limited to SQLite `:memory:`. A database-session test also checks that another account's two session records and remember token remain unchanged, using SQLite rather than the development MySQL session table.

Before the fix, all three cross-role redirect regressions failed with the observed wrong destinations. The MySQL/private-file baseline is recorded by an ignored read-only helper for this step; runtime session rows are accounted for separately. No schema migration is required.

`npm.cmd run build` passed after the Blade input changed. The known empty `app` chunk warning remains because the existing `resources/js/app.js` entry is empty. No browser preview was attempted. Real browser Back/Forward behavior, navigation caching and mobile/theme/keyboard review remain deferred in `manual-review.md`; automated navigation-effect tests do not claim those interactions were performed.

The affected run passed **72 tests / 667 assertions**, exit status zero, with zero failures/errors/skips. Evidence uses the `storage/logs/login-redirect-affected` prefix. This includes all new redirect regressions, the existing authentication folder, suspension/revocation, account-integrity, dashboard/navigation and theme-control checks. Repository-wide Pint and syntax checks for both changed PHP/Blade files passed.

A read-only comparison of all current columns/row hashes across 24 domain tables and private-file hashes matched `storage/app/private/login-redirect-baseline.json` after the affected run. No migration or real login/account mutation was performed by verification. Runtime sessions are excluded from the domain baseline and counted separately.

The subsequent complete SQLite `:memory:` suite passed **2,206 tests / 12,291 assertions**, with **zero failures, errors or skips**, exit status zero. The text, JUnit, testdox and exit-status records use the `storage/logs/login-redirect-full` prefix. The affected and full runs were sequential, and final implementation/test hashes match the inputs tested.

Final repository-wide Pint, syntax and whitespace checks passed. All 24 MySQL domain-table/account/profile hashes and private files still match the captured baseline after the full suite. Runtime sessions were checked separately: four rows, two authenticated. The login correction consists of four unstaged/uncommitted files: the login Blade component, its regression test, this document and the deferred manual-review checklist. Branch and discovery checkpoint remain `feature/freelancer-discovery` / `10085a1`.
