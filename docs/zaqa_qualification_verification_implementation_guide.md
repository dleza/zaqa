# ZAQA Qualification Verification Platform — Implementation Guide

## 1. Recommended Stack

### Core backend
- **Laravel 12+**
- **PHP 8.2+**
- **MySQL**
- **Redis** for queues, cache, throttling, OTP expiry, and notifications
- **Laravel Horizon** for queue monitoring

### Recommended frontend
- **Inertia.js + Vue 3 + TypeScript**
- **Tailwind CSS**
- **shadcn-vue** for polished, reusable UI primitives
- **Pinia** for client-side state where needed

### Why this frontend is the best fit
This is the best fit for this platform because it gives:
- a **modern, dynamic SPA-like experience** without splitting the app into separate backend and frontend projects
- **very fast page transitions** with Laravel + Inertia
- a **beautiful interface** using Tailwind and shadcn-vue
- **easy visual customization** through design tokens, Tailwind config, reusable layout components, and shared UI blocks
- a **maintainable codebase** for future teams
- strong support for **dashboards, workflow screens, review queues, filters, tables, modals, and status timelines**

### Alternative if you want even simpler development
- **Laravel Livewire + Volt + Tailwind + Flux/Filament components**

Use this only if the priority is minimizing JavaScript and building mostly server-driven screens. It is simpler, but for a highly dynamic applicant portal, verification workflow, status tracking, and polished UI flexibility, **Vue 3 with Inertia** is the stronger option.

---

## 2. Business Requirements Extracted from the ZAQA Module Document

The system must support the following major areas:

### Applicant portal
- Individual and institution/organization account registration
- Email and/or phone activation using tokens/OTP
- Dashboard with application tracking states:
  - Draft
  - Submitted
  - In Progress
  - Processed
  - Sent Back
- Capture applicant and qualification details
- Upload required supporting documents
- Support local and foreign qualification flows
- Embed local consent form in the application
- Allow upload of signed foreign consent forms
- Notify the applicant after submission, delay, sent-back action, and certificate issuance
- Include verification link for downloadable certificate
- Immediately after successful final submission, show a premium service feedback experience (rating + optional comments) linked to the submitted application (skippable; one feedback per application by default)

### Finance and payments
- Finance users created by system administrators
- Generate invoice for verification/evaluation services
- Invoice amount must be derived from system-managed fee rules (fee structures) using qualification type + local/foreign status
- Accept payment against invoice
- Support payment modes:
  - Mobile Money
  - VISA
  - Bank deposit
  - Bank transfer
- Generate and store payment receipts
- Upload bank deposit slips / transfer proof
- Notify finance team
- Support receipt reference structure
- Search and filter invoices, payments, and receipts
- Audit trail
- Import/export and integrations
- Role-based access control

### Fee master data and historical correctness
Fees must be configured and resolved using master data:
- qualification types (ZAQA ZQF levels) map into billing categories
- billing categories define processing time rules
- fee structures are effective-dated versions with **local vs foreign** fees

Invoice rule:
- when invoicing/billing, resolve the fee structure effective at that time and snapshot the reference + billed amount so later fee changes do not affect old invoices.

### Verification workflow
- Verification team accounts created by administrators
- Receive all applications into an applications pool
- Categorize foreign applications by country of award
- Categorize local applications by awarding institution
- Support “Other” option where country or awarding institution is not listed
- At least 2 verification levels:
  - Level 1
  - Level 2
- Level 2 assigns work to Level 1
- Level 1 processes only assigned applications
- Level 1 and Level 2 can send back applications with comments
- Applicant can resubmit amended applications
- Level 2 reviews and can amend/send back to Level 1
- Level 2 issues certificate of recognition or notice of rejection
- Level 2 can change status of issued certificate with comment
- Notify applicant when certificate is issued

