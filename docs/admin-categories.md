# Administrative category management

`/admin/categories` is available only to authenticated administrators through the role middleware and `CategoryPolicy`. Every mutation reloads and locks the authenticated account before checking its persisted role. Category access does not grant administrators access to private projects, profiles, files or conversations.

Names are trimmed, must contain a letter or number and are limited to 100 characters. The existing `VARCHAR(255)` column is retained to avoid altering existing records; the new application limit is stricter. Descriptions are optional plain text, limited to 1,000 characters and escaped on display. Creation generates a normalized slug on the server and reports unusable or duplicate slugs. Existing slugs remain immutable. New categories are active.

Descriptive edits and activation changes are separate actions. Descriptive revisions compare the name, description and latest descriptive audit identity. Activation revisions compare the displayed name, activation state and latest activation audit identity. This detects conflicting edits and changes that return to earlier values, including changes within one timestamp second. A descriptive edit cannot overwrite activation state.

Activation confirmation captures real total, open, discoverable and assigned project counts. Counts and the relevant revision are checked again under the category lock. Changed counts require refreshing the confirmation. Counts do not reveal project titles or private account details. Search is bounded and treats SQL wildcard characters literally; pagination uses deterministic name/id ordering.

Inactive categories cannot be used for new drafts, publication, proposal submission or acceptance. The existing shared discovery scope excludes them from both listing and direct detail access. Client project cards and review pages explain the inactive category. No project status, ownership, category, agreed term or publication timestamp changes automatically. Reactivation restores only projects otherwise eligible under the normal discovery rules.

Assigned planning, task progress, uploads, version reviews, completion, external-payment tracking, messages and authorized history remain available after deactivation. Category deletion is not offered. `CategorySeeder` remains reference-data-only and uses `firstOrCreate`, preserving existing names, descriptions, activation states and timestamps.

## Transactional action log

`admin_action_logs` records the server-resolved actor, generic subject identity, typed action, whitelisted before/after values, UTC occurrence time, UUID retry identity and normalized request hash. The actor foreign key restricts deletion; account settings return a validation message before any profile/avatar cleanup when administrative history exists.

The logger requires the subject's active transaction and an administrator account on that connection. Category creation, descriptive edits and activation changes write their audit event in the same transaction as the category. Rollback removes both. Unchanged operations do not touch the category timestamp or create events. Application updates and deletion of completed log models are refused. The explicit action/subject/field whitelist can be extended for later administration without accepting arbitrary account payloads.

An exact retry returns the original result without changing its event time or replaying a later-reversed change. Reusing an identity for a different actor, payload or action is rejected. Only recognized category-slug and action-identity uniqueness conflicts are handled; unrelated database failures propagate. Locks follow the existing actor-then-domain-record convention, and transactions use bounded deadlock retries.

## Verification limits

Automated tests use SQLite `:memory:` and fake private storage. Interleavings, stale forms and injected uniqueness conflicts simulate concurrency; they are not parallel MySQL tests. The local MySQL migration check preserves existing row counts, row hashes and private-file hashes and creates no demonstration categories or audit events. Browser, keyboard, mobile, theme and isolated parallel MySQL checks remain on the final-review list in `manual-review.md`.
