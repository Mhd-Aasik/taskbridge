# Private project activity

Login redirects were checkpointed as `299a81f` (`Fix role-aware login redirects`). Activity development is on `feature/project-activity`, with its implementation intentionally unstaged and uncommitted.

## Event coverage

Explicit hooks run only after an effective authorized mutation, inside the existing business transaction. Factories, observers, page rendering and notification delivery do not emit activity. Existing records are not backfilled.

| Activity | Authorized business action | Durable identity |
| --- | --- | --- |
| Project published | `PublishProject` | Project |
| Freelancer assigned | `AcceptProposal` | Assignment; no rejected-offer details |
| Milestone added / updated | `SaveMilestone` | Milestone / persisted details revision |
| Milestone completed | `CompleteMilestone` | Milestone |
| Task added / updated | `SaveProjectTask` | Task / existing details revision |
| Task status changed | `ChangeProjectTaskStatus` | Existing status revision; safe resulting status |
| Deliverable version submitted | `SubmitDeliverable` | Immutable version |
| Version approved / changes requested | `ReviewDeliverableVersion` | Immutable review |
| Project completed | `CompleteProject` | Project |
| External payment reported | `ReportPayment` | Immutable report |
| Receipt acknowledged | `AcknowledgePayment` | Immutable acknowledgment |
| Dispute opened | `OpenDispute` | Immutable case |
| Dispute resolved / dismissed | `ResolveDispute` | Immutable resolution |

The event enum allowlists 17 event types. Metadata contains only the origin's numeric record ID, a numeric revision for mutable changes and the resulting allowlisted task status where applicable. No project/milestone/task/deliverable titles or descriptions, cover letters, competing proposals, amounts, payment references, notes, message bodies, blind feedback, administrative rationale, storage paths or filenames are copied into activity. Display labels are fixed application strings; actor display names and the current authorized project's title are escaped. Dedicated pages remain the source of detailed records.

## Transactions and retries

`ProjectActivities` requires the originating model's transaction and uses that same connection. It re-resolves persisted origins and actors and checks the saved event outcome. Existing account → project → assignment → child lock ordering is unchanged. Activity-key locks/inserts occur after those business locks. A failed activity insert rolls back business records, notifications and any newly uploaded file through the existing rollback cleanup. Failure is not swallowed or converted into a successful business mutation.

The database unique key is `(project_id, event_key)`; keys derive only from event type, origin ID and persisted revision. Only that specific unique conflict is recoverable, and a recovered record must match the original actor, assignment, event and safe metadata. Metadata comparison ignores JSON object key order (which MySQL can normalize) while retaining strict value/type checks. Original occurrence times are preserved. Unrelated database errors and conflicts without a matching winner propagate.

Task details/status retain their existing optimistic revisions. Milestones gain a guarded `details_version`, initialized to zero without changing existing details; only an effective details edit increments it under the existing assignment lock. Unchanged milestone details now return without saving. This counter identifies events, rather than introducing a new client-editable field or changing existing milestone form semantics. Real edit cycles and Done → To do → Done transitions have distinct persisted revisions even in the same second. Existing action-level retries return before emitting; unchanged and rejected saves emit nothing. Creation remains the existing explicit creation operation: two genuinely created milestones/tasks have separate IDs and separate events; recording the same creation twice cannot duplicate its event.

Notifications and Admin audit logs retain their separate services, purposes, recipient rules and unread state. Activity has no unread state, subscription, delivery or backfill process. Messages and feedback intentionally have no activity hooks, avoiding message/feedback timing or content disclosures.

## Visibility and navigation

`projects.activity` is authenticated, role-restricted and policy-authorized. The account is resolved afresh and normal suspension/access-version enforcement remains active.

- The owning Client sees all of that project's activity, including before assignment and on drafts.
- Only the assigned Freelancer sees shared rows for that exact assignment with occurrence time at or after `assigned_at`. Null-assignment publication rows remain Client-only even after assignment.
- Historical access remains after completion/cancellation and after the counterparty is suspended. Suspended/revoked viewers are denied. A persisted wrong role removes access.
- Other Clients, unselected applicants, directory visitors, unrelated Freelancers and Admins receive no project-history access. An Admin's dispute-resolution event does not grant that Admin an activity page.

The page loads 15 rows with actor `id,name` eager loading, ordered by UTC `occurred_at DESC, id DESC`; page values are bounded to 1–10,000. Times display in Asia/Colombo. Responses are private/no-store. There is an honest empty state because pre-feature events do not exist.

Links from Client project review, Freelancer assignment details and the shared plan open activity. Event destinations are derived from the current participant/policy context without per-event record queries: own project/assignment, plan, deliverables, completion, payment or the private dispute list. They never link to another role's proposal review, account settings, private profiles/avatars or Admin pages.

## Schema and preservation

