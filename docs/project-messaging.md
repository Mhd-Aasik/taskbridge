# Private project conversations

The owning Client and assigned Freelancer can exchange permanent plain-text messages while their assigned project is in progress, completed or cancelled. Other project statuses, unassigned projects, unrelated accounts and Admins are denied by the ordinary messaging policy. Messaging never reopens work or changes project, assignment, completion, file, review, dispute or payment records. There are no message edits, deletion, attachments, arbitrary recipients, presence indicators or external notifications in this step.

## Schema and authorization

`project_messages` stores assignment, sender, body, UTC sent time, a globally unique submission UUID, and timestamps. All fields are guarded against public mass assignment. Model events refuse updates/deletion of sent messages. Privileged SQL can bypass model events and must not rewrite history.

`project_read_cursors` has a unique assignment/user pair and a non-null last-read message. A composite foreign key to `(project_messages.id, project_assignment_id)` prevents cross-conversation cursor anchors on MySQL and SQLite. All foreign keys restrict deletion/update. Model events reject changing cursor ownership/assignment, moving backwards or deleting read history. Account deletion checks both sender and reader history before any account/profile/photo cleanup.

Every send, history load, poll, render and cursor action re-resolves the authenticated account and persisted participant role/association. Lock order is account → project → assignment → message/cursor, matching existing work/completion operations. The assignment lock serializes sends and cursor updates. Message IDs provide a stable, deterministic conversation order even when timestamps tie.

## Sending and retries

The reusable sending action normalizes CRLF/CR to LF and trims surrounding Unicode whitespace, retaining meaningful internal spacing and line breaks. Body validation requires meaningful text up to 5,000 characters. Sender, assignment, UUID and UTC time come from trusted code, not editable fields.

Exact UUID/actor/normalized-body retries return the original message and timestamp before checking the rate limit. Reusing a UUID with different content, sender or conversation conflicts. Recovery handles only the submission UUID constraint; unrelated database errors propagate. Failure feedback preserves text and does not assume an unknown response outcome means the database rolled back. A conflicting composer can explicitly allocate a new identifier without discarding its text.

The server permits **20 new valid sending attempts per account per 60 seconds across all projects**, under the account lock, using Laravel's configured cache-backed rate limiter. Exact retries do not consume quota. Invalid text does not consume quota. A valid attempt that later fails can consume quota depending on cache-store transaction behavior; waiting the stated cooldown permits another attempt. This is an application limit, not an external messaging service.

## Read tracking and bounded history

Unread counts include only incoming message IDs newer than the authenticated account's cursor, scoped to its currently authorized assignments. Outgoing messages and other accounts' cursors do not increase the count. The conversation list computes scoped counts with database count queries; navigation and detail badges refresh through Livewire polling/events.

GET, sending, polling, history loading, list pages and Admin review do not update read cursors. A separate authenticated Livewire mutation acknowledges a server-issued encrypted presentation receipt. It contains the account, assignment, complete displayed ID window and issue time. It expires after 20 minutes and is verified against a contiguous, immutable same-assignment database range. Editable message IDs and arbitrary cursor owners are not accepted.

The cursor advances only to that presented window's highest ID, never to a fresh database maximum. A message arriving after rendering therefore remains unread. Older/repeated receipts do not move the cursor backwards or rewrite timestamps. If there is an undisplayed incoming backlog before the window, acknowledgment leaves the cursor unchanged rather than skipping it. Normal initial loading starts at the oldest unread incoming message. After sending, a jump to the newest window preserves any earlier unread backlog and provides a return-to-unread control.

History loads 30 messages at a time and retains at most 90 rendered IDs. Older loading uses ID anchors, so new arrivals do not shift earlier pages. While reading older history, polling refreshes counts without replacing that window. The Alpine handler preserves a retained message's visual offset when prepending history. It scrolls following windows only when already near the bottom, or after an explicit newer/unread action or successful send.

Livewire polls visible conversations every 10 seconds and unread badges every 15 seconds, with its installed background throttling. Read acknowledgment additionally requires a visible browser document, the latest end marker inside the viewport, a near-bottom thread, no older-history view, and no in-flight read/load. Client visibility is a UI signal, not proof that a human read text. Server-issued window bounds and current authorization enforce the write boundary even if client signals are forged. No read receipts or presence are exposed to the other participant.

## Admin dispute context

Admins have no general conversation list, participant messaging page, sender action or cursor action. `DisputeConversation` independently resolves the authorized dispute and verifies that its assignment/project match the message policy's explicit dispute ability. Its paginated read-only current-context section loads only same-assignment messages and sender display names. It does not invoke read-cursor actions or create Admin cursors. Existing broad project/file/photo policies are unchanged.

## Verification limits

PHPUnit/Volt tests use SQLite `:memory:` and fake storage as needed. Arrival/competing-write tests simulate interleavings and inject conflicts after rollback; they are not parallel MySQL tests. The Node built-in test runner executes the actual Alpine handler with simulated geometry/visibility to verify acknowledgment and scroll decisions; this is not browser or accessibility review. Actual desktop/mobile, themes, keyboard, polling/network and native scrolling checks remain deferred in `manual-review.md`. Run any real concurrency tests against a dedicated MySQL test database, never development records.
