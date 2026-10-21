# Private mutual project feedback

## Eligibility and the exact review window

Only the original owning Client and assigned Freelancer can submit, in their persisted participant roles. The opposite participant is resolved from the locked assignment/project, never editable fields. Both participant accounts are locked in ID order before the project, assignment and rating records, following the existing account-before-project convention. Each save reloads authorization, assignment and completion state. Self-rating is denied even for an inconsistent historical assignment.

A project must have status `completed`, its original UTC `completed_at`, and `completed_by` matching the owning Client. The submission interval is **[completed_at, completed_at + 14 days)**. The opening instant is included and the exact closing instant excluded. This is an elapsed UTC interval, not a Sri Lankan calendar-day cutoff. The UI displays its opening/closing instants and submission times in Asia/Colombo, including seconds. Missing completion time/actor is never estimated; future completion times do not open a window early.

Payment reports, receipt acknowledgment and dispute state are independent and do not gate feedback. No project, payment, dispute, profile visibility or agreed term is changed by submitting feedback.

## Immutability, constraints and retries

`project_ratings` stores assignment, reviewer and reviewee identities, an integer score from 1 to 5, optional normalized plain-text comments (maximum 2,000 characters), UTC submission time and a server-issued UUID. All fields are guarded. Ordinary model updates/deletes are refused. Foreign keys restrict deletion of the assignment and both accounts. Database constraints enforce score bounds, non-self-rating, one submission per assignment/reviewer and unique submission identifiers. SQLite uses inline CHECK constraints; MySQL uses named CHECK constraints.

The account-deletion guard checks authored and received feedback before photo/profile cleanup, returning a clear history-preservation error. Accounts with no domain history retain their existing deletion behavior.

The transactional action validates only the editable score/comments and explicit confirmation. Identity, role, visibility and timestamps in editable input are ignored. The token is a locked Livewire property passed separately. An authorized exact retry requires the same token and normalized score/comments; it returns the original record, including after the submission window closes. A different token, score or comments cannot replace a submission. Only known rating uniqueness conflicts can be recovered, and only by reloading a matching durable winner. Missing winners and unrelated database failures propagate. Notification failure or enclosing transaction rollback leaves no partial rating or alert.

## Blind release and response privacy

Authors can read their own submitted feedback while retaining private participant authorization. Counterparty feedback is query-filtered out until both original participants have submitted or the review window has ended. The shared `ProjectRating::released()` scope derives release from persisted completion/participant records and current server time. It validates the original opposite-participant pairing. A submission for a different assignment or an unrelated author cannot release another rating.

There is no editable `is_public`, queued release job, scheduled publication write or GET mutation. A unilateral rating becomes released at the exact closing instant without modifying any record. Feedback-page visible polling recomputes the same query. Before release, the received record is not loaded into view data or public Livewire properties, and scores/comments are absent from HTML, snapshots, effects and notification previews. Authors' own records remain readable when historical completion metadata is unavailable; missing metadata never fabricates a release time.

The private named route is `projects.feedback` (`/projects/{project}/feedback`). No public account/profile lookup route is introduced. Client project review, Freelancer assignment details and project completion link to this page after completion. GET and polling only read; submission is an authenticated, CSRF-protected Livewire mutation with an explicit permanent-feedback confirmation.

## Reputation summaries

`ProjectReputation::forUsers()` aggregates released feedback only, in one query for a page of accounts. Counts and one-decimal averages are separated by the role in the original assignment: received as Client versus received as Freelancer, independent of a later account-role change. No released ratings displays **Not rated yet**, never a fabricated zero-star reputation. No reputation cache can outlive time-based release.

Summaries appear on Client applicant list/detail pages, Freelancer discovery details for the Client, and the account's own Client/Freelancer dashboard and Profile settings. They expose only average/count and participant role. They do not include past project titles, budgets, files, correspondence, comments, private profiles, emails, avatars or inaccessible project links. Full escaped comments, score, author display name and submission date are available only in the authorized project-feedback workspace after the release rule permits them.

## Notification mapping

`project_feedback_submitted:<rating ID>` notifies only the opposite authorized participant, excluding the author. The existing notification service writes inside the rating transaction/connection and deduplicates per recipient/event. Its payload contains only `record_id`; generic text reveals neither score nor comments. Opening leads to the authorized feedback page even while content is blind, without exposing the hidden rating. Destination authorization is rechecked and lost access uses the existing safe inbox fallback. No external notification is sent and no alert is generated on rendering, release, factories or a historical backfill.

## Verification limits

Automated verification uses SQLite `:memory:` and fake storage. Injected durable winners and sequential competing submissions simulate concurrency; they do not constitute real parallel MySQL execution. Local MySQL receives only the additive rating migration, with no demonstration feedback or modification of existing projects. Browser/mobile/theme/keyboard review and isolated parallel MySQL checks remain deferred in `manual-review.md`; the denied preview is not retried.