### Certificates
- Generate certificates from prescribed templates
- Include security features:
  - QR code
  - Court of Arms background watermark
  - ZAQA logo
  - Director General signature

### Systems administration
- Create all back-office processing accounts
- Re-issue failed certificates on technical grounds
- Database access for systems admins
- Change user roles/rights
- Enable/disable accounts and permissions

---

## 3. Product Recommendation Summary

### Final recommendation
Use:
- **Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind + shadcn-vue**

### Why this is the right choice here
This platform has:
- several user types
- many workflow states
- document uploads
- notifications
- finance operations
- multi-step application forms
- review queues
- certificate generation
- admin dashboards

That means the UI must feel modern and fast, while still staying tightly integrated with Laravel. This stack gives the best balance of:
- developer speed
- maintainability
- performance
- excellent UI quality
- future extensibility

---

## 4. High-Level Architecture

## Modules
1. **Authentication & Identity**
2. **Applicant Portal**
3. **Application Management**
4. **Qualification Data Capture**
5. **Document Management**
6. **Consent Management**
7. **Invoice & Payment Module**
8. **Verification Workflow Engine**
9. **Certificate Generation & Verification**
10. **Notifications & Messaging**
11. **Feedback Module**
12. **RBAC & Administration**
13. **Audit & Activity Logs**
14. **Reporting & Export**
15. **Integration Layer**

## Deployment shape
- **Nginx**
- **Laravel app servers**
- **MySQL**
- **Redis**
- **Object storage** for uploads and generated certificates
- **Queue workers** for PDF generation, notifications, document processing, reminders, and integrations
- Optional:
  - **Meilisearch/Elasticsearch** for advanced search
  - **MinIO/S3** for file storage

---

## 5. Core User Roles

### Public/applicant side
- Individual Applicant
- Institution Applicant

### Internal users
- System Administrator
- Finance Officer
- Verification Officer Level 1
- Verification Officer Level 2
- Super Admin / IT Admin
- Read-only Auditor (recommended addition)

### Permission model
Use **spatie/laravel-permission** with:
- roles
- permissions
- permission groups
- policy-based authorization for records

---

## 6. Key Workflows

## 6.1 Applicant registration and activation
1. User chooses applicant type: Individual or Institution
2. User enters required profile details
3. System sends OTP or activation link to email and/or phone
4. User verifies account
5. Account becomes active
6. User lands on applicant dashboard

## 6.2 Application creation
1. Applicant clicks **Apply**
2. Selects application type and qualification type
3. Completes personal/institution details
4. Captures qualification details
5. Uploads required documents
6. Completes local or foreign consent flow
7. Saves as draft or submits
8. System creates invoice
9. System waits for payment confirmation
10. On successful payment, application moves to **Submitted**

## 6.3 Verification process
1. Submitted application enters **Applications Pool**
2. System categorizes it by:
   - local awarding institution, or
   - foreign country of award
3. Level 2 reviews and assigns to Level 1
4. Level 1 reviews and updates notes/status
5. If amendments needed, Level 1 sends back to applicant with comments
6. Applicant updates and resubmits
7. Level 2 performs final review
8. Level 2 issues:
   - Certificate of Recognition, or
   - Notice of Rejection
9. System notifies applicant and provides secure certificate link

## 6.4 Finance workflow
1. Invoice is generated on submission or pre-submission checkout point
2. User selects payment mode
3. Payment is confirmed through gateway or manually by finance
4. Receipt is generated
5. Receipt stored on applicant portal
6. Finance can search/filter/export invoices, payments, receipts

## 6.5 Certificate workflow
1. Level 2 approves application
2. System generates certificate PDF from template
3. Adds QR code, watermark, logo, and signature asset
4. Stores immutable certificate record
5. Creates public verification token/link
6. Applicant receives notification
7. Admin may reissue only on approved technical grounds

---

## 7. Application Status Model

Recommended normalized status flow:

