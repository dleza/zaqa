# 05 — Verification Workflow and Operations

## Objective
Implement the complete verification operating model, including applications pool intake, categorization, Level 2 assignment, Level 1 processing, send-back flows, resubmission, final review, and issuance or rejection decisions.

## Verification team roles
Support at minimum:
- Verification Officer Level 1
- Verification Officer Level 2

Recommended additions:
- Verification Supervisor
- Read-only Auditor

## Source workflow requirements translated
The system must:
- allow administrators to create verification team accounts
- receive all applications and qualification details for review
- place all applications in an applications pool
- categorize foreign applications by country of award
- categorize local applications by awarding institution
- allow “Other” when country or awarding institution is not listed
- allow all verification team members view access to the pool
- maintain at least two verification levels
- allow Level 2 to assign applications to Level 1
- ensure Level 1 only processes applications assigned by Level 2
- allow Level 1 and Level 2 to send back applications with comments
- allow applicant resubmission after amendment
- allow Level 2 to review, amend, and send back to Level 1 with comment
- allow Level 2 to issue a Certificate of Recognition or Notice of Rejection
- allow Level 2 to change status of an issued certificate with a comment
- notify the applicant when the certificate is issued and process ends

## Intake into verification pool (qualification-based)
Verification tasks enter the pool only when:
- submission is valid
- payment status is confirmed according to business rules

**Important:** the pool is arranged by **qualification verification item**, not by parent application.
One application can contain many qualification items, and each item is an independent verification task.

## Lifecycle tracking (required)
Verification operations must record business-readable lifecycle events per application, including:
- submission received / acknowledged
- assignment to reviewers (who/when)
- review started / review completed
- sent back to applicant (reason/comment)
- resubmission received
- decision made (approved/rejected)
- certificate issued / completed

Internal users must have a dedicated tracking view with a full timeline (internal + applicant-visible) and actor information.

### Pool characteristics
All verification users may view pool entries, but action permissions differ by role.

Pool rows represent **one qualification item**. Columns include:
- applicant name
- application reference/number
- qualification type
- qualification title
- **Awarding Institution**
- country of award
- local/foreign (per qualification)
- uploaded qualification document indicators (per qualification)
- foreign consent indicator (per qualification)
- assigned verifier (per qualification)
- qualification verification status (per qualification)
- parent application payment status
- submitted date

## Categorization rules (per qualification)
### Foreign qualification items
Sort/group by:
- country of award

### Local qualification items
Sort/group by:
- **Awarding Institution**

### Country category view (admin)
The **Category View by Country** groups applications by country of award and **includes Zambia by default**.
Staff can optionally use a **Hide Zambia** toggle when they want to focus on foreign applications only.

### Missing reference values
If country or awarding institution is not listed:
- applicant selects Other
- store explicit other text
- keep it visible to verification staff for downstream classification

## Verification levels
### Level 2 responsibilities
- review intake
- assess completeness
- assign applications to Level 1
- review Level 1 outcomes
- amend and return work to Level 1
- send application back to applicant where necessary
- make final decision
- issue certificate or rejection notice
- update issued certificate status with comment

### Level 1 responsibilities
- view only assigned work for action
- review applicant and qualification details
- request amendments through send-back comments
- process application according to workflow rules
- return findings to Level 2

## Assignment rules
- only Level 2 can assign to Level 1
- assignment must record assigner, assignee, timestamp, and optional comment
- only one active assignment per **qualification item**
- reassignment must preserve previous assignment history
- Level 1 cannot self-assign
- unassigned qualification items remain visible but non-actionable for Level 1

## Workflow states
Recommended internal workflow states:
- Submitted
- Awaiting Assignment
- Assigned to Level 1
- Under Level 1 Review
- Returned to Applicant
- Resubmitted
- Under Level 2 Review
- Approved for Certificate
- Rejected
- Certificate Issued
- Certificate Reissued
- Closed

## Send-back flow
### From verification to applicant
Both Level 1 and Level 2 can send back an application.

Rules:
- comment is mandatory
- comment must be applicant-visible
- application moves to returned state
- applicant is notified by email and or phone
- applicant can amend and resubmit
- resubmission preserves previous history and document versions

### From Level 2 back to Level 1
Level 2 can also amend and send work back to Level 1 with a comment.

Rules:
- comment required
- record workflow transition
- keep internal review comment history
- notify Level 1 assignee

## Review screen requirements
Each qualification review screen should show:
- applicant summary
- parent application reference + payment state
- qualification item details (type, locality, awarding institution, country)
- qualification item documents with preview
- foreign consent documents where applicable
- status history
- assignment history
- internal comments
- applicant-visible comments
- payment confirmation summary
- SLA countdown
- action buttons governed by policy

## Decision rules
### Approve
Level 2 may approve only when:
- required application data is complete
- required supporting documents are present
- review has reached Level 2
- payment is confirmed
- any return cycles are resolved

### Reject
Level 2 may reject when the merits of the application do not satisfy requirements.

Implementation:
- require reason
- create formal rejection outcome
- notify applicant
- preserve review evidence and history

### Issue certificate
Only Level 2 may issue the final certificate of recognition after approval.

## SLA handling
The source document specifies service times:
- 14 days for local qualifications after submission
- 60 days for foreign qualifications after submission

Implementation:
- compute service deadline at submission or payment-confirmed intake according to business rule
- show countdown in verification and applicant views
- notify applicant when service time has been exceeded
- expose overdue filter for operations team

## Notification points
Trigger notifications when:
- application enters processing
- application is sent back
- application is resubmitted
- application is assigned
- certificate is issued
- rejection is finalized
- SLA is breached

## Backend services
Create:
- `ApplicationsPoolService`
- `AssignmentService`
- `VerificationReviewService`
- `ApplicationWorkflowService`
- `SlaService`
- `VerificationSearchService`

## Policy rules
- Level 1 can act only on assigned applications
- Level 2 can assign, final-review, approve, reject, and issue
- both Level 1 and Level 2 can comment and send back to applicant
- all sensitive transitions require audit logs
- issued certificate status changes require comment and separate audit entry

## UI page map
### Verification module pages
- Applications Pool
- Category View by Country
- Category View by Awarding Institution
- Assigned to Me
- Application Review Detail
- Send Back Dialog
- Assignment Dialog
- Final Decision Dialog
- Overdue Applications
- Review Activity Log

## Definition of Done
This phase is complete when:
- all submitted applications flow into a structured applications pool
- local and foreign categorization works exactly as required
- Level 2 assignment to Level 1 is fully enforced
- Level 1 cannot process unassigned work
- send-back, resubmission, amendment, and internal review loops are complete
- final approval, rejection, and issuance actions are correctly restricted
- SLA timing, overdue flags, and applicant notifications are operational
- every state change is recorded in workflow and audit history
- there are no missing workflow branches or placeholder decisions
