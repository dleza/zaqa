# ZAQA Qualification Verification Platform — Codex Instruction File

## Project Overview
You are building a full production-grade Qualification Verification Platform for the Zambia Qualifications Authority (ZAQA).

This system includes:
- Applicant portal (individual and institution)
- Application processing
- Finance and payment handling
- Verification workflow (Level 1 and Level 2)
- Certificate generation and verification
- Admin and system management
- Notifications, reporting, audit logs, and security

## Instruction to Codex
You MUST treat the markdown files in this repository as the **source of truth**.

You MUST:
- Read ALL `.md` files in this project before writing code
- Follow them strictly without skipping any requirement
- Ensure NO requirement from the documents is omitted
- NOT introduce placeholders, TODOs, or incomplete implementations
- Build a production-ready system from the start

## Files to read (in order)
1. 01_FOUNDATION_AND_ARCHITECTURE.md
2. 02_DATABASE_AND_DOMAIN_MODEL.md
3. 03_AUTH_APPLICANT_PORTAL_AND_APPLICATIONS.md
4. 04_FINANCE_PAYMENTS_AND_RECEIPTS.md
5. 05_VERIFICATION_WORKFLOW_AND_OPERATIONS.md
6. 06_CERTIFICATES_PUBLIC_VERIFICATION_AND_ADMINISTRATION.md
7. 07_NOTIFICATIONS_FILES_REPORTING_SECURITY_AND_TESTING.md
8. 08_EXECUTION_PLAN_AND_CODEX_PROMPTS.md

## Technology Stack (MANDATORY)
- Laravel 12+
- PHP 8.2+
- MySQL
- Redis
- Laravel Horizon
- Inertia.js
- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-vue

## Core Engineering Rules
- No business logic in controllers
- Use:
  - Form Requests
  - Policies
  - DTOs
  - Services
  - Events
  - Jobs
- Use enums for all statuses
- Use MySQL `JSON` columns where specified
- Use private file storage with signed URLs
- Queue all heavy operations
- Maintain a single, unified audit log (`audit_logs`) for all important events
- Enforce role-based access control

## Workflow Rules (STRICT)
- Level 2 assigns applications
- Level 1 only processes assigned work
- Send-back requires comment
- Applicant must be able to resubmit
- Certificate can only be issued after approval
- Admin can reissue only for technical reasons
- SLA rules:
  - 14 days (local)
  - 60 days (foreign)

## Payment Rules
- Support:
  - Mobile Money
  - VISA
  - Bank Deposit
  - Bank Transfer
- Receipt only after confirmed payment
- Manual proofs must be reviewed by finance
- All payment actions must be auditable
- Invoice is the immutable billing record; payment methods settle the invoice and must not mutate it
- After confirmed payment, applicant payment UI becomes read-only and method selection must be blocked

## Qualification types and fees (critical)
Qualification types and fee rules must be first-class configurable entities.

Requirements:
- qualification types are master data (ZAQA ZQF levels)
- each qualification type maps to a billing category
- fee structures are effective-dated versions per billing category with **local vs foreign** fees
- invoice generation must resolve the active fee at billing time and **snapshot**:
  - `fee_structure_id`, `billing_category_id`, `qualification_type_id`
  - billed amount/currency
  - local/foreign context
  - processing time snapshot where useful
- later fee changes must not mutate already-issued invoices

## Certificate Rules
- Include:
  - QR code
  - Watermark
  - ZAQA logo
  - Director General signature
- Provide public verification page
- Use secure token-based verification

## UI Rules
- Modern, clean, premium interface
- Fast and dynamic (SPA-like with Inertia)
- Fully responsive
- Reusable UI components
- Clear dashboards and workflow views

## Applicant wizard (critical)
The applicant application wizard must include a dedicated **Payment** step.

Required order:
- Applicant → Qualification → Documents → Consent → **Payment** → Review & Submit

Rule:
- applicants must not be able to finally submit until payment is **confirmed** (gateway callback or finance approval).

## Development Strategy
You MUST implement in phases:

1. Foundation
2. Database
3. Authentication & Applicant Portal
4. Finance
5. Verification
6. Certificates & Admin
7. Notifications, Security, Testing

Do NOT skip phases.

## Execution Mode
- Always analyze before coding
- Break tasks into steps
- Generate complete working code
- Include migrations, models, policies, services, and UI together
- Ensure consistency across modules

## Definition of Done (GLOBAL)
The system is complete only when:
- Applicants can register, apply, upload, pay, track, and download results
- Finance can manage invoices, payments, receipts, and reports
- Verification team can assign, review, send back, approve, reject, and issue
- Admin can manage users, roles, templates, and reissues
- Certificates are secure and publicly verifiable
- All workflows are tested and audited

## Final Instruction
Do NOT:
- skip any module
- simplify required workflows
- leave partial implementations

Always build complete, production-ready features.