### Applicant-facing statuses
- Draft
- Pending Payment
- Submitted
- In Progress
- Sent Back
- Approved
- Rejected
- Certificate Ready
- Completed

### Internal workflow statuses
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

Use a **workflow transitions table** instead of only a single status column. Keep current status on the application, but store full movement history.

---

## 8. Suggested Database Design

## Core tables
- users
- applicant_profiles
- institution_profiles
- roles
- permissions
- applications
- application_status_histories
- qualifications
- awarding_bodies
- countries
- qualification_documents
- consent_forms
- invoices
- payments
- receipts
- payment_proofs
- verification_assignments
- verification_reviews
- review_comments
- certificates
- certificate_templates
- certificate_status_histories
- notifications
- sms_logs
- email_logs
- audit_logs
- service_feedback
- system_settings
- reference_data_imports
- integrations
- failed_jobs
- jobs

## Important table ideas

### applications
Fields:
- id
- application_number
- applicant_user_id
- applicant_type
- qualification_id
- current_status
- service_type
- is_foreign
- country_id
- awarding_body_id
- assigned_level1_user_id
- assigned_by_level2_user_id
- submitted_at
- paid_at
- completed_at
- service_deadline_at
- metadata JSON
- created_at
- updated_at

### qualifications
Fields:
- id
- application_id
- awarding_institution_name
- qualification_holder_name
- country_id
- nrc_passport_number
- certificate_number
- student_exam_number
- title_of_qualification
- award_date
- transcript_required
- qualification_type
- raw_subject_results JSON
- notes

### verification_reviews
Fields:
- id
- application_id
- review_level
- reviewer_user_id
- assigned_to_user_id
- outcome
- comment
- decision_at
- editable_snapshot JSON

### certificates
Fields:
- id
- application_id
- certificate_number
- verification_code
- verification_url_token
- template_id
- pdf_path
- qr_payload
- watermark_version
- signature_version
- issued_by_user_id
- issued_at
- status
- reissued_from_certificate_id nullable
- revocation_comment nullable

### invoices
- id
- application_id
- invoice_number
- amount
- currency
- status
- due_date
- generated_at

### payments
- id
- invoice_id
- payment_method
- reference_number
- amount
- status
- paid_at
- gateway_response JSON
- proof_file_path nullable
- confirmed_by_user_id nullable

### audit_logs
- id
- actor_user_id nullable
- actor_name_snapshot nullable
- event_type
- module
- entity_type nullable
- entity_id nullable
- action_name
- message
- before_state JSON nullable
- after_state JSON nullable
- metadata JSON nullable
- ip_address nullable
- user_agent nullable
- correlation_id nullable
- created_at

Use **MySQL JSON** for flexible sections like application metadata, subject results, external gateway responses, and event payloads.

---

## 9. Document Management Requirements

The platform should support:
- certificate copy upload
- NRC/passport upload
- transcript upload where required
- foreign consent form upload
- payment proof upload
- generated receipt storage
- generated certificate storage

### File handling rules
- virus scan uploads
- MIME type allowlist
- size limits by document type
- hash files to detect duplicates
- private storage by default
- signed temporary URLs for download
- image/PDF preview support
- versioning for resubmitted files

Recommended:
- store files in **private object storage**
- store only metadata/path in database
- generate secure access URLs on demand

---

## 10. Notification Requirements

Support both:
- Email
- SMS

### Trigger events
- account activation
- application submitted
- receipt available
- service time exceeded
- application sent back
- application resubmitted
- application assigned
- certificate issued
- certificate reissued
- payment proof received
- finance confirmation

### Design recommendation
Use:
- Laravel Notifications
- queued delivery
- message templates in database or config
- notification preferences per user
- delivery logs and retry support

---

## 11. Payment Design

The requirements mention support for:
- Mobile Money
- VISA
- Bank deposit
- Bank transfer

### Recommended implementation model
Use a **payment provider abstraction layer**:

