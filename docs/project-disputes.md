# Private project disputes

An owning Client or assigned Freelancer can open a case for an assigned project, including completed projects. Opening text, statements and Admin decisions are append-only application history. There are no correction, deletion, refund, money-transfer or automatic project-status actions in this step.

## Records and state

- `dispute_cases` preserves the original title/reason, assignment, opener, UTC opening time and unique protected submission UUID.
- `active_disputes` uses the assignment ID as its primary key. Its unique case ID and composite foreign key to `(dispute_cases.id, project_assignment_id)` enforce a single active case for the correct assignment on MySQL and SQLite.
- `dispute_statements` preserves actor, server-assigned statement/clarification kind, plain text, UTC submission time and unique protected submission UUID. A later role change does not relabel earlier discussion.
- `dispute_resolutions` has one immutable decision per case: resolved or dismissed, with rationale, deciding Admin and UTC time.

All foreign keys restrict deletion/update. System fields are guarded against ordinary mass assignment. Model events refuse updates/deletion of historical records. These are application protections; privileged direct SQL can bypass model events and must not be used to rewrite history. Account deletion checks actor relationships before any account/profile/photo cleanup.

State derives from the resolution and active marker, with no separately editable status. The transaction inserts the decision and removes the active marker together. Missing/inconsistent markers without a resolution display as unavailable rather than accepting further discussion or implying a resolution. Closed cases remain readable, and participants can open a later case for the same assignment.

## Authorization and coordination

Every action resolves the persisted authenticated account and parent associations server-side. Lock order is account, project, assignment, case, then case history/active marker. The project/assignment locks match work, payment and completion actions. An active case blocks new completion through shared readiness checks inside the existing completion transactions. Exact earlier completion retries still return their immutable history. Normal authorized work and payment tracking continue.

An opening retry matches UUID, actor, original title and reason. Competing new openings return the authorized existing active case. Statement retries match UUID, actor and body. Resolution retries match the original Admin, decision and rationale. Exact retries preserve timestamps, including after closure. Changed requests conflict. Only the relevant UUID, active-marker or resolution unique constraint triggers recovery after rollback; unrelated database errors propagate. No successful response is returned without finding a durable, authorized winner.

Resolution requires a currently authorized Admin who is neither participant nor the original opener. Decisions close only the case and remove its completion blocker. They do not complete/reopen work, rewrite terms, alter payment/receipt records, or move funds.

## Private context and downloads

Participant lists and pages are scoped to current participant identity/role. Separate Admin routes and dispute policy abilities grant explicit case review. Existing project, proposal, cover, account-photo and deliverable policies do not gain broad Admin access.

Case context is explicitly labelled **current**, not an opening-time snapshot. It includes participant display names, project brief, immutable agreed terms, task counts, deliverable/version/review history, and external payment/receipt history. It excludes counterparty email addresses, private profile data, private photos, internal storage paths and unrelated proposals/files. The viewer's own normal account menu remains unchanged.

Admin attachment delivery uses `/admin/disputes/{dispute}/versions/{version}/download`, verifies assignment membership and the dispute policy, and reuses the normal secure attachment service: owned private storage path, safe download filename, attachment disposition, private/no-store cache control, `Vary: Cookie`, `nosniff`, and sandbox CSP. Participant cover and download access keeps its existing authorization.

List filters accept only known case states and newest/oldest ordering. Pages are bounded to 1–10,000, ordered by opening time plus ID, and filter changes reset pagination. Counts remain scoped and independent of filtering. Discussion and current-work sections paginate separately.

## Verification limits

SQLite in-memory tests and fake storage verify behavior and simulate sequential interleavings, response failures and relevant database conflicts. They do not prove actual parallel MySQL scheduling. Run concurrency checks only against a dedicated test database, never development records. Browser, mobile, theme, keyboard and native-dialog focus checks remain deferred in `manual-review.md`; automated Blade rendering is not browser review.
