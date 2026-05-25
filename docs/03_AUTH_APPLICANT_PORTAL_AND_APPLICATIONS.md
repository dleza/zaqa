# 03 — Authentication, Applicant Portal, and Applications

## Objective
Implement all applicant-facing capabilities from registration to application submission, including account activation, profile capture, application creation, status tracking, document upload, consent handling, and feedback readiness.

## Applicant types
The system must support:
- Individual Applicant
- Institution or Organization Applicant

## Registration requirements
### Individual applicants
Capture:
- first name
- middle name or other names where applicable
- surname
- primary phone number
- secondary phone number optional
- email address

### Institution or organization applicants
Capture:
- institution or organization name
- primary phone number
- secondary phone number optional
- email address
- TPIN

## Activation requirements
Applicants must create accounts using a valid email address or phone number. The account must be confirmed and activated by the applicant.

Support:
- email activation token
- phone OTP
- combined verification flow when both channels are present

### Activation rules
- activation token or OTP must expire
- resend must be rate-limited
- successful verification must mark relevant verified timestamp
- inactive accounts cannot submit applications
- repeated invalid token attempts must be throttled

## Authentication UX
Provide:
- sign up
- login
- forgot password
- reset password
- activate account
- resend activation token
- verify phone OTP

## Applicant dashboard requirements
The applicant dashboard must include:
- primary action to apply
- a premium **Quick actions** section that centralizes primary actions (New application, Track application, Continue draft, Billing/Invoicing)
- tracking mechanism with statuses:
  - Draft
  - Submitted
  - In Progress
  - Processed
  - Sent Back
- recent applications list
- invoice and receipt visibility
- certificate download area when available
- notifications panel
- feedback prompt when eligible

## Applicant-facing statuses
Expose a friendly mapped status model:
- Draft
- Pending Payment
- Submitted
- In Progress
- Sent Back
- Approved
- Rejected
- Certificate Ready
- Completed

Use internal workflow statuses under the hood, but keep the applicant experience simple and clear.

## New application flow
### Step 1 — Applicant information (wizard start)
Capture:
- submitting as: **Myself** or **On behalf of someone**
- if submitting on behalf: subject full name, optional email/phone, and **NRC or Passport number required**

Rules:
- when submitting as self (individual applicants), the authenticated profile must have **either NRC or Passport** before proceeding
- regardless of self vs on-behalf, the system must persist **qualification holder name** and **NRC/Passport number** onto the qualification/application record so verification staff can see it

### Step 2 — Applicant details
Preload from profile but allow controlled updates where appropriate.

### Step 3 — Qualification details
Capture:
- country of award
- awarding institution (searchable list; supports **Other** with manual entry)
- country of award
- certificate number or student number or examination number
- title of qualification (searchable dropdown from Learner Records; supports **Other** with manual entry)
- date of award as on certificate
- qualification type (**ZQF level**, loaded from master data)

Notes:
- foreign/local is derived automatically: if **country of award is not Zambia**, the application is treated as **foreign**
- awarding institution is captured in the applicant wizard step (with **Other** supported)
- fees and estimated processing time are derived from billing category rules mapped from the selected qualification type
- **foreign fee override**: if country of award is not Zambia, billing must use the **Foreign Qualifications** fee path (billing category `FOREIGN_QUALIFICATIONS`) across all qualification types

### Step 4 — Subject results where applicable
For school certificates and equivalents, capture grades per subject exactly as on transcript or certificate.

### Step 5 — Supporting documents
Require uploads for:
- NRC copy or passport copy
- certificate copy
- transcript when required
- foreign consent form where required
- any additional supporting document needed by business rules

### Step 6 — Consent handling
#### Local qualifications
Consent form must be embedded directly in the application flow.

Implementation:
- show full consent text
- require explicit acceptance
- store consent text version and acceptance timestamp
- store applicant name tied to acceptance

#### Foreign qualifications
The applicant must:
- fill in the ZAQA consent flow if required by policy
- download and upload a signed consent form from the awarding institution where applicable

Implementation:
- explain requirement clearly
- require uploaded signed consent form before submission when mandated
- version the consent upload if resubmitted

### Step 7 — Payment (required before final submission)
Applicants must complete payment before the system submits the application for processing.

Payment rules:
- payment is a first-class wizard step after Consent
- supported methods: **Card (VISA)**, **Mobile Money**, **Bank deposit**, **Bank transfer**
- applicant may initiate payment and return later
- the application remains editable until payment is confirmed
- an invoice is generated as the immutable billing record; changing payment method does not change the invoice
- after payment is confirmed, payment method options are hidden and the payment step becomes read-only

