# 01 — Foundation and Architecture

## Objective
Establish the production-grade technical foundation for the ZAQA Qualification Verification Platform as a modular monolith with clear domain boundaries, high maintainability, security, scalability, and excellent user experience.

## Stack
### Backend
- Laravel 12+
- PHP 8.2+
- MySQL
- Redis
- Laravel Horizon
- Laravel Queues
- Nginx

### Frontend
- Inertia.js
- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-vue
- Pinia only where shared client state is truly needed
- VueUse where it adds tangible value

## Why this frontend choice
This platform has:
- a public-facing applicant portal
- workflow-heavy back-office operations
- multiple dashboards
- multi-step forms
- queue-style review screens
- document previews
- payment screens
- status timelines
- certificate views

Inertia + Vue 3 + TypeScript gives:
- SPA-like speed without splitting the codebase into two separate applications
- easy visual customization using Tailwind and reusable UI primitives
- better long-term maintainability than a highly improvised Blade setup
- a dynamic interface suitable for review flows, tracking, filters, drawers, modals, and rich dashboards

## Architecture style
Use a **modular monolith**.

Do not start with microservices. The system is structured enough to support scale later, but the most efficient and maintainable first implementation is one Laravel application with strong internal module boundaries.

## Core domains
- Identity and Access
- Applicants
- Applications
- Qualifications
- Documents
- Consent
- Finance
- Verification
- Certificates
- Notifications
- Reporting
- Administration
- Audit

## Recommended project structure
```text
app/
  Actions/
  Data/
  Domain/
    Identity/
    Applicants/
    Applications/
    Qualifications/
    Documents/
    Consent/
    Finance/
    Verification/
    Certificates/
    Notifications/
    Reporting/
    Administration/
    AdminDashboard/
    Audit/
  Enums/
  Events/
  Http/
    Controllers/
    Middleware/
    Requests/
    Resources/
  Jobs/
  Listeners/
  Models/
  Notifications/
  Policies/
  Providers/
  Support/
bootstrap/
config/
database/
  factories/
  migrations/
  seeders/
resources/
  js/
    Components/
      UI/
      Applicant/
      Finance/
      Verification/
      Certificates/
      Admin/
    Layouts/
    Pages/
      Auth/
      Applicant/
      Finance/
      Verification/
      Admin/
      Public/
    Composables/
    Stores/
    Types/
    lib/
routes/
  web.php
  auth.php
  admin.php
  applicant.php
  finance.php
  verification.php
  public.php
```

## Deployment topology
### Core components
- Nginx reverse proxy
- Laravel application containers or servers
- MySQL database
- Redis for cache, queues, locks, rate-limits, OTP expiry, and notification orchestration
- **Laravel Horizon** — single Supervisor-managed process (`php artisan horizon`) that runs all queue workers
- Horizon dashboard (`/horizon`, Super Admin access in production)
- Object storage for uploaded files, receipts, and certificates

Production queue processing uses **Redis + Laravel Horizon + Supervisor managing Horizon**. Do not run separate `queue:work` workers for the same queues. See `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md` for deployment, operations, and monitoring.

### Optional future additions
- Meilisearch or Elasticsearch for advanced search
- MinIO or S3-compatible storage for private assets
- Separate worker pool for PDF generation and integrations

## Cross-cutting engineering rules
### Controllers
Controllers must:
- validate via Form Requests
- authorize via Policies or Gates
- delegate to Actions or Services
- return Inertia responses or API resources

Controllers must not:
- contain workflow decision trees
- write direct financial logic
- issue certificates directly
- implement role rules inline

### Services and actions
Use dedicated services such as:
- `AccountActivationService`
- `ApplicationCreationService`
- `ApplicationWorkflowService`
- `AssignmentService`
- `PaymentService`
- `ReceiptService`
- `CertificateIssuanceService`
- `NotificationOrchestrator`
- `AuditLogService`
- `AdminDashboardService` (role- and permission-aware admin home metrics; see Admin dashboard below)

