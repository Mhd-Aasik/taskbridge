# Freelancer visibility and Client discovery

The account-management checkpoint is `1b3541f` (`Add admin account management and suspension`). This feature is developed on `feature/freelancer-discovery`. Automated verification uses SQLite `:memory:` and fake private storage; manual review stays deferred.

## Visibility and ownership

New professional profiles remain private. `is_public` now means **Visible to Clients**, not anonymous internet access. Only an active Freelancer may preview or explicitly publish/unpublish their own saved profile. The preview is `/freelancer/profile/preview`; the existing editor contains the visibility actions. Opening either page does not create or publish a profile.

Editing and visibility changes resolve the owner from authentication, lock the account before its profile, and recheck persisted access, role and ownership. The confirmation's locked revision includes normalized saved attributes and skill membership. A guarded server-managed `profile_version` counter detects visibility cycles even within the same timestamp second. A changed saved profile must be reloaded and previewed before its visibility changes; stale ordinary edits are also refused with input retained. Requests already at their desired state are harmless retries. Ordinary profile saving cannot change ownership, visibility, photo consent or publication time. Saving details on a published profile makes those changes visible to Clients.

Publication validates the saved professional fields with the same reusable rules as the editor: meaningful headline up to 120 characters, biography up to 2,000, optional location up to 120, an optional HTTP/HTTPS portfolio URL up to 2,048, an optional non-negative LKR rate within DECIMAL(12,2), and 1–10 distinct existing skills. Draft form input cannot replace those saved values in the publish action.

The additive migration adds `first_published_at`, `share_account_photo`, and `profile_version`. The first successful explicit publication records UTC time. Retries and unpublish/republish preserve that first time, so republishing does not bump discovery order. Existing visibility is not changed or inferred by migration; any legacy visible profile with an unknown first publication time sorts after known publication times.

## Client discovery and filters

Named routes `client.freelancers.index` and `client.freelancers.show` require an active authenticated Client. `FreelancerProfile::visibleToClients()` is shared by listing, direct details, proposal profile links and shared-photo authorization. A profile must be published and belong to an active Freelancer. Private, missing, suspended and wrong-role targets return 404 through direct discovery URLs. Other viewer roles have no access, including Admins.

Search matches display name, headline and biography; location is a separate literal substring search. SQL `LIKE` wildcard characters `%`, `_` and the escape character `!` are treated literally. Skill filtering matches **all** selected skills. Skill IDs must exist, be distinct, and number at most ten. Search/location strings are bounded to 150/120 characters.

Optional minimum/maximum hourly rates use the established decimal-string LKR rule and decimal-safe comparisons. They accept zero, at most two fractional digits, and at most LKR 9,999,999,999.99. Inverted ranges are rejected. Profiles without a rate are included when neither bound is supplied and excluded when a rate bound is supplied. Zero is displayed as `LKR 0.00`; null is `Not specified`.

Whitelisted sorting supports first publication newest first, name, hourly rate in either direction, and highest released rating. Unknown rates and unrated profiles sort last for their corresponding sorts. Profile ID descending supplies deterministic ties. Pagination uses ten profiles, bounds page numbers to 1–10,000, retains validated filters and resets to page one when the GET filter form is submitted. Invalid filters return 422 without a results query. Clear filters returns to the unfiltered first page.

## Presentation and reputation privacy

Client pages receive an explicit array whitelist, not hydrated target User models or editable Livewire target state: profile ID, display name/initials, headline, biography, skills, optional location/rate/portfolio, released reputation aggregates and an authorized photo URL when consented. Target account email, password, tokens, avatar paths, notifications and private project history are never selected for this presentation. Biographies and all other user text are escaped; line breaks are preserved. Legacy invalid portfolio schemes are omitted, valid links use HTTP/HTTPS with `noopener noreferrer nofollow`, and no external preview is fetched.

Reputation ordering and display reuse `ProjectReputation::aggregateQuery()` and the existing released-only, participant-role scopes. The aggregate join and eager-loaded skill query avoid per-profile queries. Neither blind feedback nor feedback excerpts, reviewer identities or past-project identities enter discovery presentation. Profiles with no released Freelancer ratings say `Not rated yet`.