Confirmed means:
- card callback marks payment successful
- mobile money callback/status update marks payment successful
- bank deposit/transfer proof is manually approved by finance

### Automatic submission trigger (no manual “Submit Application”)
There is no separate applicant “Submit Application” action.

Once payment is confirmed and payment satisfaction is met:
- the application is locked from applicant editing
- the application is automatically marked as submitted (`submitted_at` set)
- qualifications enter the auto-verification pipeline asynchronously

## Post-submission service feedback (required)
Immediately after payment confirmation and automatic submission, the applicant is shown a premium service feedback experience:
- rating (required when submitting feedback)
- optional written comments
- feedback is linked to the submitted application and applicant user
- feedback can be skipped
- only one feedback submission per application is allowed by default

### Save draft
Users must be able to:
- save draft
- return later
- edit draft before payment confirmation locks the application

UI notes:
- step navigation is gated; users can only continue once a step is completed and saved
- the wizard uses **Save changes** / **Save & continue** actions per step (no standalone “Save” button in the step panels)

## Foreign qualification handling
The original requirements indicate that foreign qualifications may need additional information and mandatory transcript uploads for some qualification origins.

Implementation rules:
- support dynamic document requirements based on country or qualification type
- allow administrator-managed rules for required extra documents
- always support transcript upload for foreign qualifications
- store selected country cleanly

## Local qualification handling
Implementation rules:
- embed consent flow inside the application form

## Validation rules
### Registration
- email must be unique
- primary phone must be unique enough for account use
- TPIN required for institution applicants
- names required according to applicant type

### Application
- qualification holder name required
- qualification holder NRC/passport number required
- title of qualification required
- date of award required
- country or awarding institution must be captured correctly
- NRC/passport document required
- certificate document required
- transcript required when qualification rules demand it
- foreign consent upload required when rule applies

## File upload implementation rules
- private storage only
- MIME allowlist
- file size limits per type
- hash files on upload
- maintain version history for resubmissions
- allow preview for images and PDFs
- prevent direct public links

## Applicant timeline
Each application page should show:
- current status
- a business-readable lifecycle timeline (wizard steps, payments, submission/resubmission, sent-back, decisions)
- status history timeline (raw status transitions)
- submission date
- payment state
- SLA deadline
- comments visible to applicant
- sent-back requests and resubmission history
- certificate status when applicable

### Track Application flow (required)
Applicants must have a dedicated **Track Application** page per application that presents:
- high-level progress tracker (stages)
- detailed chronological timeline of lifecycle events (applicant-visible)
- key dates and payment summary

Entry points:
- applicant dashboard (applications table)
- application detail page

## Sent-back and resubmission flow
### When a verification officer sends back an application
- applicant receives email and/or SMS
- applicant sees the returned status in portal
- applicant sees a clear visible comment
- applicant can edit the relevant sections
- applicant can upload corrected files
- applicant resubmits
- previous versions remain in history

## Notifications visible to applicant
Applicant portal must show messages for:
- account activation success
- application submitted
- payment receipt available
- service time exceeded
- application sent back
- certificate issued
- certificate ready for download
- rejection outcome where applicable

## Feedback trigger
A service experience feedback form must appear once the application has been paid and submitted according to the source requirements.

Implement:
- one feedback response per application unless explicitly reopened
- score bands:
  - Poor
  - Average
  - Commendable
  - Exceptional
- optional short message or suggestion box

## UI page map
### Auth
- Sign Up
- Login
- Activate Account
- Verify OTP
- Forgot Password
- Reset Password

### Applicant
- Dashboard
- New Application Wizard
- My Applications
- Application Detail
- Edit Draft
- Resubmission Form
- Documents Center
- Invoices and Receipts
- Notifications
- Feedback Form
- Profile Settings

## Backend services
Create:
- `ApplicantRegistrationService`
- `AccountActivationService`
- `ApplicantProfileService`
- `ApplicationDraftService`
- `ApplicationSubmissionService`
- `ApplicantDocumentService`
- `ConsentService`
- `ApplicantFeedbackService`

## Policies
Implement:
- applicant can only see own applications
- applicant can only edit drafts or sent-back applications
- applicant cannot alter issued certificate records
- applicant cannot submit without meeting required documents and consent rules

## Definition of Done
This phase is complete when:
- both applicant types can register correctly
- account activation works by token and or OTP with secure expiry and throttling
- dashboard clearly shows statuses and next actions
- applicants can create, save, edit, submit, and resubmit applications
- all qualification fields from the requirements are captured without omission
- local and foreign qualification flows behave correctly
- consent handling is fully implemented for local and foreign cases
- document upload, preview, versioning, and validation are production-ready
- feedback capture is implemented without placeholders
- applicant-visible notifications and timelines are complete
