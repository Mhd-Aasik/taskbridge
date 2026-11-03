# Administrator workspace — manual acceptance checklist

**Status:** Test plan only. **No tests in this document are claimed as executed or passed.** Mark an outcome only after actually completing that scenario in an approved test environment.

## Safe setup

- Obtain permission from the repo owner / project team before changing any stored data.
- Prefer a local or dedicated test installation. **Do not** change real users, live disputes or business categories simply to collect screenshots.
- Use an existing authorized Admin test account, plus separate fictional Client and Freelancer accounts. Have a pre-created test dispute and test category if the environment permits it.
- Never include passwords, account-setup tokens, real email addresses, private file contents or session IDs in evidence.
- Note the application revision and environment. Local and Alwaysdata databases are separate; do not assume test records match.
- Use this notation: `Not run`, `Pass`, `Fail`, `Blocked`. Record date, operator, evidence reference and defect ID when applicable.

## Account and access scenarios

| ID | Steps (summary) | Expected result | Status |
| --- | --- | --- | --- |
| ADM-M01 | Sign in as Admin and open `/admin/dashboard` | Admin dashboard visible | Not run |
| ADM-M02 | Sign in as Client; request `/admin/accounts` | Admin area denied; private account list not shown | Not run |
| ADM-M03 | Sign in as Freelancer; request `/admin/categories` | Admin area denied | Not run |
| ADM-M04 | As Admin, search/filter accounts by known fictional test data | Correct bounded results; no secrets exposed | Not run |
| ADM-M05 | Open fictional Client account status; inspect reason/confirmation before a permitted suspension | Target and state clearly shown; action requires confirmation | Not run |
| ADM-M06 | In approved **test database**, suspend test Client then attempt access from their previous session | Old authenticated access is blocked; project records remain | Not run |
| ADM-M07 | Reactivate that fictional account; retry its previous session, then perform a fresh login | Old session stays invalid; fresh eligible login works | Not run |
| ADM-M08 | Open an Admin account detail as Admin | No controls for suspending/reactivating Admin account | Not run |

## Category scenarios

| ID | Steps (summary) | Expected result | Status |
| --- | --- | --- | --- |
| ADM-M09 | Create a uniquely named **test** category (only in approved test database) | New active category appears; change has audit record | Not run |
| ADM-M10 | Edit its description and review history | Updated description; previous administrative change retained | Not run |
| ADM-M11 | Deactivate test category; check new-project eligibility | New actions using inactive category are refused; existing assigned work is preserved | Not run |
| ADM-M12 | Try to resubmit an older state-changing confirmation after data changes | Stale confirmation refused or requires refreshed state | Not run |

## Dispute and privacy scenarios

| ID | Steps (summary) | Expected result | Status |
| --- | --- | --- | --- |
| ADM-M13 | Open authorized test dispute in Admin area | Only scoped case context is shown | Not run |
| ADM-M14 | Use approved fictional dispute to request clarification | Case retains statement/history; no unrelated data exposed | Not run |
| ADM-M15 | Resolve/dismiss an approved fictional case with rationale | Decision retained; no automatic payment or project completion | Not run |
| ADM-M16 | As a Client with no case participation, attempt to access another case | Access refused | Not run |
| ADM-M17 | Use Admin account to access unrelated private project pages/files | Unrelated private resources remain forbidden | Not run |

## Execution log template

Copy this row for each test **actually run**:

| Field | Value |
| --- | --- |
| Test ID | `ADM-M__` |
| Environment and revision | `Local/test environment; revision ____` |
| Tester and date | `____` |
| Data used | `Fictional test records only` |
| Actual result | `____` |
| Outcome | `Not run / Pass / Fail / Blocked` |
| Evidence (non-sensitive) | `____` |
| Defect / follow-up | `____` |

## Cleanup and handoff

1. In a dedicated test database, remove permitted disposable test data using documented application workflows where available; **do not** delete immutable history directly.
2. If cleanup is restricted, ask the owner to preserve or reset the isolated test environment; do not bypass database constraints.
3. Record any scenario not exercised as **Not run** or **Blocked**, not Pass.
4. Separate existing CI results from *your own* manual test results. This checklist does not assert new CI evidence.

References: `routes/web.php`, `docs/admin-accounts.md`, `docs/admin-categories.md`, `docs/project-disputes.md`, `docs/user-guide.md`.