```php
interface PaymentGatewayInterface {
    public function initiatePayment(array $payload): PaymentInitResult;
    public function verifyPayment(string $reference): PaymentVerificationResult;
    public function webhook(array $payload): void;
}
```

### Payment modes
- **Online gateways**
  - Mobile Money
  - VISA
- **Manual confirmation modes**
  - Bank deposit
  - Bank transfer

### Important rules
- Receipt generated only after confirmed payment
- Manual proofs must be visible to finance queue
- Payment confirmation must be auditable
- Use idempotent webhook handlers
- Separate invoice status from payment status

---

## 12. Verification Workflow Engine

Do not hardcode the workflow only in controllers.

Use:
- application state machine
- transition service
- assignment service
- policy checks
- event-driven notifications

### Recommended services
- `ApplicationWorkflowService`
- `AssignmentService`
- `CertificateIssuanceService`
- `PaymentService`
- `NotificationOrchestrator`
- `AuditLogService`

### Rules to enforce
- only Level 2 can assign to Level 1
- Level 1 can only process assigned applications
- send-back action requires comment
- resubmission creates new status history record
- final issuance allowed only from approved state
- reissue allowed only to admin with reason

---

## 13. Certificate Design

### Certificate generation
Use HTML-to-PDF rendering with:
- **Laravel Snappy/wkhtmltopdf**, or
- **Browsershot/Chromium**

Browsershot is often better for modern layouts.

### Security features required
- QR code that resolves to verification page
- watermark background
- ZAQA logo
- Director General signature asset

### Extra recommended protections
- unique verification token
- certificate hash
- immutable issuance snapshot
- revocation/reissue handling
- public verification page with limited exposed data
- optional signed verification URL

### Public verification page should show
- certificate number
- holder initials or masked name if required
- qualification title
- issue date
- certificate status
- validation result

---

## 14. Admin and Back-Office Features

### Operations dashboard (admin home)
- The default admin landing (`/admin/dashboard`) is a **role-aware, stats-based** dashboard (not a static welcome page).
- It shows a **personalized greeting** (time of day + first name), primary role, current date, KPI cards, **Chart.js** summaries for the **current week** (aligned to `config('app.timezone')`), actionable **queues** (application numbers / audit snippets; no extra PII), and **quick actions** gated by the same permissions as navigation.
- **Super Admin** sees broad system metrics; **Finance Officer** sees finance KPIs and revenue charts; **Verification Level 1 / 2** see assignment and pool-oriented metrics; **Auditor** (optional seeded role) sees audit-focused widgets. Data is **filtered on the server** so users never receive props for sections they cannot authorize.

### System admin
- create users
- assign roles
- enable/disable users
- reset accounts
- manage templates
- manage reference data
- manage settings
- reissue failed certificates

### Finance dashboard
- invoice list
- payment queue
- proof verification queue
- receipt generation
- export reports

### Verification dashboards
- applications pool
- by country/awarding institution categories
- Level 1 assigned queue
- Level 2 final review queue
- sent back queue
- overdue queue

### Audit dashboard
- who changed what
- when it changed
- previous vs new value
- exportable logs

---

## 15. Search, Filters, and Reporting

The requirements explicitly mention search and filtering.

### Recommended filter dimensions
- application number
- applicant name
- applicant type
- country of award
- awarding institution
- qualification type
- status
- assigned reviewer
- payment status
- invoice number
- receipt number
- certificate number
- date range
- overdue flag

### Reports to include
- applications by status
- processing times
- local vs foreign volumes
- payments by method
- receipts issued
- certificates issued
- rejections
- sent-back rate
- reviewer throughput
- SLA breach count
- feedback scores

---

## 16. UI/UX Design Guidance

### Design system recommendation
Create a consistent design system with:
- neutral background palette
- strong primary brand color
- card-based dashboards
- generous spacing
- clean typography
- reusable tables, badges, timelines, drawers, and modals