Client navigation/dashboard link to Find freelancers. Proposal review shows View profile only while that applicant is eligible. A profile becoming private or suspended does not erase proposal or assignment history. The working Post a project link leads to the existing draft workflow; assignment continues through submitted proposals, with no direct-hire action.

## Separate photo consent

`share_account_photo` defaults false and is protected from ordinary mass assignment. The owner must explicitly save **Show my account photo to Clients** independently of their professional details or publication. The editor explains that replacing an account photo updates its shared version while consent is enabled.

`client.freelancers.photo` is a separate authenticated image endpoint: an active Client must request an eligible published profile with current consent. It reuses account-photo path ownership, MIME checks and private streaming. The owner-only `account.photo` policy remains unchanged. Images are private, `no-store`, vary by Cookie, use `nosniff` and a sandboxed image response; conditional requests do not bypass authorization. Subsequent requests after unpublishing, consent withdrawal, owner suspension or wrong-role changes are refused. A replacement is read from the current private path on the next image request. Missing/removed photos use the initials fallback, and private storage paths never appear in Client HTML or JSON.

## Verification boundaries

MySQL migration verification compares original domain columns/row hashes and private files against a dedicated pre-migration baseline. It must not publish a real profile, enable consent, create demonstration profiles or mutate current accounts. Runtime session activity is accounted for separately from domain preservation.

Stale snapshots and injected failures in SQLite are simulated interleavings, not real parallel MySQL tests. Browser layout, keyboard, theme, real image fallback and isolated MySQL concurrency checks remain in `manual-review.md`. Do not retry the previously denied browser preview.

## Local migration and preservation

Only `2026_11_02_120000_add_client_visibility_to_freelancer_profiles_table` was applied to local MySQL. Original column/row hashes across 24 domain tables and all private-file hashes match `storage/app/private/freelancer-discovery-baseline.json`: three users, one professional profile, its two skill selections, eight skills, eight categories, one project, one proposal, and one avatar file remain unchanged. No demonstration records were created. The original profile visibility is unchanged; new photo consent is false, first publication time is null, and profile version is zero. All real accounts remain active at access version zero. A read-only MySQL discovery/released-reputation projection query also completed successfully against this baseline.

## Automated verification

The final affected run passed **275 tests / 1,848 assertions**, exit status zero, using isolated SQLite `:memory:` and fake storage. Evidence is in `storage/logs/freelancer-discovery-affected-final.txt`, its corresponding JUnit report, and the saved exit-status file. It includes the existing editor/model/policy, photo, released-reputation, proposal-review, account-settings, suspension and dashboard/navigation regressions as well as the new feature cases.

The subsequent complete suite passed **2,186 tests / 12,099 assertions**, with **zero failures, errors or skips**, exit status zero. Text, JUnit, testdox and exit-status evidence use the `storage/logs/freelancer-discovery-full` prefix. The affected and full runs were sequential; final source hashes match the implementation tested. No production/schema-test inputs were changed during either final run.

Targeted fixes verified stable normalized revision hashes, publish/unpublish/consent retries, first-publication preservation, retained-input validation recovery, stale editor refusal, and same-second visibility-cycle detection. Production suspension and last-active-Admin protection are preserved.

Repository-wide Pint, changed PHP/Blade syntax, whitespace checks and `npm.cmd run build` passed. No JavaScript test script is configured. The build retains the existing empty `app` chunk warning because `resources/js/app.js` is empty; its cause is unchanged by this feature. No browser preview was attempted.

The verified discovery change set contains **34 files** (31 PHP/Blade files and three documentation files), which were unstaged and uncommitted at verification time. The complete list is retained locally in `storage/logs/freelancer-discovery-changed-files.txt`. Final MySQL/domain/private-file preservation checks passed after the suite. Runtime database sessions are excluded from the domain baseline and were checked only as aggregates: four rows, two authenticated at the final check. No real account was suspended and no profile was published or opted into photo sharing by this work.
