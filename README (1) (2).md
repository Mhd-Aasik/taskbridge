# TaskBridge – Account and Communication Features

TaskBridge is a web-based project management system that connects Clients and Freelancers. This document describes its account and communication features.

> **Note:** Confirm each feature against the current project code before describing it as implemented. The flows below describe the intended/typical behavior.

## 1. Login

The login feature allows registered users to securely access TaskBridge.

### Data Flow

1. The user enters their email address and password.
2. The browser sends the login request to the Laravel application.
3. Laravel validates the submitted information.
4. Laravel checks the credentials against the database.
5. If the credentials are correct and the account is verified, the user is authenticated.
6. The system identifies the user's role: Client, Freelancer, or Admin.
7. The user is redirected to the appropriate dashboard.
8. If the information is incorrect, the system displays an error message.

### Purpose

Login protects user accounts and ensures users access features according to their roles.

## 2. Gmail Email Verification

Email verification helps confirm that a user has access to the email address provided during registration.

### Data Flow

1. The user enters their name, email address, and password during registration.
2. Laravel validates the registration information.
3. Laravel securely hashes the password.
4. The database stores the new account with an unverified status.
5. Laravel generates an email verification link or token.
6. The email is sent through the configured Gmail SMTP service.
7. The user opens the email and clicks the verification link.
8. Laravel validates the link or token.
9. The database updates the account status to verified.
10. The user sees a verification success message and can access the account.

### Purpose

Email verification helps confirm that users own the email address they registered with.

## 3. Notifications

The notification feature informs users about important activities and updates in TaskBridge.

### Examples of Notifications

- A Client receives a notification when a Freelancer submits a proposal.
- A Freelancer receives a notification when a Client accepts or rejects a proposal.
- A user receives a notification when a project, milestone, or task is updated.
- A Freelancer receives a notification when a Client reviews submitted work.
- A user receives a notification about a dispute or administrative update.

### Data Flow

1. An event occurs in TaskBridge, such as a proposal submission or milestone update.
2. Laravel identifies the user or users who should receive the notification.
3. Laravel creates the notification.
4. The notification is stored in the database or sent through a configured notification channel.
5. The user opens the notification area in the application.
6. The user views the notification and may follow a link to the related project or task.
7. The notification can be marked as read.

### Purpose

Notifications keep Clients, Freelancers, and Admins informed about relevant project activities.

## 4. Password Management

Password management allows users to recover access to their accounts and maintain account security.

### Forgot Password Data Flow

1. The user selects **Forgot Password** on the login page.
2. The user enters their registered email address.
3. Laravel validates the email address.
4. Laravel generates a password-reset link or token.
5. The reset email is sent through the configured Gmail SMTP service.
6. The user opens the email and clicks the reset link.
7. Laravel validates the link or token.
8. The user enters and confirms a new password.
9. Laravel validates and securely hashes the new password.
10. The database updates the password.
11. The user can log in using the new password.

### Change Password Data Flow

1. The authenticated user opens Account Settings.
2. The user enters their current password and new password.
3. Laravel validates the current password and new password.
4. Laravel securely hashes the new password.
5. The database updates the password.
6. The system displays a confirmation message.

### Purpose

Password management helps users recover their accounts and update their passwords securely.

## 5. Account Settings

Account Settings allows users to view and manage their personal account information.

### Examples of Account Settings

- Update name and profile information.
- Update contact information.
- Change password.
- Manage notification preferences, if supported.
- View account status.
- Log out of the account.

### Data Flow

1. The user opens Account Settings.
2. Laravel retrieves the user's account information from the database.
3. The application displays the current information.
4. The user edits the required fields.
5. The browser sends the updated information to Laravel.
6. Laravel validates the submitted information.
7. The database updates the user's account details.
8. The application displays a success message.

### Purpose

Account Settings gives users control over their account information and helps keep their details up to date.

## 6. Overall System Flow

```text
User
 |
 v
TaskBridge Browser
 |
 v
Laravel Application
 |
 +---- Login
 |
 +---- Gmail Email Verification
 |
 +---- Notifications
 |
 +---- Password Management
 |
 +---- Account Settings
 |
 v
Database
 |
 v
Response to User
```

## Presentation Summary

TaskBridge provides login, Gmail email verification, notifications, password management, and account settings. Laravel processes user requests and communicates with the database. Gmail SMTP can be used to send verification and password-reset emails. Notifications help users stay informed about project activities, while account settings allow them to manage their personal information.
