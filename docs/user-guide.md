# TaskBridge user guide

## Accounts and shared controls

Register at `/register` as a **Client** or **Freelancer**, then sign in at `/login`. Successful login goes through `/dashboard` to the saved role's dashboard. A stale intended URL cannot redirect you into another role's workspace. Admin registration and changing your own role are not offered.

The avatar menu opens **Profile** (`/settings/profile`, `settings.profile`) and **Password** (`/settings/password`, `settings.password`), and provides logout. Profile settings change your account name/email separately from **Save photo** and **Remove photo**. Photos accept genuine JPEG/PNG/WebP, at most 2 MB and 4096 × 4096; initials appear without a photo. Account photos are private unless a Freelancer separately consents to sharing through a published professional profile. Replacing a shared photo updates the version Clients see.

The compact header theme menu selects **Light**, **Dark** or **System** and persists the choice. The former `/settings/appearance` URL redirects to the dashboard. Account Profile settings are distinct from the Freelancer professional profile.

Email verification is optional. An unverified account may request a link from Profile settings or `/verify-email`; changing the email clears verification. Links expire after the displayed interval (currently 60 minutes). If logged out when opening verification, sign in to the matching account and reopen the link. `/forgot-password` always gives a generic response; reset links are single-use and currently expire after 60 minutes. Successful password changes/recovery revoke older sessions; a suspended account is not reactivated by recovery. External delivery depends on the operator's SMTP setup.

Account deletion asks for the password. Recorded project/proposal/work/payment/message/dispute/feedback/administrative history can prevent deletion; refusal preserves the profile and photo. The last active Admin cannot delete their account. There is no history-erasing workaround in the UI. Suspended accounts cannot access authenticated work; an active counterparty retains their own authorized access. Reactivation requires a fresh login.

## Conventions

- Money is **LKR**, stored and displayed precisely to two decimal places. Project/offer fixed budgets range from **1.00 to 9,999,999,999.99**. A Freelancer's optional hourly rate may be zero; absent means unspecified, not zero.
- Work deadlines and due dates are calendar dates, without a time-of-day conversion. Eligibility uses **Asia/Colombo**. Event timestamps are stored in UTC and shown in Sri Lankan time. Feedback uses an exact elapsed 14-day UTC window, displayed locally.
- Descriptions, cover letters, notes, reviews and messages are plain text. Markup is escaped. A percent-complete figure is **task progress**, not Client approval, project completion or payment.
- Read access depends on role and ownership, not on knowing a URL. Admins do not gain general access to private projects. Browser back/refresh does not bypass restrictions.

## Client: publish a brief and select a Freelancer

1. Open **Your projects**, `/client/projects` (`client.projects.index`), or **Create project**, `/client/projects/create`. Save a title (150 characters), description (10,000), active category, fixed LKR budget and a deadline tomorrow or later in Sri Lanka. An optional cover can be saved with the draft, replaced or removed while it remains editable; covers use validated JPEG/PNG/WebP images (2 MB, 4096 × 4096 maximum).
2. **Save draft** keeps the project private. Review it at `/client/projects/{project}` (`client.projects.show`); **Edit draft** returns to the saved values. Only the owning Client may edit, and only while it remains a draft. If the saved category was deactivated, choose an active one before saving/publishing.
3. **Publish project** requires confirmation and rechecks the saved brief. It opens eligible discovery without changing your agreed terms (no assignment exists yet). Published briefs cannot be edited through the draft editor. Refreshing or opening a URL never publishes.
4. **View proposals** opens `/client/projects/{project}/proposals`, with status filters, newest/offer/deadline sorting, counts and pagination. A detail link shows that Freelancer's full cover letter and offered price/date against the advertised terms. A published professional-profile link appears only when the applicant is currently visible to Clients; private account details remain hidden.
5. **Accept proposal** displays a confirmation. Selecting a submitted offer creates the one durable assignment, copies its offer exactly into the agreed budget/deadline, changes the project to **In progress**, accepts that proposal and rejects only other submitted proposals. It requires an eligible open project and a still-valid offer. Review the comparison carefully: there is no reassignment, acceptance undo or agreed-term editor. Repeating the same confirmed selection preserves the original assignment; another selection is refused.

**Find freelancers**, `/client/freelancers` (`client.freelancers.index`), searches only published active Freelancer profiles. Filter by location, skills and optional hourly rate; open `/client/freelancers/{profile}` for the professional profile. Publication is not anonymous internet access. There is no direct hire or arbitrary message button: hiring proceeds through a project and proposal.