### Key UI patterns
- multi-step application wizard
- status timeline component
- document upload cards with preview
- finance tables with filters and export
- Kanban-like or queue-based verification views
- certificate preview modal
- audit trail drawer
- activity feed on every application

### Suggested layout zones
#### Applicant portal
- top progress/status card
- action bar
- draft/submitted tabs
- recent notifications
- invoice and receipt panel
- certificate downloads

#### Back-office portal
- KPI cards
- advanced filters
- data table
- review side panel
- activity log timeline
- internal comment stream

---

## 17. Performance and Scalability

### Use these patterns from the start
- queue all emails/SMS/PDF generation
- cache reference data
- index all searchable columns
- eager load relationships carefully
- use server-side pagination for tables
- store heavy files outside database
- use read-optimized query services for dashboards
- use database transactions for critical financial and issuance flows

### Recommended indexes
- application_number
- current_status
- applicant_user_id
- country_id
- awarding_body_id
- assigned_level1_user_id
- submitted_at
- service_deadline_at
- invoice_number
- receipt_number
- certificate_number
- verification_url_token

### Scale path
Phase 1:
- monolith Laravel app

Phase 2:
- separate queue workers and storage

Phase 3:
- extract notifications/integrations/reporting into services if needed

Keep the **modular monolith** approach first. It is the most efficient here.

---

## 18. Security Requirements

### Must-have controls
- OTP/account verification
- strong password policy
- MFA for internal staff recommended
- RBAC and policies
- audit logs for every sensitive change
- signed file URLs
- encryption at rest where possible
- encryption for sensitive fields if needed
- CSRF/XSS/SQL injection protections through Laravel defaults and secure coding
- rate limits on login, OTP, uploads, and verification lookups
- anti-malware file scanning
- immutable financial and certificate audit trails

### Especially sensitive actions
- payment confirmation
- certificate issuance
- certificate reissue
- status reversal
- role changes
- user disabling/enabling

Require extra audit entries and optional approval flow for these.

---

## 19. Suggested Laravel Project Structure

```text
app/
  Actions/
  Data/
  Domain/
    Applications/
    Applicants/
    Finance/
    Verification/
    Certificates/
    Notifications/
    Administration/
  Enums/
  Events/
  Http/
    Controllers/
    Requests/
    Resources/
  Jobs/
  Listeners/
  Models/
  Notifications/
  Policies/
  Services/
  Support/
resources/
  js/
    Components/
    Layouts/
    Pages/
    Stores/
    Composables/
    Types/
  views/
routes/
  web.php
  auth.php
```

### Frontend structure
```text
resources/js/
  Components/UI/
  Components/Application/
  Components/Finance/
  Components/Verification/
  Components/Certificates/
  Layouts/
  Pages/Auth/
  Pages/Applicant/
  Pages/Finance/
  Pages/Verification/
  Pages/Admin/
  Stores/
  Composables/
  lib/
```

---

## 20. Recommended Packages

### Laravel/backend
- `inertiajs/inertia-laravel`
- `spatie/laravel-permission`
- `spatie/laravel-activitylog`
- `spatie/laravel-medialibrary` or custom private file handling
- `laravel/horizon`
- `laravel/scout` if advanced search needed
- `maatwebsite/excel` for import/export
- `simple-qrcode` for QR generation
- `spatie/laravel-data` for DTO patterns
- `pestphp/pest` for testing

### Frontend
- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-vue
- Pinia
- VueUse
- TanStack Table for advanced data tables if desired
- Zod or shared schema validation helpers

---

## 21. API and UI Boundary Recommendation

Even with Inertia, define clean backend boundaries:
- Form Requests for validation
- DTOs for service inputs
- Actions/services for use cases
- Policies for authorization
- Events/listeners for side effects

Avoid putting business rules directly in controllers or Vue components.

---

