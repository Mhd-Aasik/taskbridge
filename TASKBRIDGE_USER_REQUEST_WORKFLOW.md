# TaskBridge – User Request and Requirement Workflow

TaskBridge is a web-based project management system that connects Clients and Freelancers. This document explains how a user request moves through the system and how a Client can provide project requirements.

> **Implementation note:** This document describes the intended workflow. Verify screens, status names, notifications, and database behavior against the current TaskBridge code before presenting them as implemented.

## 1. User Request Flow

A user request is a project request created by a Client who needs work completed by a Freelancer.

### Flow

```text
Client
  |
  v
Login / Register
  |
  v
Open Client Dashboard
  |
  v
Create a New Project
  |
  v
Enter Project Details and Requirements
  |
  v
Submit / Publish Project
  |
  v
Freelancers View Project
  |
  v
Freelancers Submit Proposals
  |
  v
Client Reviews Proposals
  |
  v
Client Selects a Freelancer
  |
  v
Project Work Begins
  |
  v
Milestones / Tasks and Deliverables
  |
  v
Client Reviews Submitted Work
  |
  v
Project Completion
```

### Step-by-Step Explanation

1. **Access the account:** The Client logs in or creates an account and completes any required email verification.
2. **Create a project:** The Client opens the project creation page from the dashboard.
3. **Describe the project:** The Client enters a project title, description, category, budget, expected timeline, and other requested details.
4. **Provide requirements:** The Client explains the expected outcome, required features, constraints, and acceptance criteria.
5. **Submit the request:** The Client reviews the information and submits or publishes the project.
6. **Freelancer discovery:** Freelancers browse available projects and decide whether they are suitable.
7. **Proposal submission:** Interested Freelancers submit proposals describing their approach, price, and estimated delivery time.
8. **Proposal review:** The Client compares proposals and selects a Freelancer.
9. **Plan the work:** The Client and Freelancer agree on milestones, tasks, and deliverables.
10. **Work and review:** The Freelancer completes work and submits deliverables. The Client reviews them and requests changes or accepts them.
11. **Complete the project:** Once agreed work is accepted, the project can be marked complete according to the system workflow.

## 2. How to Submit a Project Requirement Request

A good requirement request clearly explains what needs to be built, why it is needed, and how completion will be judged.

### Recommended Requirement Information

| Field | What to provide | Example |
|---|---|---|
| Project title | Short, clear project name | Build a Freelancer Portfolio Website |
| Project overview | Brief explanation of the project | A website where freelancers can display skills and work samples |
| Objective | The problem or goal | Help freelancers present their services to potential clients |
| Required features | Main functions the system must provide | Registration, login, portfolio management, contact form |
| User roles | Types of users and their permissions | Visitor and Freelancer |
| Deliverables | Items expected at the end | Responsive website, source code, setup instructions |
| Budget | Expected project budget or range | $500–$800 |
| Timeline | Desired start and completion dates | 3 weeks |
| Acceptance criteria | Conditions for accepting the work | Users can register, add portfolio items, and view them on mobile |
| Attachments | Supporting files, sketches, or references | Design mockup or sample website |

## 3. Example Requirement Request

### Project Title
Build a Freelancer Portfolio Website

### Project Overview
I need a responsive website where freelancers can create profiles and display their skills, experience, and completed projects.

### Objective
The goal is to help freelancers present their work professionally and allow potential clients to contact them.

### Required Features
- User registration and login
- Freelancer profile creation and editing
- Skills and experience section
- Portfolio project upload
- Responsive design for desktop and mobile
- Contact form

### Deliverables
- Complete responsive website
- Source code
- Database setup instructions
- Basic user guide

### Timeline
The expected completion time is three weeks.

### Acceptance Criteria
- Users can register and log in.
- Freelancers can create and update their profiles.
- Freelancers can add portfolio items.
- The website works on desktop and mobile screens.
- The contact form validates required fields and displays a confirmation.

## 4. Requirement Request Data Flow

```text
Client enters project information
          |
          v
Browser validates required fields
          |
          v
Request sent to Laravel
          |
          v
Laravel validates and authorizes request
          |
          v
Project information saved to database
          |
          v
Project status set according to workflow
          |
          v
Confirmation shown to Client
          |
          v
Published project becomes visible to eligible Freelancers
```

## 5. Presentation Summary

In TaskBridge, a Client creates a project request by entering project details, requirements, budget, timeline, and expected deliverables. Laravel validates the request and stores the project information in the database. Once the project is published, Freelancers can view it and submit proposals. The Client reviews proposals, selects a Freelancer, and the work proceeds through milestones, tasks, deliverables, and review until completion.