## Freelancer: profile, discovery and proposals

1. **Freelancer profile**, `/freelancer/profile` (`freelancer.profile.edit`), saves your professional headline, biography, location, optional HTTP/HTTPS portfolio URL, optional LKR rate and 1–10 skills. The headline and biography are required (120/2,000 characters). Saving does not publish or change photo consent.
2. Preview at `/freelancer/profile/preview`, then explicitly publish **Visible to Clients**. New profiles are private. Editing a published profile changes what Clients can see. Unpublishing removes directory/detail access without deleting your assignments/proposal history. **Show my account photo to Clients** is an independent consent choice; revoke it independently. Already viewed/downloaded copies on someone else's device cannot be recalled.
3. **Browse projects**, `/freelancer/projects` (`freelancer.projects.index`), searches title/description with category, LKR range, sort and reset controls. Projects must be open, actually published, owned by an active Client, in an active category, and not past their Sri Lankan deadline. Direct detail URLs enforce the same eligibility.
4. On an eligible detail page submit a plain-text cover letter (up to 5,000 characters), your fixed LKR offer and completion date. The offer may differ from the advertised budget. Completion must be tomorrow or later and no later than the project deadline. If the deadline is today, discovery may still show the project but there is no valid proposal completion date. Self-applications are refused. A public profile is not required.
5. Submission does not assign the project or guarantee acceptance. There is one proposal per project/Freelancer; duplicate attempts link to the original. **View your proposal** replaces the submission action. **My proposals**, `/freelancer/proposals`, retains your private read-only history even after discovery closes. Proposal editing, individual withdrawal and Client single-offer rejection are not implemented.
6. After acceptance use **Assigned projects**, `/freelancer/assigned-projects` (`freelancer.assignments.index`), then `/freelancer/assigned-projects/{assignment}` for the original brief, agreed terms and work links. Unselected applicants cannot access the assigned workspace.

## Both participants: planning, delivery and completion

**Project plan**, `/projects/{project}/plan` (`projects.plan`), is private to the owning Client and assigned Freelancer. The Client creates/edits milestones and task details; both parties can change task status while work is in progress. Dates must fit the interval from assignment's Sri Lankan date through the agreed deadline. Attached tasks must also be due by their milestone; shortening a milestone cannot invalidate an existing task. Historical/overdue dates inside the interval are allowed.

Tasks are **To do**, **In progress** or **Done**. Finishing records a server timestamp; repeating Done retains it; reopening clears it. Detail edits do not change status. Completed/total counts include the whole persisted scope, regardless of filters. No tasks means no invented progress. These controls do not complete milestones or approve deliverables. Completed milestones lock attached work; completed projects lock new work mutations. Task/milestone deletion and manual reordering are not offered.

**Deliverables**, `/projects/{project}/deliverables` (`projects.deliverables.index`), lets the assigned Freelancer submit files and immutable later versions. Supported types: PDF, UTF-8 text, JPEG/PNG/WebP, ZIP, DOCX/XLSX/PPTX, up to **10 MB**; content and archive validation may reject files even when their suffix is allowed. Accepted types are not a malware-scanning guarantee. Use the authorized **Download** links, including after completion.

The owning Client reviews the **latest** submitted version: **Request changes** requires meaningful remarks; **Approve version** has an explicit confirmation and optional remarks (maximum 5,000). Approval and changes requests are immutable. A revision after requested changes can include a response note; previous files/reviews remain available. A new version before review supersedes the older unreviewed version. Approval blocks further versions of that deliverable; it does not approve the whole project or report payment. Other deliverables remain independent.

The Client explicitly completes each milestone. Attached tasks must be Done and attached deliverables' latest versions approved. An otherwise empty milestone needs a meaningful outcome note. Then **Completion**, `/projects/{project}/completion` (`projects.completion`), explains project readiness: all milestones completed, all tasks including ungrouped tasks Done, at least one deliverable, and all latest versions approved. An active dispute blocks new completion. Confirmation records immutable completion time/actor/note. There is no reopening action; history/downloads remain authorized.

## Payment, communication, feedback and disputes

