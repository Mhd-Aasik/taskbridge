# Administrator access-control review sheet

**Purpose:** A compact review matrix for role restrictions and risk-focused checks. This is a documentation review of known rules, **not** a penetration test or proof that all controls were retested.

## Roles and permitted areas

| Action / resource | Guest | Client | Freelancer | Admin |
| --- | --- | --- | --- | --- |
| Open Admin dashboard | Deny | Deny | Deny | Allow if active, verified and authorized |
| Browse Admin account list | Deny | Deny | Deny | Allow |
| Suspend/reactivate Client/Freelancer account | Deny | Deny | Deny | Allow with confirmation and current-state checks |
| Suspend/reactivate Admin account in Admin UI | Deny | Deny | Deny | **Not offered** |
| Create/edit/activate category | Deny | Deny | Deny | Allow with validation/confirmation |
| Delete category using Admin UI | Deny | Deny | Deny | **Not offered** |
| Open Admin dispute queue | Deny | Deny | Deny | Allow |
| Read a dispute's private scoped context | Deny | Only if participant through participant UI | Only if participant through participant UI | Only through authorized case context |
| View arbitrary private project outside permitted case | Deny | Only if authorized participant/owner | Only if authorized participant | **Deny by default** |
| Decide dispute where Admin is participant/opener | Deny | Deny | Deny | **Deny** |

**Note:** The matrix summarizes role/ownership rules. Individual actions may also depend on account status, workflow state, stale confirmations, and server-side policy checks.

## What to inspect for each protected admin operation

1. **Authentication** — Is there a current user and a verified, active account where required?
2. **Role** — Is the user actually an Administrator according to the persisted account role, not a client-supplied value?
3. **Resource ownership/scope** — Is the target account/category/case the requested authorized resource?
4. **Input validation** — Are reason, description, filters and selected action validated on the server?
5. **Freshness and concurrency** — Does a stale form conflict rather than overwriting a newer decision?
6. **Historical integrity** — Does the operation record only allowed state transitions and preserve immutable logs?
7. **Privacy** — Does the response omit unrelated project records, secrets, user tokens and private photos?
8. **Session revocation** — For account suspension/reactivation, do old sessions remain invalid?

## Risk-to-control examples

| Risk | Related boundary | Relevant review question |
| --- | --- | --- |
| Unauthorized account management | Role middleware + User policy / server-side checks | Does `/admin/accounts` reject Client and Freelancer access? |
| Improper access to dispute material | Dispute policy and case-scoped file delivery | Can an Admin see only the case context, not unrelated private work? |
| Stale or repeated admin update | Current-state validation, transactions, protected retry logic | Does the server refuse conflicting stale actions? |
| Session re-use after suspension | Persisted account status, access version and session invalidation | Does an old authenticated session stop working? |
| Audit loss or silent mutation | Transactional append-only admin history | Are changes tracked without rewriting old actions? |
| Unauthorized category activation | Category policy and confirmation checks | Can an ordinary user change category status? |

## Evidence table — complete only after observing

| Check | Evidence available now | New manual result |
| --- | --- | --- |
| Admin route middleware | Can be inspected in `routes/web.php` | Not run |
| Account status rules | Described in `docs/admin-accounts.md` | Not run |
| Category rules | Described in `docs/admin-categories.md` | Not run |
| Dispute policy and private access | Described in `docs/project-disputes.md` | Not run |
| Browser/manual negative access checks | Requires authorized test setup | Not run |

## Do not overclaim

- A visible button or a policy described in a document does not by itself prove its security under all conditions.
- Existing GitHub CI reports are **historical recorded evidence**. This sheet does not add new execution results.
- Never use a real person's account or private case to demonstrate access failures without explicit authorization.
- Do not expose internal suspension reasons to Clients/Freelancers or present them in public logs.

Implementation references: `routes/web.php`, `docs/user-guide.md`, `docs/admin-accounts.md`, `docs/admin-categories.md`, `docs/project-disputes.md`.