## 22. Testing Strategy

### Must-have tests
- authentication and activation flows
- application creation and validation
- document upload rules
- invoice generation
- payment confirmation
- role-based permissions
- assignment rules
- send-back and resubmission flows
- approval/rejection flows
- certificate generation and verification link
- admin reissue flow
- audit logging
- notification dispatch

### Test layers
- unit tests for services
- feature tests for workflows
- browser tests for critical happy paths if possible
- contract tests for payment integrations

---

## 23. Delivery Plan

### Phase 1 — Foundation
- Laravel setup
- auth
- roles/permissions
- reference data
- applicant profiles
- admin user management

### Phase 2 — Applicant portal
- registration and activation
- dashboard
- application wizard
- uploads
- consent forms
- status tracking

### Phase 3 — Finance
- invoices
- payment methods
- proof upload
- receipt generation
- finance queue

### Phase 4 — Verification workflow
- applications pool
- categorization
- L2 assignment
- L1 review
- send-back/resubmission
- final review

### Phase 5 — Certificates
- templates
- issuance
- QR verification
- public verification page
- reissue flow

### Phase 6 — Reporting and hardening
- reports
- exports
- audit enhancements
- performance tuning
- security hardening
- automated tests

---

## 24. Prompt File for Cursor or Codex

Copy the following prompt into Cursor or Codex when starting implementation:

