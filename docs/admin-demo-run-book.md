# Administrator workspace — safe demonstration runbook

This runbook makes the TaskBridge Admin role easy to explain and demonstrate **without editing production data**. It is for onboarding, handoff and course demonstrations. Nothing here should be presented as a completed manual test unless it was actually executed.

## Preparation (before opening the audience-facing screen)

- Obtain the maintainer's permission and an **authorized Admin demo login**. Keep passwords private and out of slides, recordings and source control.
- Check that the demo environment/revision matches the intended documentation. The live deployment may differ from latest repository documents.
- Confirm the pages load: `/admin/dashboard`, `/admin/accounts`, `/admin/categories`, `/admin/disputes`.
- Prepare fictional, non-sensitive account/category/case records **ahead of time** in an authorized environment. If there is no safe test data, choose read-only navigation instead of making real changes.
- Close unrelated tabs, browser autofill pop-ups and any pages showing personal messages or credentials.
- Agree on the presenter handoff and a fallback screenshot/page, clearly labelled as an illustration if live navigation fails.

## Suggested short walkthrough (about 90 seconds)

| Target time | Screen / action | Plain-English explanation |
| --- | --- | --- |
| 0:00–0:15 | Admin dashboard | “This dashboard gives authorized administrators access to platform management.” |
| 0:15–0:35 | Accounts → list/detail | “Admins can search accounts and review their current status and administrative history.” |
| 0:35–0:55 | Categories | “Admins can create or edit categories and control whether they are active.” |
| 0:55–1:15 | Disputes | “Admins review disputes using case-specific context, and record permitted decisions.” |
| 1:15–1:30 | Return to dashboard | “Access is restricted by role and permissions; important decisions are recorded.” |

If the group has only a minute left, show the first three pages without status-changing actions. Prefer completing a shorter accurate demo to rushing through irreversible actions.

## Optional deeper demo (approved isolated test environment only)

1. **Accounts:** open a fictional Client/Freelancer record, inspect confirmation, use a meaningful *internal* reason, suspend/reactivate only the test account. Verify a fresh login is required after reactivation.
2. **Categories:** create/edit a test category, demonstrate Active/Inactive status, check that the change is recorded. Avoid touching a real in-use category.
3. **Disputes:** open a pre-created fictional case, explain current statements and case context. If authorized, record a reasoned decision on the test case, never a live customer case.

**Warning:** The platform preserves many decisions in immutable history. Do not assume reversing a status or deleting an old record is possible.

## Quick questions and accurate answers

- **What is Admin Dashboard for?** Platform account, category and dispute administration.
- **What is suspension?** Temporarily denying authenticated access while preserving work/history; old sessions remain invalid.
- **What happens to assigned projects when a category is deactivated?** Existing assignments continue under their normal access rules; new eligibility in that category is restricted.
- **Can Admin see every private project?** No. Admin dispute review is a specific scoped exception.
- **What does resolving a dispute do?** Records a case decision and removes that active case's completion blocker. It does not send/refund money or complete the project.
- **What is an audit trail?** A preserved record of permitted administrative state changes and decisions.
- **Frontend/backend/database?** Livewire/Volt/Blade and Tailwind UI → Laravel/PHP authorization and actions → relational database.
- **What work was personally done?** Answer with the tasks you actually performed (e.g. reviewing and preparing these admin support docs), not implementation work done by others.

## Evidence capture without sensitive data

For a legitimate new review contribution, record: date, app revision, page viewed, fictional record used, observed behavior, and whether the check passed/failed/was blocked. Redact IDs and private fields. Add results to the manual checklist **only after performing the checks**.

## Failure handling

- Login unavailable → show prepared page screenshots, explicitly stating that they are screenshots rather than a live test.
- No demo data → show read-only UI, not fictional claims about changes executed.
- Stale confirmation → refresh current data; do not retry blindly.
- Unexpected role-access problem → stop, document the observation and inform the maintainer; do not attempt to bypass restrictions.

## Key project sources

- `routes/web.php`
- `docs/user-guide.md` (**Admin workspace**)
- `docs/admin-accounts.md`, `docs/admin-categories.md`, `docs/project-disputes.md`
- `docs/deployment-completion.md` (actual deployed revision and verification scope)

This is **documentation-only**: it makes no changes to Laravel routes, migrations, UI code, seeding, production data or CI configuration.
