# 08 — Execution Plan and Codex Prompts

## Objective
Provide a concrete phase-by-phase execution order and complete prompts that Cursor or Codex can follow without losing scope.

## Recommended implementation order
### Phase 1 — Project foundation
Implement:
- Laravel setup
- Inertia + Vue 3 + TypeScript + Tailwind + shadcn-vue
- Redis and Horizon
- roles and permissions package
- core layouts
- reference data tables
- fee master data tables (billing categories, qualification types, fee structures)
- user model extensions
- audit base
- shared UI component library

### Phase 2 — Authentication and applicants
Implement:
- applicant registration for individual and institution users
- email token activation
- phone OTP verification
- login and password reset
- applicant profiles
- dashboard shell

### Phase 3 — Applications and documents
Implement:
- application wizard
- draft save
- qualification capture
- local vs foreign branching
- subject result capture
- file upload engine
- consent flows
- payment step (before final submit)
- status timeline

### Phase 4 — Finance
Implement:
- invoice generation
- fee resolution + invoice fee snapshotting (historical correctness)
- payment records
- online payment adapters
- manual proof upload
- finance review queue
- receipt generation
- applicant portal finance panel

Finance correctness rules:
- invoice is the immutable billing record for an application
- payment methods settle the invoice; switching methods must not mutate the invoice
- once payment is confirmed, applicant payment UI becomes read-only (no method switching)

### Phase 5 — Verification
Implement:
- applications pool
- category views
- Level 2 assignment
- Level 1 review
- send-back to applicant
- resubmission handling
- Level 2 review and final decisions
- SLA monitoring

### Phase 6 — Certificates and admin
Implement:
- certificate template engine
- QR code generation
- public verification page
- rejection notice
- technical reissue flow
- user and role administration
- settings and template management
- **role-aware admin dashboard** at `/admin/dashboard`: personalized greeting, permission-filtered KPIs, Chart.js trends for today/this week (application timezone), work queues, and quick actions (`AdminDashboardService` + `Admin/Dashboard` Inertia page); see `docs/01_FOUNDATION_AND_ARCHITECTURE.md` (Admin dashboard section)

### Phase 7 — Reporting, security hardening, and testing
Implement:
- operational reports
- exports
- notification hardening
- malware scanning
- security review
- end-to-end tests
- performance tuning
- service feedback capture after submission (applicant UX + analytics)

## Engineering rules for Codex or Cursor
- do not invent fields outside the documented business rules unless they are justified for system integrity
- do not leave placeholder routes, views, policies, services, migrations, or tests
- when a feature is started, finish all supporting pieces for that feature
- always create policies and validation together with new models and screens
- always add audit logging for sensitive actions
- always use enums for status-like fields
- prefer domain services over controller logic
- keep the applicant experience simple even when the internal workflow is complex

## Prompt 1 — Generate the project skeleton
```md
You are a senior Laravel solutions architect.

Create the full project skeleton for the ZAQA Qualification Verification Platform using:
- Laravel 12+
- PHP 8.2+
- MySQL
- Redis
- Horizon
- Inertia.js
- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-vue

Set up a modular monolith with domains for Identity, Applicants, Applications, Qualifications, Documents, Consent, Finance, Verification, Certificates, Notifications, Reporting, Administration, and Audit.

Generate:
- folder structure
- route files
- base layouts
- shared UI components
- enums
- service interfaces
- policy map
- package installation list
- config plan

Do not use placeholders or TODO comments. Produce implementable code and file structure only.
```

## Prompt 2 — Generate database and migrations
```md
Using the ZAQA implementation documents, generate the first full migration batch and Eloquent models for:
- users
- applicant_profiles
- institution_profiles
- countries
- awarding_bodies
- applications
- application_status_histories
- qualifications
- qualification_subject_results
- qualification_documents
- consent_forms
- billing_categories
- qualification_types
- fee_structures
- invoices
- payments
- receipts
- payment_proofs
- verification_assignments
- verification_reviews
- review_comments
- certificate_templates
- certificates
- certificate_status_histories
- service_feedback
- audit_logs
- system_settings

Use MySQL types and JSON where appropriate.
Create all foreign keys, indexes, uniqueness constraints, and backed enums.
Do not omit any field needed by the requirements.
```

