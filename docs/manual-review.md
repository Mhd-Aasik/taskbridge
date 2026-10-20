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
- Parallel acceptance/submission, milestone additions, and task detail/status updates against an isolated MySQL test database; never use development records for concurrency tests.
- Long titles, display names, cover letters, large LKR amounts, and Sri Lankan calendar dates and timestamps on small screens.
- Identify the resource URL for the previously reported unused-preload warning. The earlier console screenshot included an extension script error; the console has not been verified clear.

Automated rendering tests and source review do not complete these manual checks. Simulated race tests do not constitute real parallel MySQL testing.