**Payment**, `/projects/{project}/payment` (`projects.payment`), tracks **external full payment**. The Client reports the saved full agreed amount, method (bank transfer/cash/other), paid-on date and optional reference/note. Paid-on must be between assignment's Sri Lankan date and today. Read the confirmation: the report cannot be edited, deleted or replaced. The Freelancer separately **acknowledges receipt**. Pending → Client-reported payment → receipt acknowledged are distinct records; no bank verification, transfer, escrow, installment, refund or automatic payment occurs. Work completion and payment remain independent.

**Messages**, `/messages`, opens private assigned conversations at `/projects/{project}/messages`. Both participants can send up to 5,000 characters, with bounded rate limits; sent messages cannot be edited/deleted and have no attachments. Unread counters are per account; viewing older history does not falsely mark newer messages read. There is polling, not WebSockets or presence/read receipts. Assigned historical conversations remain accessible in allowed project statuses. Admin conversation access is limited to authorized dispute context.

**Notifications**, `/notifications` (`notifications.index`), and the header bell contain personal in-app alerts. Opening rechecks destination authorization; inaccessible items safely fall back to the inbox. Read/unread controls affect only your alerts. Activity is separate: `/projects/{project}/activity` (`projects.activity`) displays append-only, minimal events. The assigned Freelancer sees shared history from assignment onward, not the Client's earlier private publication activity. Empty history honestly states that activity will appear as the project progresses; old events are not fabricated.

**Feedback**, `/projects/{project}/feedback` (`projects.feedback`), opens after recorded project completion for **14 elapsed days**. Each original participant may submit once: 1–5 stars and optional comment (2,000 characters), with permanent-submission confirmation. You can see your own feedback; the other party's stays blind until both submit or the exact window closes. Full comments remain private to the project; released aggregate count/average can appear in authorized profile/discovery views. Payment/dispute state does not control release. Feedback cannot be edited or withdrawn.

**Disputes**, `/disputes`, and **Open dispute**, `/projects/{project}/disputes/create`, are available to participants of an assignment, including completed history. Opening reasons and later statements are permanent. There can be only one active case per assignment. An active dispute blocks new milestone/project completion but does not stop normal authorized work, payment tracking or historical reads. A permitted Admin may request clarification, then record **Resolved** or **Dismissed** with rationale. Closing the case removes that completion blocker; it does not transfer/refund money, change terms or complete/reopen the project. Later cases can be opened separately.

## Admin workspace

The Admin dashboard is `/admin/dashboard`. **Categories**, `/admin/categories` (`admin.categories.index`), supports creation, descriptive edits and separate activate/deactivate confirmations. Slugs remain stable, deletion is unavailable, and repeat reference seeding preserves edits. Deactivation blocks draft selection/publication, discovery, proposals and new acceptance in that category; it does not erase existing projects or disable assigned work. Real affected counts and immutable category audit history support each decision.

**Accounts**, `/admin/accounts` (`admin.accounts.index`), searches/filter accounts and opens `/admin/accounts/{account}` for status and audit history. Confirmed suspension/reactivation applies to Clients/Freelancers only, with internal reasons. It revokes their old sessions/remember tokens while preserving work/history. Reactivation does not revive revoked sessions. Admin accounts have no status-changing controls; role editing, impersonation, password replacement and administrative deletion are not offered.

**Disputes**, `/admin/disputes`, is the explicit exception permitting case context, related conversation and scoped downloads. Admins cannot resolve cases where they are a participant/opener. There is no unrestricted Admin project browser or private-photo access. Internal account reasons never become participant activity/notification content.

## Status reference and irreversible actions

| Record | Meaning |
| --- | --- |
| Project `draft` / `open` / `in_progress` / `completed` | Private brief / published intake / one assigned Freelancer / Client-confirmed completion |
| Project `cancelled` | Reserved historical status; no cancellation control is implemented |
| Proposal `submitted` / `accepted` / `rejected` | Awaiting Client choice / selected for assignment / closed by another accepted offer |
| Proposal `withdrawn` | Reserved historical status; no withdrawal control is implemented |
| Deliverable state | Derived from latest version: Awaiting review, Changes requested or Approved; an older unreviewed version is superseded |
| Milestone completion | Explicit Client confirmation, separate from task percentages |
| Payment/receipt | External report and independent acknowledgment, separate from project status |

Publication closes draft editing; acceptance fixes the selected Freelancer and agreed terms; version uploads/reviews, completion, payment records, messages, feedback and dispute decisions preserve history. Exact retries retain original results; altered retries or stale forms may require a reload. Read the visible validation explanation and reload current data rather than trying to bypass it. No browser, mobile, theme or keyboard verification is claimed by this guide.
