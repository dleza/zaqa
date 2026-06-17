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

## Consent forms (institution-specific for foreign qualifications)
### Admin configuration
Admins can upload an optional **Institution Consent Form** for each **Awarding Institution**. This is the template applicants must download when verifying a foreign qualification from that institution.

### Applicant behavior (per qualification item)
- If the selected awarding institution is **Zambian/local**, no signed consent upload is required for that qualification item (local embedded consent applies as normal).
- If the selected awarding institution is **foreign (non-Zambian)**:
  - the applicant must **download the institution consent form**, sign it, and **upload the signed copy**
  - this is enforced **per qualification item** (mixed local + foreign in one application is supported)
  - if no institution consent form has been configured by admin, the applicant is blocked from submission/payment and shown a clear warning to contact support or select another institution

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
- Awaiting Auto-Verification
- Awaiting Assignment
- Assigned to Level 1
- Under Level 1 Review
- Returned to Applicant
- Resubmitted
- Under Level 2 Review
- Auto Verified Pending Level 2
- Approved for Certificate
- Rejected
- Certificate Issued
- Certificate Reissued
- Closed

## Auto-verification (Learner Achievement Records)
When an application is **paid and payment satisfaction is confirmed**, the system automatically:
- locks the application from applicant edits
- submits the application into verification
- transitions each qualification into `awaiting_auto_verification`
- dispatches an asynchronous job per qualification to attempt auto-verification

### Learner records module
Auto-verification uses **only** internal **Learner Achievement Records** (`learner_records`). No external institution systems are called during auto-verification.

Records are populated primarily via:
- admin Excel imports (`learner_record_imports`) — optional `Classification` column for award/result values (Credit, Merit, Distinction, etc.); not used in matching
- institution push API (`/api/institution/v1`)
- institution pull lookup ingestion when invoked manually or in future workflows (not during auto-verification)
- (optional) manual/admin entry

### Matching threshold and safety
The matching engine produces a confidence score (0–100).

Config:
- `AUTO_VERIFICATION_ENABLED` (default true)
- `AUTO_VERIFICATION_THRESHOLD` (default 70)
- `AUTO_VERIFICATION_AUTO_ISSUE_ENABLED` (default false)

Safety rules:
- never auto-verify using only title, only institution, only year, or institution+year
- require “strong evidence” such as Student ID, Certificate No, NRC, or Passport
- ambiguous outcomes must not be auto-issued

### Outcomes
For each qualification in `awaiting_auto_verification`:
- **Matched and safe** (confidence ≥ threshold, non-ambiguous):
  - qualification is linked to the matched learner record
  - qualification moves to:
    - `auto_verified_pending_level2` when auto-issue is disabled (default), or
    - `approved_for_certificate` and then auto-issued when auto-issue is enabled
- **Possible / Ambiguous**:
  - if confidence ≥ threshold → route to `auto_verified_pending_level2`
  - otherwise → fall back to `awaiting_assignment`
- **Not found / error**:
  - fall back directly to `awaiting_assignment` (no external institution lookup)
  - attempt Level 1 category-based auto-assignment when configured

All auto-verification attempts are audited in `learner_record_match_attempts`.

### Stuck qualifications (legacy institution pull dispatch)

Before this change, some qualifications may have been left in `awaiting_auto_verification` while an institution pull lookup was dispatched but not completed (`institution_pull_lookup_dispatched_at` set, `institution_pull_lookup_attempted_at` null).

**Identify stuck rows (read-only):**

```sql
SELECT id, application_id, verification_state,
       institution_pull_lookup_dispatched_at,
       institution_pull_lookup_attempted_at
FROM qualifications
WHERE verification_state = 'awaiting_auto_verification'
  AND institution_pull_lookup_dispatched_at IS NOT NULL
  AND institution_pull_lookup_attempted_at IS NULL;
```

**Safe remediation (review each row first):**

1. Clear pull-in-progress flags and re-queue auto-verification:

```sql
-- Example per qualification id — run only after manual review
UPDATE qualifications
SET institution_pull_lookup_dispatched_at = NULL,
    institution_pull_lookup_status = NULL,
    institution_pull_lookup_last_error = NULL
WHERE id = ?;
```

Then dispatch `ProcessQualificationAutoVerificationJob` for that qualification (e.g. via tinker or admin recheck).

2. Or route directly to Level 1 if auto-verification already ran and only the pull wait blocked progress:

```sql
UPDATE qualifications
SET verification_state = 'awaiting_assignment',
    institution_pull_lookup_dispatched_at = NULL,
    institution_pull_lookup_status = NULL,
    institution_pull_lookup_last_error = NULL
WHERE id = ?;
```

Then run Level 1 auto-assignment or assign manually as appropriate.

Do not run bulk updates without reviewing affected applications.

## Level 1 category-based auto-assignment
When auto-verification does not produce a safe internal match, the qualification falls back to the Level 1 manual workflow.

To reduce manual triage effort, the system supports **category-based auto-assignment** to Level 1 officers.

### Category pools
Assignment categories are **pools** that Level 2 / Super Admin configure:
- **Foreign qualifications** route by **Country of award**
  - one category can include **multiple countries**
- **Local qualifications** route by **Awarding Institution**
  - one category can include **multiple awarding institutions**

### No-overlap rule (deterministic routing)
To keep routing deterministic and avoid ambiguous assignments:
- a given **country** may belong to **only one active** foreign assignment category
- a given **awarding institution** may belong to **only one active** local assignment category

Inactive categories may retain mappings, but cannot be reactivated if their mappings conflict with another active category.

### Fallback behavior
If no active category exists (or no eligible Level 1 officer is available):
- the qualification remains in `awaiting_assignment`
- the failure reason is stored for Level 2 / Super Admin visibility
- the pipeline must not crash

## Level 2 category-based auto-assignment
After a Level 1 officer completes review and submits the qualification forward, the system attempts **category-based auto-assignment** to Level 2 officers attached to the same assignment category.

### Category officer pools
Each assignment category supports two officer pools on the shared `verification_assignment_category_user` pivot:
- `review_level = level1` — used for Level 1 auto-assignment after auto-verification fallback
- `review_level = level2` — used when Level 1 completes review

Admins manage both pools from **Pending Verification → Assignment Categories**.

### Level 2 fairness
Level 2 auto-assignment uses the same balancing strategy as Level 1:
1. fewest active `under_level2_review` tasks owned by the officer
2. oldest `last_assigned_at` on the category membership
3. lowest `user_id` tie-break

### Level 2 fallback
If no eligible Level 2 officer exists for the category (or the qualification has no category):
- Level 1 completion still succeeds
- `level2_review_owner_id` remains null
- the qualification appears in the Level 2 pool / Ready for Level 2 queue
- an audit entry records that auto-assignment failed and the item was routed to the pool

When auto-assignment succeeds, the assigned Level 2 officer receives portal and email notifications (same pattern as Level 1 assignment notifications, without SMS).

### Level 1 review submission
When a Level 1 officer marks review complete, the portal captures:

- **Recommendation** — `Recommend awarding` or `Do not recommend awarding` (required)
- **Findings** — internal review notes (required)
- **Accreditation statement** — required when recommending award; may appear on the CVEQ certificate `recognition_statement` field when issued
- **Evaluation report attachment** — optional internal document (`level1_evaluation_report`)
- **Supporting attachment** — optional additional file (`level1_review_attachment`)

Level 2 officers see the full submission in the qualification **Decision summary** panel after Level 1 completes.

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