The single additive migration creates `project_activities`, adds its ordered-query/unique indexes and the milestone edit counter, and adds a supporting unique assignment `(id, project_id)` index. Restrictive project/actor foreign keys and a composite assignment/project foreign key preserve history and reject mismatched assignment ownership on MySQL and SQLite. Model updates/deletions are refused and all fields are guarded. This is application-level append-only enforcement, not protection against privileged raw SQL or database administrators.

The account-deletion form retains established history guards and adds an activity-only guard before deletion/private-file cleanup. Ordinary deletion without history still works.

Before migration, an ignored read-only helper captured original values/counts in 24 MySQL domain tables and SHA-256 hashes of private avatar/cover/deliverable files. Only `2026_11_03_120000_create_project_activities_table` was applied. The immediate comparison passed: original domain values/counts and private files unchanged, the new activity table empty, no seeded events or changes to the real published project/proposal. All three real accounts remained active at access version zero. Runtime sessions are excluded from domain hashes and counted separately (four rows, two authenticated at the initial and immediate post-migration checks).

## Verification and limits

Automated coverage exercises all allowlisted action hooks, exact record/action retries, safe metadata, activity-insert rollback for every hook (including file cleanup and notification rollback), suspended actors, task/edit cycles, unchanged/stale saves, scoped history and interval boundaries, outsider denial, historical/counterparty access, escaped text, pagination, authorized destinations, eager loading, immutable fields, restrictive/unique/CHECK constraints, account deletion, connection ownership and simulated uniqueness conflicts.

The final focused activity run passed **85 tests / 825 assertions**, with zero failures/errors/skips and exit status zero (`storage/logs/activity-final-targeted` text/JUnit/exit records). The earlier broad affected run passed 817 tests and found one overlapping-email fixture assertion; distinct test addresses corrected that fixture, and the complete final activity run includes the corrected privacy check and JSON-key-order regression. Repository-wide Pint, whitespace checks and syntax checks for all **36 changed PHP/compiled Blade files** passed after the final code/test edits. `npm.cmd run build` passed; its known empty `app` chunk warning remains because the existing `resources/js/app.js` entry is empty. No frontend inputs changed after that build.

The subsequent complete SQLite `:memory:` suite passed **2,291 tests / 13,116 assertions**, with **zero failures, errors or skips**, exit status zero (662.29 seconds wall time). Evidence uses `storage/logs/activity-full` text/JUnit/testdox/exit records. Test runs were sequential, with isolated compiled-view directories and fake storage; all 36 final implementation/test hashes match the inputs exercised by both passing runs.

After the full suite, the read-only MySQL comparison again matched all original values/counts in 24 domain tables and all private-file hashes. The new activity table remains empty, and no existing project state was changed. MySQL metadata confirms all three new activity foreign keys use `DELETE RESTRICT`, including the composite assignment/project constraint. The three real accounts remain active at access version zero. Runtime sessions were checked separately: four rows, two authenticated. The migration was not repeated. Final Git scope is **38 changed files, all unstaged/uncommitted**, on `feature/project-activity` at checkpoint `299a81f`.

SQLite stale-state/conflict injection is simulated concurrency, not actual parallel MySQL execution. No concurrency tests run against development records. No browser preview or manual review has been performed; keyboard/focus, screen-reader announcements, mobile layouts, both themes, navigation caching and isolated parallel MySQL testing remain deferred in `manual-review.md`.

## Changed files (38)

- Business hooks (13): `app/Actions/Projects/{PublishProject,CompleteProject}.php`, `app/Actions/Proposals/AcceptProposal.php`, `app/Actions/Milestones/{SaveMilestone,CompleteMilestone}.php`, `app/Actions/Tasks/{SaveProjectTask,ChangeProjectTaskStatus}.php`, `app/Actions/Deliverables/{SubmitDeliverable,ReviewDeliverableVersion}.php`, `app/Actions/Payments/{ReportPayment,AcknowledgePayment}.php`, `app/Actions/Disputes/{OpenDispute,ResolveDispute}.php`.
- Activity infrastructure (6): `app/Enums/ProjectActivityEvent.php`, `app/Models/ProjectActivity.php`, `app/Services/ProjectActivities.php`, `app/Policies/ProjectActivityPolicy.php`, `app/Support/ProjectActivityHistory.php`, `app/Http/Controllers/ProjectActivityController.php`.
- Existing model relationships/revision cast (4): `app/Models/{Project,ProjectAssignment,User,Milestone}.php`.
- Schema/factory (2): `database/migrations/2026_11_03_120000_create_project_activities_table.php`, `database/factories/ProjectActivityFactory.php`.
- Page, links, deletion guard and route (6): `resources/views/projects/activity.blade.php`, `resources/views/livewire/client/projects/show.blade.php`, `resources/views/freelancer/assignments/show.blade.php`, `resources/views/livewire/projects/plan.blade.php`, `resources/views/livewire/settings/delete-user-form.blade.php`, `routes/web.php`.
- Tests (5): `tests/Concerns/BuildsProjectActivity.php`, `tests/Feature/ProjectActivity{Actions,Data,Integrity,Pages}Test.php`.
- Documentation (2): this document and `docs/manual-review.md`.