### Admin dashboard (operations)
The admin home at `GET /admin/dashboard` (`dashboard.view`) is **not** a static landing page. It is a **data-driven, role-aware** operations dashboard:

- **Personalized header**: time-of-day greeting (`Good morning|afternoon|evening`) plus the user’s **first name** when present (fallback to the first token of `name`), primary **Spatie role** label, formatted **current date**, and a short **contextual subtitle** driven by permissions (e.g. verification vs finance vs audit).
- **Backend-only authorization**: `App\Domain\AdminDashboard\AdminDashboardService` aggregates KPIs, charts, and queues from real tables (`applications`, `invoices`, `payments`, `qualification_documents`, `users`, `audit_logs`, `application_lifecycle_events`, etc.). Each block is included only if the user passes the relevant `can()` checks (e.g. `admin.finance.view`, `verification.pool.view`, `admin.audit.view`). Unauthorized metrics are **omitted from the payload**, not merely hidden in Vue.
- **Charts**: [Chart.js](https://www.chartjs.org/) (Vue canvas components) for **this week** trends and breakdowns (e.g. submissions by day, revenue by day, payment methods, audit volume). Week boundaries use **Monday–Sunday** in `config('app.timezone')`.
- **Queues**: compact, low-PII lists (e.g. application **numbers** and statuses, audit **module/action** snippets) with links only where the user already has route access.
- **Quick actions**: buttons and links derived from the same permission gates as the sidebar (pool, finance queue, users, settings modules, SLA report, etc.).
- **Empty state**: if a user has `dashboard.view` but no widget permissions, the page shows a clear empty state (no fabricated numbers).

The default **Auditor** role (see `RolesAndPermissionsSeeder`) has `dashboard.view` + `admin.audit.view` for monitoring-focused dashboards.

### Events
Use domain events for major actions:
- `ApplicantRegistered`
- `AccountActivated`
- `ApplicationSubmitted`
- `PaymentConfirmed`
- `ApplicationAssigned`
- `ApplicationSentBack`
- `ApplicationResubmitted`
- `CertificateIssued`
- `CertificateReissued`

### Queued jobs
Queue:
- OTP sending
- emails
- SMS
- PDF generation
- receipt generation
- payment reconciliation
- overdue SLA reminders
- export generation
- webhook processing
- audit enrichment where expensive
- external integrations

## UX system design
### Design goals
- premium, clean, government-grade interface
- responsive layout
- excellent readability
- low cognitive load
- consistent visual language across modules
- easy theming and future redesign

### Shared UI components
Build reusable components for:
- page headers
- filter bars
- data tables
- status badges
- stat cards
- timeline items
- upload cards
- modal dialogs
- slide-over drawers
- comment panels
- confirmation prompts
- tabs
- steppers
- empty states
- alert banners
- activity feeds

## Performance rules
- use server-side pagination for large tables
- eager load relationships intentionally
- cache reference data such as countries and awarding bodies
- keep dashboard queries read-optimized
- offload expensive work to queues
- use private object storage rather than database blobs
- index all frequent filter columns
- use transactions for financial and issuance operations

## Environment configuration expectations
Configure:
- app URL
- queue connection
- Redis
- MySQL
- private storage disk
- mail driver
- SMS provider
- payment gateway credentials
- QR signing secret
- certificate verification base URL
- audit retention rules
- rate limit settings

## Required seed data
Seed:
- roles
- permissions
- countries
- awarding bodies
- billing categories (fee-driving categories)
- qualification types (ZQF levels)
- fee structures (effective-dated versions)
- applicant types
- application statuses
- payment methods
- notification templates
- certificate templates
- feedback rating bands

## Definition of Done
This phase is complete when:
- the Laravel application is initialized with the full selected stack
- Inertia, Vue 3, TypeScript, Tailwind, and shadcn-vue are installed and working
- the domain-oriented folder structure is created
- Redis, queues, Horizon, and private storage are configured
- seeders for reference data are planned and scaffolded
- cross-cutting engineering rules are established and enforced in code conventions
- no placeholders, stub decisions, or unresolved architecture gaps remain
