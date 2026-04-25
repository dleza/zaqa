# ZAQA Qualification Verification Platform — Implementation File Set

This file set breaks the platform into implementation-ready documents for Cursor or Codex.

## Recommended stack
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

## Why this structure
The original consolidated guide is useful for strategy, but implementation assistants perform better when the work is split into:
- clear scope areas
- complete requirements
- precise deliverables
- explicit rules
- module-specific Definition of Done

## File order
1. `01_FOUNDATION_AND_ARCHITECTURE.md`
2. `02_DATABASE_AND_DOMAIN_MODEL.md`
3. `03_AUTH_APPLICANT_PORTAL_AND_APPLICATIONS.md`
4. `04_FINANCE_PAYMENTS_AND_RECEIPTS.md`
5. `05_VERIFICATION_WORKFLOW_AND_OPERATIONS.md`
6. `06_CERTIFICATES_PUBLIC_VERIFICATION_AND_ADMINISTRATION.md`
7. `07_NOTIFICATIONS_FILES_REPORTING_SECURITY_AND_TESTING.md`
8. `08_EXECUTION_PLAN_AND_CODEX_PROMPTS.md`

## Implementation rule
Every file is intentionally complete for its scope and avoids placeholders. Where the ZAQA source document defines a rule, that rule is carried into the relevant implementation file. Cross-cutting concerns are repeated where necessary so Codex or Cursor can implement each phase without missing dependencies.

## Global non-negotiables
- No business logic in controllers
- Use Laravel Form Requests, Policies, DTOs, Events, Jobs, Services, and domain-oriented structure
- Use private file storage and signed URLs
- Queue expensive work
- Maintain a full audit trail for sensitive actions via the unified `audit_logs` system
- Enforce role-based access control
- Support local and foreign qualification flows
- Preserve full application lifecycle history
- Do not use dummy text, TODO blocks, or placeholders
- Every module must be production-ready

## Global Definition of Done
The platform is complete only when:
- applicants can register, verify accounts, create applications, upload documents, pay, track progress, receive notifications, and download outcomes
- finance can review payments, confirm proofs, generate receipts, search records, and export reports
- verification teams can receive, assign, review, send back, approve, reject, issue certificates, and maintain audit trails
- administrators can manage users, roles, settings, templates, and technical reissues
- generated certificates are secure and publicly verifiable
- all critical workflows are validated by automated tests