```md
You are a senior Laravel solutions architect and full-stack engineer.

Build a production-grade Qualification Verification Platform for the Zambia Qualifications Authority (ZAQA) as a modular monolith using Laravel.

## Required stack
- Laravel 12+
- PHP 8.2+
- MySQL
- Redis
- Inertia.js
- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-vue
- Laravel queues and Horizon

## Core goals
Build a robust, scalable, maintainable, secure, and efficient system with excellent UX for both public applicants and internal back-office teams.

## Main modules
1. Authentication and account activation
2. Applicant portal
3. Application and qualification capture
4. Document upload and management
5. Consent management
6. Invoice and payment processing
7. Verification workflow with Level 1 and Level 2 roles
8. Certificate generation and public verification
9. Notifications by email and SMS
10. Feedback collection
11. Role-based administration
12. Audit logs and reporting

## Functional requirements
### Applicant requirements
- Individual applicants register with full names, phone numbers, and email
- Institution applicants register with institution name, phone numbers, email, and TPIN
- Account must be activated using email and/or phone token/OTP
- Dashboard must support application statuses: Draft, Submitted, In Progress, Processed, Sent Back
- Applicant must create applications and capture qualification details
- Required captured fields include awarding institution, qualification holder names, country of award, NRC/passport, certificate number/student number/exam number, title of qualification, date of award
- Applicant must upload required documents including certificate copy and NRC/passport copy
- For school certificates/equivalents, applicant must capture grades as shown on transcript/certificate
- For foreign qualifications, system must support additional transcript uploads and extra required information
- Local qualifications must have embedded consent form
- Foreign qualifications must support signed consent form upload
- After successful submission, applicant must receive notification and see payment receipt on portal
- Applicant must be notified if processing exceeds SLA: 14 days for local and 60 days for foreign
- If sent back, applicant must receive message and later resubmit with amendments
- When certificate is issued, applicant must receive a notification with verification/download link
- Show service experience feedback form after payment and submission

### Finance requirements
- Admin must create finance team accounts
- System must generate invoices
- Clients must pay against invoices
- Payment methods include Mobile Money, VISA, Bank Deposit, Bank Transfer
- Generate receipts after successful payment
- Store payment receipt on applicant portal
- Support upload of deposit slips and transfer proof
- Finance users must review and update payments
- Receipt number should include payment source and application ID reference logic
- Finance module must support search, filters, import/export, and audit trail
- System must support integration with external systems

### Verification requirements
- Admin creates verification users
- All submitted applications enter a common applications pool
- Foreign applications categorized by country of award
- Local applications categorized by awarding institution
- If country or awarding institution not found, allow Other
- All verification team members have view access to pool
- There must be at least two levels: Level 1 and Level 2
- Level 2 assigns applications to Level 1
- Level 1 can only process applications assigned to them
- Level 1 and Level 2 can send back applications with comments
- Applicant can amend and resubmit
- Level 2 can review, amend, and send back to Level 1 with comments
- Level 2 can issue certificate of recognition or notice of rejection
- Level 2 can change status of issued certificate with comment
- Once certificate is issued, system sends notification and process ends

### Certificate requirements
- Generate certificates from prescribed templates
- Include QR code
- Include Court of Arms watermark background
- Include ZAQA logo
- Include Director General signature
- Support secure public verification page

### Administration requirements
- Admin creates all back-office accounts
- Admin can reissue certificates that failed technically
- Admin can manage roles and rights
- Admin can enable/disable users
- Admin has database and system management access

## Non-functional requirements
- Modular monolith architecture
- Clean domain-oriented service structure
- Strict validation and authorization
- Full audit trail for sensitive actions
- Queue all expensive tasks
- Secure file handling with private storage and signed URLs
- Scalable search and reporting
- Use DTOs, Form Requests, Policies, Events, Jobs, and Services
- Avoid business logic in controllers
- Use enums and workflow transition rules
- Write tests for major workflows

## Deliverables
1. Full architecture and folder structure
2. Database schema and migrations
3. Seeders for roles, permissions, countries, awarding bodies, statuses, and templates
4. Backend services and workflow engine
5. Inertia + Vue pages for applicant, finance, verification, and admin portals
6. Beautiful reusable UI component system
7. Notification templates
8. Certificate generation pipeline
9. Public certificate verification page
10. Automated tests

## UI expectations
- Modern, premium, government-grade design
- Very clean dashboard layouts
- Responsive design
- Fast loading and excellent perceived performance
- Easy theming and future modification
- Reusable data tables, status badges, timelines, forms, modals, drawers, and upload cards

## Technical implementation rules
- Use TypeScript in frontend
- Use Tailwind and reusable design tokens
- Use composables and stores sparingly and correctly
- Use MySQL JSON where appropriate
- Use policies for per-record authorization
- Use queues for notifications, PDF generation, and integrations
- Use unified audit logs (`audit_logs`) for important changes
- Use private file storage
- Use signed URLs for certificate verification and downloads where needed
- Build for maintainability first

Start by generating:
1. system architecture
2. database schema
3. roles and permissions matrix
4. workflow/state machine
5. module-by-module implementation plan
6. Laravel folder structure
7. frontend page map
8. first migration batch
9. first set of models, enums, and services
```

---

## 25. Final Technical Recommendation

### Best stack for your exact request
**Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS + shadcn-vue**

This will give you:
- a very attractive UI
- fast and dynamic interactions
- easy visual changes later
- strong long-term maintainability
- strong fit for this workflow-heavy system

---

## 26. Nice-to-have Enhancements

Recommended additions beyond the PDF requirements:
- internal notes separate from applicant-visible comments
- SLA dashboard and reminders
- bulk assignment tools for Level 2
- certificate revocation and verification history
- impersonation for admins with audit logging
- dashboard analytics and heatmaps
- webhook-based payment integrations
- OCR-ready document pipeline later if needed
- multilingual support if needed later
- accessibility compliance support

---

## 27. Definition of Done

The system should be considered complete when:
- applicants can register, verify, apply, upload, pay, track, and download outcomes
- finance can invoice, confirm payment, issue receipts, and report
- verification teams can review, assign, send back, approve, reject, and issue certificates
- admins can manage accounts, roles, templates, and reissues
- certificates are secure and publicly verifiable
- all major actions are audited
- queues, notifications, and file security are in place
- the system is tested and production-ready
