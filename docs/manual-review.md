# Manual review carried forward

Manual checks are deferred to the final project review. Continue development and automated verification without waiting for browser checks. The previously denied browser preview must not be retried.

## Confirmed by the user

- Freelancer project browsing, filtering, and reset work.
- Account photo upload, replacement, removal, avatar refresh, and persistence after refresh work.

These confirmations do not cover all devices, themes, or keyboard interactions in subsequent changes.

## Pending final review

- Current Client, Freelancer, and Admin pages on desktop and mobile in light, dark, and system themes, including the mobile navigation menu.
- Keyboard navigation, visible focus, labels, validation errors, retained input, loading states, and success announcements.
- Proposal submission, duplicate feedback, and own proposal history, including projects no longer in discovery.
- Private Client proposal lists and details, status filters, sort order, pagination, real counts, and return links.
- Client acceptance confirmation, advertised/offered term comparison, cancel/confirm keyboard focus, loading feedback, validation, retry messages, and project/proposal status refresh.
- Both parties' assignment summaries, Freelancer assigned-project list/detail, pagination, history links, real dashboard counts, and empty states. Check that only necessary display information is visible.
- Private project planning for the owning Client and assigned Freelancer: create/edit/cancel flows, retained validation input, read-only history, date bounds, overdue labels, escaped long text, and links back to each party's workspace. Verify keyboard focus when loading a milestone into the editor and save announcements in both themes and on mobile.
- Project tasks in both participant workspaces: create/edit/move between milestones and ungrouped, explicit status controls and reopening, retained input on stale saves, task progress with and without filtering, overall/milestone counts, completion times, keyboard focus, loading/error/success announcements, and narrow-screen layout in both themes. Task progress must not imply deliverable approval or project acceptance.
- Optional private project covers: choose/discard/private local preview, upload progress and validation, save with a new draft, replace/remove in an owned draft, persistence after refresh, and refreshed images after replacement. Check compact list thumbnails, detail crops, missing-file/category fallbacks, long titles and terms on mobile and desktop in both themes. Verify keyboard controls, confirmation/error announcements, and authorised cover access through discovery, proposal history and assignment history. Confirm published drafts remain locked. No cover browser checks have been performed.
- Private deliverables for both participants: create with optional milestone, select/upload progress, retained validation input, new versions and complete immutable history, safe attachment downloads, Sri Lankan times, pagination, response-failure retry feedback, and ended-project read-only access. Check long filenames/notes, mobile layout, both themes, keyboard focus, loading states and announcements. Confirm an actual 10 MiB browser upload and the web/proxy PHP limits described in `docs/deliverable-uploads.md`. No deliverable browser checks have been performed. Uploads do not imply Client approval.
- Immutable version reviews: Client approval confirmation and cancel/confirm keyboard focus; optional approval remarks, required change-request remarks, retained input and validation; stale-version refresh without silently retargeting a decision; exact retry/conflict feedback; both parties' full escaped review history and Sri Lankan times; latest-version states/counts across pagination; revision response notes and downloads after changes requests; closed upload controls after approval; and ended-project read-only history. Check mobile and both themes, long remarks, loading feedback, and success announcements. Approval must remain distinct from task progress, whole-project completion, and payment. No review browser checks have been performed.
- Parallel upload versus review and review retries against an isolated MySQL test database. Current automated coverage uses sequential simulated interleavings and injected conflicts, not real parallel MySQL execution; do not use development records.
- Parallel acceptance/submission, milestone additions, task detail/status updates, cover replacement/removal versus publication, and deliverable version allocation/retries against an isolated MySQL test database; never use development records for concurrency tests.
- Long titles, display names, cover letters, large LKR amounts, and Sri Lankan calendar dates and timestamps on small screens.
- Identify the resource URL for the previously reported unused-preload warning. The earlier console screenshot included an extension script error; the console has not been verified clear.

Automated rendering tests and source review do not complete these manual checks. Simulated race tests do not constitute real parallel MySQL testing.
