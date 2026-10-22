# Documentation index

Start with the [project README](../README.md). No documentation index existed in the inspected checkout; this index collects the existing feature documents and new operational guides.

## Setup and operation

| Guide | Purpose |
| --- | --- |
| [Installation](installation.md) | Fresh Windows setup, existing-install updates, isolated checks, first-Admin gap |
| [User guide](user-guide.md) | Role workflows, statuses, privacy and irreversible actions |
| [Backup and recovery](backup-recovery.md) | Database/files/secrets, consistent capture, guarded disposable restore |
| [Hosting](hosting.md) | Deployment configuration, persistence, permissions, mail and runtime requirements |
| [Account email](account-email.md) | SMTP variables, trusted links, expiry, optional verification and delivery limits |
| [Handoff verification](handoff-verification.md) | Current commands actually exercised, cleanup, preservation and setup gaps |
| [Manual review](manual-review.md) | Deferred browser/device/accessibility/environment checklist |

## Implemented features

| Area | Detailed design |
| --- | --- |
| Login and account administration | [Role redirects](login-redirects.md), [accounts and suspension](admin-accounts.md) |
| Categories and Freelancer directory | [Categories](admin-categories.md), [profile publication/discovery](freelancer-discovery.md) |
| Work and payments | [Deliverable uploads/reviews](deliverable-uploads.md), [completion](project-completion.md), [external payments](external-payments.md) |
| Participant communication | [Messages](project-messaging.md), [notifications](in-app-notifications.md), [activity history](project-activity.md) |
| Feedback and cases | [Feedback](project-feedback.md), [disputes](project-disputes.md) |
| Cross-feature audit | [Workflow audit](workflow-audit.md) |
| Real database concurrency | [Isolated MySQL verification](mysql-verification.md) |

Older feature documents record evidence and deferred work at their own checkpoint. Later [MySQL verification](mysql-verification.md), [account-email verification](account-email.md) and [handoff verification](handoff-verification.md) supersede earlier statements that those particular checks had not run. None supersedes the pending browser review or claims an external mail provider has been verified.
