# Private in-app notifications

Notifications are synchronous database records, written explicitly by successful business actions inside the originating transaction and connection. The service is never invoked by domain model observers, factories, rendering, polling or historical backfills. Core delivery does not use a queue worker or external channels.

## Native storage and deduplication

`InAppNotification` extends Laravel's `DatabaseNotification`; User retains `Notifiable` and exposes its native morph relationship, read/unread scopes and notification collection through this model. The native UUID/type/notifiable/data/read_at/timestamp structure adds a stable `event_key` and a database unique constraint on `(notifiable_type, notifiable_id, event_key)`.

Event keys combine a whitelisted event type and the actual persisted domain record ID. The service derives recipients and the actor from saved associations, excludes the actor, verifies current role/policy eligibility, and writes on the origin model's connection. A missing origin transaction or mismatched record/decision is refused. Existing alerts return unchanged, including any read state and timestamps. Only the recipient/event unique constraint is recoverable, in a savepoint; a matching durable winner must exist. Different payloads under the same key, missing winners and unrelated failures propagate and roll back the business operation.

The source actions keep their existing account/project/assignment/domain lock order. Notification-producing transactions consistently use Laravel's three-attempt deadlock handling; they do not add recipient account locks after project locks. The notification foreign key still acquires normal database referential locks. Real parallel MySQL scheduling remains a final-review check; automated conflict injection is not proof of parallel execution.

The notification recipient foreign key cascades on account deletion. Personal alerts therefore do not add a domain-history deletion restriction. Existing project/proposal/work/payment/dispute/conversation guards still run before deletion and photo/profile cleanup. A refused deletion retains alerts as well as domain records and files.

## Event and recipient mapping

Every recipient below must still have the stated role and pass the destination's current policy. The actor is always excluded.

| Event | Stable source | Recipients |
| --- | --- | --- |
| Proposal submitted | Proposal ID | Owning Client |
| Proposal accepted | Selected Proposal ID | That proposal's Freelancer |
| Proposal rejected during assignment | Each newly rejected Proposal ID | That proposal's Freelancer; already rejected/withdrawn offers are excluded |
| Deliverable version submitted | Version ID | Owning Client |
| Version approved / changes requested | Immutable Review ID | Assigned Freelancer |
| Milestone completed | Milestone ID | Assigned Freelancer |
| Project completed | Project ID | Assigned Freelancer |
| External payment reported | PaymentReport ID | Assigned Freelancer |
| Receipt acknowledged | PaymentAcknowledgment ID | Owning Client |
| Dispute opened | DisputeCase ID | Counterparty and Admins who pass the case's explicit view and resolve policies, including conflict-of-interest restrictions |
| Dispute statement added | DisputeStatement ID | Other project participants, plus already participating clarification-author Admins who still pass case policies |
| Dispute resolved / dismissed | Immutable Resolution ID | Both project participants |
| Project message sent | Message ID | Other project participant |

No other private event is broadcast to Admins. Under the current dispute policy all non-involved Admins may manage an open case; the opening notification uses those policy checks rather than an unconditional Admin broadcast. Subsequent discussion alerts go only to eligible Admins already participating through clarification statements.

## Privacy and destinations

The payload is only `{"record_id": number}`. Type/event key identify the event. Previews use fixed event labels and notification times, without project titles or sensitive user-written content. They contain no message bodies, cover letters, dispute remarks, payment references, private paths, credentials or emails. No destination URL or recipient list comes from editable input.

Opening is an authenticated, CSRF-protected Livewire mutation. It scopes the UUID to the authenticated account, re-resolves the event record, checks current destination authorization, and constructs a named internal route from the event whitelist. Unknown/corrupt types, missing records or lost authorization return to the private inbox with a safe explanation and leave the alert unread. No sensitive resource context is displayed in a preview. The destination page independently enforces its normal authorization again.

## Bell, inbox and read state

The shared header bell shows the account's unread count and at most five recent alerts. The full inbox paginates 20 records in deterministic created-at/UUID descending order, with validated All/Unread filtering and bounded page numbers. Mobile control spacing is compact; the existing theme/avatar controls and branding are preserved. Both views refresh through visibility-aware Livewire polling, without WebSockets.

GET, dropdown rendering and refresh never mark alerts read. Explicit read/unread and authorized open mutations update only the account's own alerts, preserving timestamps on unchanged retries. These operations do not call conversation read actions. Opening a message alert marks only the alert read; the conversation's existing visibility/presentation-receipt flow must present messages before its cursor advances. Admin dispute context likewise does not alter participant alerts or cursors.

“Mark all read” first captures the existing unread UUID set on explicit preparation. A protected random token refers to an owner-bound snapshot in Laravel's configured cache for 20 minutes; rendering/polling does not create snapshots. Confirmation updates only currently unread records in that captured set, in chunks of 500 inside one transaction. New arrivals and alerts made unread after capture but absent from the set remain unread. Repeated confirmation preserves earlier read timestamps. Expired/evicted snapshots require recapture and confirmation; the cache is not used for core event delivery.

## Verification limits

PHPUnit/Volt coverage uses SQLite `:memory:` and fake storage. It includes every event's actual mutation, deduplication, rollback/file cleanup, private destinations, captured-set arrivals, non-default SQLite connection behavior, read-state independence and account deletion. Competing writes and arrivals are simulated, not real parallel MySQL execution. Browser/mobile/theme/keyboard/native-dialog and real concurrency checks remain deferred in `manual-review.md`. No browser preview or external notification delivery is performed in this step.
