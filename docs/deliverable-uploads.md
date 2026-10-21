# Private deliverable uploads

Each immutable version stores one original file, up to **10 MiB (10,485,760 bytes)**. Supported extensions are PDF, TXT, JPEG/JPG, PNG, WebP, ZIP, DOCX, XLSX and PPTX. The application validates the filename extension against server-detected content and stores a SHA-256 checksum of the original bytes. It does not perform malware scanning, extract archives, execute files, or interpret an upload as Client approval.

Only the current assigned Freelancer may submit new work while a project is in progress. The owning Client and current assigned Freelancer can download historical versions through an authenticated, scoped attachment endpoint. Private files and temporary uploads are outside the public directory; no public storage link is required. Filesystem paths and retry identifiers are hidden from model serialization.

## Effective local-development limits

The inspected PHP 8.2 CLI runtime loads `C:/xampp/php/php.ini`: `upload_max_filesize=40M`, `post_max_size=40M`, and `memory_limit=512M`. These limits support a 10 MiB upload with multipart overhead. ZIP, fileinfo, GD (JPEG/PNG/WebP), DOM and libxml are available. No PHP ini changes were necessary.

`config/livewire.php` explicitly limits temporary uploads to `max:10240` KiB on the private local disk. Application validation separately checks both the reported size and actual bytes. Existing account photos and project covers retain their own smaller limits.

This verification covers the local PHP runtime, application configuration and automated fake uploads. The final manual review must confirm the web server uses the expected PHP configuration and that any proxy/request limits allow 10 MiB uploads. No browser upload or reverse-proxy limit check has been performed.

## Content and resource safeguards

TXT files must contain plain UTF-8 text without binary control characters. Images must decode as the matching format and fit within 16,384 pixels per side, 40 megapixels and the available memory budget. Archive validation reads bounded member streams and metadata without extraction, checks integrity, and rejects encrypted, malformed or excessive packages. ZIP/Office limits are 4,096 entries, 64 MiB per expanded member and 128 MiB total expanded content, with a compression-ratio safeguard. Office documents require the correct main part, content type and package relationship. XML parsing disables network access and rejects DTD/entity declarations; content types, package relationships and main XML are limited to 512 KiB, 256 KiB and 4 MiB respectively. Macro-enabled Office packages are unsupported.

Exact defensive processing limits are defined in `App\Services\DeliverableFiles`. A structurally supported file can be refused when it exceeds those processing limits; validation returns actionable feedback. PDF validation checks structural completeness, rather than providing a full PDF parser or a security scan.

## Durability and concurrency

Submissions lock the persisted account, project, assignment, optional milestone, deliverable and version allocation in order. Version numbers are unique per deliverable; the protected submission UUID is globally unique. Retrying a committed UUID returns its original version and timestamp. A new UUID is required for a new version.

Generated paths are collision-checked before writing. Failed transactions clean up new unreferenced files; committed versions are never removed by cleanup, including after a response failure. Earlier files are never replaced. Cleanup failures are reported for investigation if storage is unavailable.

SQLite/fake-storage tests exercise sequential retries and simulated failures. Real parallel MySQL testing remains deferred to an isolated test database, never development records.

## Immutable version reviews

Only the persisted owning Client may approve or request changes while the project is in progress. Approval requires explicit confirmation; requesting changes requires meaningful plain-text remarks. Remarks are trimmed, limited to 5,000 characters, and escaped on display. A version has at most one immutable review, with server-resolved reviewer, decision and UTC review time.

Reviews use the same account -> project -> assignment -> optional milestone -> deliverable -> version lock order as uploads, followed by the review record. A new review must target the latest unreviewed version. An upload that wins the assignment lock makes an older review form stale; an approval that wins closes further versions of that deliverable. Other deliverables remain available. A committed upload-token retry still returns its original version without creating new work.

Exact review retries by the original reviewer, with the same normalized remarks and decision, return the original review and timestamp, including after later revisions or project status changes. Conflicting decisions or remarks are refused. Only the known unique version-review constraint is eligible for conflict recovery; unrelated database errors propagate.

The current review state is derived from the latest version and its review, never a separately editable status. Earlier unreviewed versions are superseded. A changes request permits another immutable version with a response note; old files and reviews remain intact. Latest-version aggregate counts are scoped to the assignment and independent of pagination. Version approval does not change task progress, project status, agreed terms, or payment status.

Reviewer foreign keys restrict deletion. Account deletion checks review history before profile/avatar cleanup and returns validation feedback. Completed reviews and submitted versions reject ordinary model updates/deletion; there are no review edit/delete endpoints.