## Prompt 3 — Generate auth and applicant module
```md
Implement the complete applicant auth and application module.

Requirements:
- support individual and institution applicant registration
- support activation by email token and phone OTP
- create applicant dashboard with statuses
- create application wizard
- support draft save and resubmission
- capture qualification details exactly as required
- support local embedded consent and foreign signed consent uploads
- support document uploads with versioning and private storage
- ensure a dedicated Payment step exists before Review & Submit
- block final submission until payment is confirmed
- immediately after successful final submission, show a premium service feedback experience (rating + optional comments) linked to the application (skippable; one feedback per application)
- implement full application lifecycle tracking with a business-readable timeline and a Track Application flow for applicants and internal users
- show application timelines and applicant-visible comments

Generate:
- routes
- controllers
- form requests
- policies
- services
- events
- jobs
- Inertia pages
- Vue components
- tests

Do not use placeholders.
```

## Prompt 4 — Generate finance module
```md
Implement the finance module for the ZAQA platform.

Requirements:
- invoice generation
- payment initiation
- Mobile Money and VISA adapter architecture
- bank deposit and bank transfer proof upload
- finance proof review queue
- receipt generation
- finance search, filters, and exports
- applicant portal invoice and receipt visibility
- complete audit logging

Generate all backend and frontend code required.
Do not leave placeholder gateways or stub services. Use a clean adapter pattern with concrete interfaces and complete manual flow support.
```

## Prompt 5 — Generate verification module
```md
Implement the full verification workflow.

Requirements:
- applications pool
- category views by country of award and awarding institution
- Level 2 assigns to Level 1
- Level 1 can process only assigned applications
- Level 1 and Level 2 can send back to applicant with comment
- Level 2 can amend and send work back to Level 1
- applicant resubmission must be supported
- Level 2 can approve, reject, and issue
- SLA handling for 14-day local and 60-day foreign applications
- complete audit trail and notifications

Generate routes, policies, services, state transitions, pages, and tests with no placeholders.
```

## Prompt 6 — Generate certificates and admin module
```md
Implement the certificate and administration modules.

Requirements:
- certificate generation from templates
- QR code
- Court of Arms watermark
- ZAQA logo
- Director General signature
- public verification page
- certificate status history with comments
- technical reissue by administrators
- user management
- role and permission management
- enable and disable users
- template and settings management

Generate production-ready code and screens. Do not use placeholder templates, placeholder routes, or incomplete actions.
```

## Prompt 7 — Generate security, notifications, and tests
```md
Implement the final hardening layer.

Requirements:
- queued email and SMS notifications
- malware-aware upload validation hooks
- signed URLs
- audit logs (unified audit + activity feed)
- reports and exports
- unit tests, feature tests, and critical browser tests
- operational monitoring hooks
- rate limits for login, OTP, uploads, and public verification

Finish all remaining gaps and ensure production readiness.
Do not leave placeholders.
```

## Release checklist
Before considering the system ready:
- all migrations run cleanly
- seeders populate reference data
- all policies are registered
- all routes are protected correctly
- queues process notifications and PDFs
- applicant, finance, verification, admin, and public flows are tested
- certificate verification works from QR code
- exports run in background for large datasets
- audit logs are generated for every sensitive action
- no placeholder assets, texts, or actions remain

## Definition of Done
This phase is complete when:
- the implementation order is unambiguous
- Cursor or Codex can work file-by-file without losing context
- every major module has a dedicated complete prompt
- the release checklist covers the full business scope
- the prompts contain no placeholders and do not omit any stated ZAQA requirement
