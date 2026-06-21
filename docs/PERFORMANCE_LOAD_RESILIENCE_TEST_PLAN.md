# ZAQA Portal — Performance, Load & Resilience Testing Plan

**Document version:** 1.0  
**Date:** 2026-06-16  
**Codebase:** `/var/www/html/zaqa-portal`  
**Status:** Planning / analysis only — no application changes included

---

## 1. Executive summary

The ZAQA Qualification Verification Portal is a Laravel 12 + Inertia/Vue monolith handling applicant applications, document uploads, payments (CyberSource card + cGrate mobile money), multi-level verification (Level 1 / Level 2), finance reporting, and public certificate verification.

Production is expected to run **Redis-backed queues** with high concurrency targets:

| Scenario | Target |
|----------|--------|
| Concurrent portal users | 15,000 |
| Card payment attempts | 3,000 |
| Mobile money payment attempts | 4,000 |
| Concurrent / high-volume uploads | 3,000 |
| Historical data | 100k+ applications, 300k+ qualifications, 1M+ document metadata rows |

**Critical success criterion:** Level 1 and Level 2 verification list and detail pages must remain responsive (p95 ≤ 2–3 s) even at large data volumes — not merely under empty-database load tests.

This plan defines how to test load, resilience, and performance in **staging only**, using scriptable HTTP tools (k6 preferred), synthetic data, mocked payment gateways, and observability including **Laravel Horizon** (installed for queue supervision).

**Key architectural findings from code review:**

- **Laravel Horizon** manages Redis queue workers in production (`php artisan horizon`). Do not run raw `queue:work` alongside Horizon for the same queues.
- High-volume verification/application list search is **reference-only** (`application_reference`, `qualification_reference`) with prefix matching — no broad `LIKE '%term%'` on holder name, NRC, or JSON metadata.
- Dashboard KPIs use **7/30-day date scoping** for period metrics; current-queue metrics remain unbounded by design but use indexed count queries.

- List pages paginate at **25 rows** via `QualificationsPoolService` — good baseline.
- Qualification **show/edit** pages eager-load many relations (documents, certificates, learner records, audit logs) — high N+1 / payload risk at scale.
- Search on high-volume verification queues uses **prefix reference matching** on indexed `application_number` and `verification_reference_number` columns (implemented).
- Production queues: `payments-high`, `payments`, `notifications`, `default`; repo defaults still use **database** queue/session/cache drivers.
- Upload limit: **3 MB** (configurable via `ZAQA_UPLOAD_MAX_FILE_SIZE_MB`); Nginx allows **25 MB**.
- Session idle timeout: **15 minutes** — will amplify login/session churn under sustained load.
- Payment idempotency is implemented in `PaymentService`, `ApplicationAutoSubmissionService`, and callback → poll job chain — must be validated under duplicate/delayed callbacks.

---

## 2. Scope and non-goals

### In scope

- Load testing strategy and scenario design
- Staging environment sizing and isolation requirements
- Synthetic test data volumes and seeding approach
- User journey coverage (applicant, institution, admin L1/L2, finance, public)
- Payment simulation (sandbox / mock — **never production gateways**)
- Upload load and rejection behaviour
- Queue/Redis resilience and backpressure
- Database query/index review plan for large volumes
- L1/L2 page performance targets and measurement
- Monitoring, metrics, acceptance thresholds
- Resilience / failure injection in staging
- Remediation backlog format (recommendations only)

### Non-goals

- **No production load tests**
- **No destructive tests** against production data or live payment providers
- **No code changes** as part of this document
- **No test script implementation** in this phase (folder structure and outlines only)
- **No schema migrations or index creation** — indexes are recommended, not applied

---

## 3. Assumptions

| # | Assumption | Notes |
|---|------------|-------|
| A1 | Production uses `QUEUE_CONNECTION=redis` | Documented in `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md`; repo `.env.example` still defaults to `database` |
| A2 | Staging mirrors production topology | Separate DB, Redis, storage, workers, Nginx, PHP-FPM |
| A3 | Load tests run against **staging URL only** | DNS + firewall block production from load generator IPs |
| A4 | Payment gateways are **mocked or sandbox** | CyberSource test env; cGrate stub + controlled webhook simulator |
| A5 | “15,000 concurrent users” needs team confirmation | See §4.1 — conservative interpretation proposed |
| A6 | Document files stored on `local` disk by default | `storage/app/private`; S3 optional via `FILESYSTEM_DISK` |
| A7 | Horizon installed; Telescope/Pulse not in codebase | Horizon for queues; external APM + Redis/MySQL metrics for load test analysis |
| A8 | 15-minute session lifetime is intentional | Increases auth/login traffic during long soak tests |

---

## 4. Architecture context (from codebase)

### 4.1 Application stack

- **Backend:** Laravel 12, PHP 8.2+, MySQL
- **Frontend:** Inertia.js + Vue 3 + Vite
- **Auth:** Session guard (`web`), Spatie permissions, 15-minute idle expiry
- **Queues (production target):** Redis — queues `payments-high`, `payments`, `notifications`, `default`
- **Scheduler:** cGrate poll dispatch (every minute, max 50 attempts), `quotations:expire` (daily)

### 4.2 Critical services and jobs

| Domain | Key classes | Queue |
|--------|-------------|-------|
| Mobile money prompt | `DispatchMobileMoneyPaymentPromptJob` | `payments-high` |
| Mobile money polling | `QueryCGratePaymentAttemptJob` | `payments` |
| Auto-verification | `ProcessQualificationAutoVerificationJob` | `default` |
| Institution pull lookup | `PerformInstitutionPullLookupJob` | `default` |
| SMS | `SendSmsJob` | configurable SMS queue |
| Mail listeners | Various `*Mail` listeners | `notifications` / `default` |

### 4.3 Payment flow summary

- **Card:** `ApplicantPaymentController` → CyberSource capture context → confirm → `PaymentService`
- **Mobile money:** initiate → `payments-high` prompt job → `payments` poll job + optional `POST /webhooks/cgrate/payment`
- **Applicant polling:** `GET applicant.payments.attempts.status` (dispatches poll job)
- **Idempotency:** `PaymentService::applyGatewayVerificationResult()`, `ApplicationAutoSubmissionService::submitAfterPaymentSatisfied()`

### 4.4 Verification query layer

- **Central service:** `App\Domain\Verification\QualificationsPoolService`
- **Pagination:** 25 rows, `withQueryString()`
- **Eager loads (lists):** application.applicant, qualificationTypeMaster, awardingInstitution, country, assignedVerifier, level2ReviewOwner
- **Filters:** status, dates, payment_status, foreign, assignment, overdue, **`q` full-text-style LIKE search**
- **Assignment queues:** `VerificationAssignmentQueueScopes` + `leftJoin applications` for sort

### 4.5 Qualification detail pages (performance hotspot)

`AdminVerificationQualificationController@show` loads:

- application, invoice, payments, documents (+ uploadedBy), consent forms, assignments, certificates, learnerRecord, match attempts, subjectResults, audit-driven level1 payload, comments/timeline

`@edit` additionally loads reference catalogues (countries, qualification types, certificate subjects) and application-level documents.

**Risk:** Single qualification page may execute 30–80+ queries and return large Inertia JSON without lazy-loading modals/previews.

---

## 5. Test environment requirements

### 5.1 Environment isolation

| Requirement | Detail |
|-------------|--------|
| Dedicated staging cluster | No shared DB/Redis with production |
| Network isolation | Load generator IPs whitelisted to staging only |
| Secrets | Sandbox CyberSource keys, mock cGrate credentials |
| Data | Synthetic or anonymized — never copy production PII wholesale |
| Storage | Separate volume/S3 bucket; size for 3k × 3 MB ≈ 9 GB minimum test window |

### 5.2 Recommended staging topology (production-like)

```
[Load generators: k6 runners] → [Nginx] → [PHP-FPM pool]
                                      ↓
                              [Laravel app × N]
                                      ↓
                    [MySQL primary]  [Redis]  [Local/S3 storage]
                                      ↓
              [Supervisor: zaqa-horizon (Laravel Horizon — manages all queue workers)]
              [Scheduler: 1 instance, withoutOverlapping jobs]
```

### 5.3 Minimum sizing (starting point — tune with baseline)

| Component | Staging minimum | Notes |
|-----------|-----------------|-------|
| App servers | 2 × 4 vCPU / 8 GB | Match production PHP-FPM `pm.max_children` tuning |
| MySQL | 8 vCPU / 32 GB RAM, SSD | Enable slow query log; `innodb_buffer_pool_size` ≥ 50% RAM |
| Redis | 4 GB memory | Queue + cache if enabled; monitor evictions |
| Horizon | 1 Supervisor process per app server | `php artisan horizon`; worker counts from `config/horizon.php` |
| Load generators | 2–4 k6 runners | Distributed execution for 15k VUs |

### 5.4 Configuration parity checklist

- [ ] `QUEUE_CONNECTION=redis`
- [ ] `HORIZON_PREFIX=zaqa_horizon` (if using shared Redis)
- [ ] Horizon running via Supervisor (`[program:zaqa-horizon]`) — **not** raw `queue:work` workers
- [ ] `SESSION_DRIVER=redis` or `database` (match production)
- [ ] `CACHE_STORE=redis` (if used in production)
- [ ] `ZAQA_UPLOAD_MAX_FILE_SIZE_MB=3` (or agreed production value)
- [ ] `SESSION_LIFETIME=15`
- [ ] Nginx `client_max_body_size` ≥ 25m (matches `docker/nginx/default.conf`)
- [ ] PHP `upload_max_filesize` / `post_max_size` ≥ 25m
- [ ] CyberSource sandbox / `test` payment provider enabled
- [ ] cGrate webhook URL points to staging; IP/token validation documented

### 5.5 Pre-test baseline run (mandatory)

Before peak scenarios, run **low-load baseline** (50 VUs, 15 min) and capture:

- p50/p95/p99 for top 20 routes
- MySQL slow query log (threshold 200 ms)
- PHP-FPM queue depth, max children reached
- Redis memory, connected clients
- Queue depths per queue name
- Error rate and 419 session expiry rate

---

## 6. Test data strategy

### 6.1 Volume targets

| Entity | Target rows | Purpose |
|--------|-------------|---------|
| `applications` | 100,000 | Pool filters, finance reports |
| `qualifications` | 300,000 | ~3 per application average |
| `qualification_documents` | 1,000,000 | Metadata + versioning |
| `payments` / `payment_attempts` | 150,000+ | Payment report load |
| `invoices` | 120,000+ | Quotation/invoice lifecycle |
| `audit_logs` | 2,000,000+ | Detail page / timeline cost |
| `learner_records` | 500,000+ | Auto-verification matching |
| `sessions` | ephemeral | Session store pressure |
| `jobs` / `failed_jobs` | transient | Queue backlog tests |

### 6.2 Data distribution (realistic mix)

| `verification_state` (qualifications) | ~% | Notes |
|---------------------------------------|-----|-------|
| Awaiting assignment / L1 / L2 active | 40% | Drives queue pages |
| Returned to applicant | 10% | Resubmission flows |
| Approved / rejected / certificate issued | 40% | Historical noise |
| Closed / terminal | 10% | Filter exclusion |

| `current_status` (applications) | ~% |
|----------------------------------|-----|
| draft | 15% |
| submitted / in_progress | 35% |
| processed / sent_back | 30% |
| expired_unpaid / rejected | 20% |

### 6.3 Seeding approach (staging only)

1. **Factory batch inserts** — extend existing factories for bulk insert (chunk 1k–5k rows per transaction).
2. **Dedicated artisan command** (future): `staging:seed-load-fixtures {--applications=100000}` — not implemented in this phase.
3. **CSV-driven identifiers** for k6: `tests/load/k6/data/users.csv`, `applications.csv`, `qualification_ids.csv`.
4. **Document metadata without files** for list tests; **real files** (PDF 2.9 MB) for upload subset.
5. **Officer accounts:** 200 Level 1, 50 Level 2, 20 finance, 10 super-admin (pre-seeded, activated).

### 6.4 Data refresh policy

- Snapshot staging DB before each major test campaign
- Restore from snapshot after destructive resilience tests
- Never point seed scripts at production

---

## 7. Load scenarios

### 7.1 Interpreting “15,000 concurrent users”

**Open question for the team** — confirm intended meaning:

| Interpretation | Description | k6 equivalent |
|----------------|-------------|---------------|
| **A (recommended conservative)** | 15,000 **virtual users** with think time; ~1,500–3,000 **active sessions** at peak | 15,000 VUs, 5–30 s sleep between iterations |
| B | 15,000 **logged-in sessions** simultaneously active | 15,000 VUs, session cookie reuse, minimal think time |
| C | 15,000 **HTTP requests per minute** | ~250 RPS aggregate |

**Recommendation:** Plan for **Interpretation A** first (15,000 VUs, staggered activity), then run a **stress variant B** if product owner confirms true simultaneous sessions.

### 7.2 Primary concurrent user scenario

| Phase | Duration | Target |
|-------|----------|--------|
| Ramp-up | 20 min | 0 → 15,000 VUs |
| Sustain | 45 min | 15,000 VUs |
| Ramp-down | 15 min | 15,000 → 0 |

**User mix (of active iterations):**

| Persona | % | Primary routes |
|---------|---|----------------|
| Applicant / institution browsing & wizard | 60% | dashboard, applications, qualification workspace, tracking |
| Upload / payment preparation | 20% | document upload, payment prepare, quotation download |
| Admin / verification queue browsing | 10% | pool, assigned-to-me, awaiting assignment, qualification show |
| Finance / reporting | 5% | finance dashboard, payments report |
| Public certificate verification | 5% | `GET /certificates/{token}` |

**Request mix (approximate):**

- 70% GET (Inertia pages + API polls)
- 20% POST/PATCH (form saves, uploads, payment initiate)
- 10% asset/static (JS/CSS — optional CDN exclusion)

**Expected throughput (initial estimate — validate in baseline):**

- Aggregate: 800–2,500 RPS at peak (depends on think time)
- Applicant dashboard p95 target: < 2 s
- Error rate: < 1% (excluding intentional 422 validation)

### 7.3 Secondary scenarios

| ID | Scenario | VUs / duration | Goal |
|----|----------|----------------|------|
| S2 | Admin morning peak | 500 L1/L2 users, 30 min | Queue pages under filter/search |
| S3 | Payment hour | 7,000 payment VUs, 20 min | Card + MM initiation burst |
| S4 | Upload storm | 3,000 upload VUs, 15 min | Storage + PHP-FPM saturation |
| S5 | Soak test | 3,000 VUs, 8 hours | Memory leaks, queue drift, session table growth |
| S6 | Report burst | 50 finance users, 10 min | Export CSV/PDF |

---

## 8. Critical user journeys to test

### 8.1 Applicant / institution

| # | Journey | Route names / paths | Load notes |
|---|---------|---------------------|------------|
| 1 | Login | `login.store` | Session churn; 15-min expiry |
| 2 | Dashboard | `applicant.dashboard` | Post-login landing |
| 3 | New application | `applicant.applications.create`, `.store` | Draft creation |
| 4 | Add qualification | `applicant.applications.qualifications.store` | Validation + DB writes |
| 5 | Upload documents | `applicant.applications.documents.store` | Multipart, 3 MB cap |
| 6 | Payment / quotation | `applicant.applications.payment.prepare` | Quotation generation |
| 7 | Download quotation | invoice PDF routes | PDF generation cost |
| 8 | Payment attempt | card / mobile money initiate routes | Queue dispatch |
| 9 | Payment status poll | `applicant.payments.attempts.status` | High frequency during MM |
| 10 | Receipt download | receipt PDF routes | Post-payment |
| 11 | Application tracking | `applicant.applications.track` | Timeline queries |
| 12 | Institutional multiple | `applicant.applications.multiple.*` | N qualifications × uploads |

### 8.2 Admin / verification

| # | Journey | Route names | Load notes |
|---|---------|-------------|------------|
| 1 | Admin login | `login.store` | Role permissions |
| 2 | Verification pool | `admin.verification.pool.index` | Paginated 25; LIKE search |
| 3 | Awaiting L1 assignment | `admin.verification.awaiting_level1_assignment` | Join + sort by deadline |
| 4 | Awaiting L2 assignment | `admin.verification.awaiting_level2_assignment` | Same |
| 5 | Assigned to me | `admin.verification.assigned_to_me` | Per-user scope |
| 6 | L1 review show | `admin.verification.qualifications.show` | **Heavy payload** |
| 7 | L2 review show | same | Same |
| 8 | Qualification edit | `admin.verification.qualifications.edit` | Catalog loads |
| 9 | Document preview | `admin.verification.documents.preview` | On-demand only in test |
| 10 | Bulk assignment | bulk assign routes | Transaction + notifications |
| 11 | Approve / reject | decision routes | State transitions + jobs |
| 12 | Issue certificate | certificate issuance | PDF + notifications |

### 8.3 Finance

| # | Journey | Route names |
|---|---------|-------------|
| 1 | Finance dashboard | `admin.finance.dashboard` |
| 2 | Payments report | `admin.reports.payments` |
| 3 | Export | `admin.reports.payments.export` |
| 4 | Payment proof review | `finance.payment_proofs.index`, approve/reject |

### 8.4 Public

| # | Journey | Route names |
|---|---------|-------------|
| 1 | Certificate QR verify | `certificates.verify` |
| 2 | Receipt verify | `receipts.verify` |

---

## 9. Payment load simulation plan

### 9.1 Safety rules

- **Never** point load tests at production CyberSource or cGrate endpoints
- Use `PAYMENT_PROVIDER=test` or CyberSource sandbox credentials in staging
- Run webhook simulation from dedicated **mock server** (k6, WireMock, or small Node service)

### 9.2 Scenario P1 — 3,000 card payment attempts

| Step | Action | Validation |
|------|--------|------------|
| 1 | Login as applicant with unpaid quotation | Session established |
| 2 | `POST applicant.applications.payment.initiate_card` or capture context | 200/302, attempt row created |
| 3 | Simulated confirm / sandbox token | Payment attempt → confirmed |
| 4 | Assert single invoice conversion | One `INV-` per quotation |
| 5 | Assert auto-submission once | `submitted_at` set once |
| 6 | Receipt PDF available | No duplicate receipts |

**Load profile:** 3,000 VUs, ramp 10 min, sustain 20 min, 1 attempt per VU (or loop with unique invoices).

**Metrics:** initiation latency, confirmation latency, duplicate detection count, `jobs` table depth, `failed_jobs` rate.

### 9.3 Scenario P2 — 4,000 mobile money payment attempts

| Step | Action | Validation |
|------|--------|------------|
| 1 | `POST initiate_mobile_money` | Creates `payment_attempts` row |
| 2 | Job on `payments-high` | Prompt dispatch (mock gateway ACK) |
| 3 | Poll job on `payments` | Status transitions |
| 4 | Optional webhook `POST /webhooks/cgrate/payment` | Idempotent re-poll |
| 5 | Applicant poll `attempts.status` every 10 s | Does not duplicate settlement |

**Load profile:** 4,000 VUs, staggered initiation over 15 min; simulate 30–50% receiving delayed callback (+5–30 min).

### 9.4 Payment edge cases (staging)

| Case | Test method | Expected behaviour |
|------|-------------|-------------------|
| Duplicate callback ×5 | Mock server replays same payload | Single settled payment |
| Delayed callback 30 min | Queue delayed job | Eventually consistent; no duplicate invoice |
| Gateway timeout | Mock 504 / hang | Attempt fails gracefully; user sees failed state |
| Failed payment | Mock decline | No auto-submission; quotation remains |
| Concurrent double-click | 2 parallel initiations same invoice | Idempotent attempt handling |
| Poll + callback race | Simultaneous | `PaymentService` serializes correctly |

### 9.5 Payment acceptance criteria

- Zero duplicate confirmed payments for same invoice
- Zero duplicate `INV-` numbers from same quotation
- Zero duplicate auto-submission events
- Callback processing p95 < 1 s (excluding mock gateway delay)
- Queue backlog from payment wave clears within **15 minutes** post-peak
- No unhandled 500 on payment routes

---

## 10. Upload load simulation plan

### 10.1 Scenario U1 — 3,000 uploads

**File matrix:**

| Type | Size | Count | Route |
|------|------|-------|-------|
| Valid PDF | 2.9 MB | 2,400 | `applications.documents.store` |
| Valid JPEG | 1.5 MB | 300 | identity document upload |
| Near-limit PDF | 3.0 MB | 200 | qualification certificate |
| Oversized | 3.1 MB | 100 | Must return 422, not 500 |
| Invalid MIME | 1 MB .exe | 0 (separate security test) | 422 |

**Personas:**

- Individual applicant (certificate, NRC, transcript)
- Institutional multiple (per-qualification uploads)
- Level 1 evaluation report / attachment (admin)
- Level 2 send-back attachment (admin)

### 10.2 Upload metrics

| Metric | Target |
|--------|--------|
| Success rate (valid files) | ≥ 99% |
| p95 upload time (3 MB) | ≤ 10 s |
| PHP memory peak | Within FPM limit; no OOM |
| Disk write latency | Monitor `storage/app/private` or S3 PUT |
| Validation error body | Friendly message with configured MB limit |
| PostTooLarge / 413 | Handled as validation, not 500 |

### 10.3 Infrastructure checks during upload test

- Nginx `client_max_body_size` (25m in repo config)
- PHP `upload_max_filesize`, `post_max_size`, `max_file_uploads`
- Temp directory space (`/tmp`, `storage/framework`)
- Antivirus/scan hooks if added in future
- Concurrent writes to same qualification (versioning correctness)

---

## 11. Queue / Redis resilience plan

### 11.1 Production queue inventory (from codebase)

| Queue | Jobs / listeners | Priority |
|-------|------------------|----------|
| `payments-high` | Mobile money prompt, callback follow-up | Highest |
| `payments` | cGrate polling | High |
| `notifications` | Queued mail | Medium |
| `default` | Auto-verification, institution pull, SMS (if unset), event listeners | Normal |

**Scheduled (not queued):** `cgrate.poll_due_attempts` (every minute, limit 50), `quotations:expire` (daily).

### 11.2 Recommended worker layout (Horizon)

Production runs **one Horizon supervisor** (`php artisan horizon`) configured in `config/horizon.php`:

| Horizon supervisor | Queue | Production min/max | Timeout | Notes |
|--------------------|-------|--------------------|---------|-------|
| `supervisor-payments-high` | `payments-high` | 2 / 10 | 120 s | Mobile money prompts, callback follow-up |
| `supervisor-payments` | `payments` | 2 / 12 | 120 s | Payment polling |
| `supervisor-notifications` | `notifications` | 1 / 6 | 120 s | Email/SMS/portal notifications |
| `supervisor-default` | `default` | 2 / 8 | 300 s | Auto-verification, institution pull, certificates |
| `scheduler` | — | 1 | — | `withoutOverlapping` |

Supervisor example:

```ini
[program:zaqa-horizon]
command=php /var/www/html/zaqa-portal/artisan horizon
autostart=true
autorestart=true
user=www-data
stopwaitsecs=3600
```

Deploy: `php artisan horizon:terminate` after config/route cache refresh.

**Do not** run separate `queue:work` workers for the same queues on the same host.

Horizon dashboard: `/horizon` — Super Admin only (local bypass).

### 11.3 Resilience test matrix

| # | Test | Procedure | Pass criteria |
|---|------|-----------|---------------|
| Q1 | Payment wave backpressure | 7k payments in 10 min | Redis memory stable; no job loss |
| Q2 | Auto-verification burst | 5k qualifications eligible | `default` depth recovers < 30 min |
| Q3 | Notification burst | Mass assignment emails | `notifications` clears < 15 min |
| Q4 | Horizon restart | `supervisorctl restart zaqa-horizon` or `horizon:terminate` mid-load | Jobs retry; no duplicate side effects |
| Q5 | Redis restart (staging) | Brief Redis stop 30 s | Horizon reconnects; failed jobs retryable |
| Q6 | Long job blocking | Slow auto-verification mock | Does not block `payments-high` (separate Horizon supervisors) |
| Q7 | Failed job rate | Monitor `failed_jobs` | < 0.1% of dispatched |
| Q8 | Scheduler overlap | Double scheduler instance | `withoutOverlapping` prevents duplicate poll dispatch |

### 11.4 Redis monitoring during tests

- Memory used / maxmemory policy
- Evicted keys (should be **zero** for queue use)
- Commands/sec, latency
- Connected clients
- Key count per queue (`queues:payments-high`, etc.)
- Blocked clients (BLPOP waiters)

### 11.5 Retry and dead-letter policy (verify in staging)

- Default `tries: 3` per Horizon supervisor in `config/horizon.php`
- Document manual replay: `php artisan queue:retry all`
- Alert if `failed_jobs` > 100 during test window
- Monitor via Horizon dashboard (`/horizon`) during load tests

---

## 12. Large database performance scenarios

### 12.1 Pages under scrutiny

| Page | Route | Service / controller |
|------|-------|----------------------|
| Verification pool | `admin.verification.pool.index` | `QualificationsPoolService::pool()` |
| Assigned to me | `admin.verification.assigned_to_me` | `QualificationsPoolService::assignedToMe()` |
| Awaiting L1 | `admin.verification.awaiting_level1_assignment` | `awaitingLevel1Assignment()` |
| Awaiting L2 | `admin.verification.awaiting_level2_assignment` | `awaitingLevel2Assignment()` |
| Auto-verified pending L2 | `admin.verification.auto_verified.index` | Auto-verified controller |
| Qualification show | `admin.verification.qualifications.show` | `AdminVerificationQualificationController` |
| Qualification edit | `admin.verification.qualifications.edit` | Same |
| Finance payments report | `admin.reports.payments` | `PaymentsRevenueReportService` |
| Certificate registry | `admin.reports.certificates` | Report controller |
| Applicant tracking | `applicant.applications.track` | Tracking controller |

### 12.2 Query patterns to profile (EXPLAIN ANALYZE)

1. **Pool list default** — filter by `verification_state`, order `updated_at DESC`, paginate 25
2. **Search `q=term`** — multi-column `LIKE '%term%'` + JSON path (high cost)
3. **Awaiting assignment sort by deadline** — `leftJoin applications`, `COALESCE(service_deadline_at, ...)`
4. **Count badges** — `countAwaitingLevel1Assignment()`, dashboard KPI counts
5. **Show page** — single qualification with all eager loads
6. **Finance report** — date range + pagination 25

### 12.3 Likely indexes to evaluate (recommendation only — do not apply in this phase)

Existing indexes include: `qualifications.verification_state`, `assigned_verifier_id`, `application_id`, `service_deadline_at`, `applications.current_status`, `application_number`, etc.

**Candidates to benchmark:**

| Table | Index | Rationale |
|-------|-------|-----------|
| `qualifications` | `(verification_state, updated_at)` | Pool default sort/filter |
| `qualifications` | `(assigned_verifier_id, verification_state, updated_at)` | Assigned-to-me L1 |
| `qualifications` | `(level2_review_owner_id, verification_state)` | L2 assigned |
| `qualifications` | `(verification_reference_number)` | Exact lookup (already may exist) |
| `applications` | `(submitted_at, current_status)` | Date filters |
| `qualification_documents` | `(qualification_id, document_type, is_current_version)` | Document counts |
| `payment_attempts` | `(status, next_query_at)` | cGrate polling scheduler |
| `audit_logs` | `(entity_type, entity_id, created_at)` | Timeline on show page |

**Full-text search:** Consider MySQL FULLTEXT or dedicated search (Meilisearch/Elasticsearch) if `q` search exceeds 3 s p95 at volume.

### 12.4 N+1 and payload risks (code review findings)

| Area | Risk | Test assertion |
|------|------|----------------|
| Qualification show | 15+ eager-loaded relations | Query count < 40; consider lazy tabs |
| Qualification edit | Loads all application documents | Paginate or scope to qualification |
| Dashboard KPIs | Multiple `count()` queries | Cache counts 60 s during load |
| Inline preview | Preview URL generation for all docs | Load preview on click only in UI test |
| Institutional multiple | N × qualification rows per application | Workspace save with 50 holders |

---

## 13. Level 1 / Level 2 page performance plan

### 13.1 Performance budgets

| Page type | p95 response | p99 | Max queries | Max SQL time |
|-----------|--------------|-----|-------------|--------------|
| List pages (pool, assigned, awaiting) | **≤ 2 s** | ≤ 4 s | ≤ 30 | ≤ 500 ms |
| Qualification show | **≤ 3 s** | ≤ 5 s | ≤ 40 | ≤ 800 ms |
| Qualification edit | **≤ 3 s** | ≤ 5 s | ≤ 35 | ≤ 800 ms |
| Search with `q` | **≤ 3 s** | ≤ 6 s | ≤ 35 | ≤ 1 s |
| Document preview (single) | **≤ 2 s** | ≤ 4 s | ≤ 10 | ≤ 200 ms |

**Hard rules:**

- No unpaginated list endpoints
- No loading full audit history on initial show (lazy-load modal)
- No generating all preview URLs server-side for 20+ documents

### 13.2 L1/L2 load test script outline

**Scenario `admin_level1_level2_queues.js` (k6):**

1. Login as Level 1 officer (CSV credentials)
2. `GET /admin/verification/assigned-to-me` — measure TTFB + JSON size
3. Random filter: country, institution, `q` search term from dataset
4. `GET /admin/verification/qualifications/{id}` — sample ID from CSV weighted to active states
5. Think time 10–30 s
6. Repeat for Level 2 persona with `awaiting_level2_assignment` and auto-verified lock queue

**Concurrent officers:** 500 VUs × 30 min on **100k+ qualification** staging DB.

### 13.3 Optimization opportunities (report only)

- Split Inertia payload: summary vs. tabs (documents, audit, learner record)
- Cache reference data (countries, qualification types) — application guide §17
- Read replicas for reporting routes
- Materialized view for dashboard counts
- Defer `beginReviewIfAssigned` write if read-only view

---

## 14. Monitoring requirements

### 14.1 Application metrics

| Metric | Source | Alert threshold (staging calibration) |
|--------|--------|--------------------------------------|
| Request rate | Nginx / APM | Informational |
| Response time p50/p95/p99 | k6 + APM | p95 > budget for 5 min |
| HTTP 5xx rate | Nginx logs | > 0.5% |
| HTTP 419 rate | App logs | Track session expiry impact |
| PHP-FPM active/idle workers | Status page | > 90% active sustained |
| Queue depth per queue | Redis / Horizon | > 10,000 |
| Job throughput | Redis | Drop > 50% vs baseline |
| Failed jobs/min | DB `failed_jobs` | > 10/min |
| Cache hit rate | Redis | < 80% if caching enabled |

### 14.2 Database metrics

- Slow query log (≥ 200 ms)
- `Threads_running`, connection count
- InnoDB buffer pool hit rate
- Lock waits / deadlocks
- Top 10 queries by total time (Performance Schema)
- Row counts for hot tables

### 14.3 Redis metrics

- Used memory, fragmentation
- Evictions (must be 0)
- Latency `redis-cli --latency`
- Connected clients

### 14.4 Storage metrics

- Disk usage on `storage/app/private`
- S3 PUT/GET latency and error rate
- Upload temp directory size

### 14.5 Payment-specific metrics

- Initiation success/failure ratio
- Time from initiate → confirmed (p95)
- Duplicate callback detection count
- Receipt PDF generation time
- Quotation → invoice conversion lag

### 14.6 Frontend metrics (browser / Lighthouse / WebPageTest)

- Inertia page JSON payload size (qualification show < 500 KB target)
- Time to interactive on queue pages
- JS heap on large tables
- Console errors during load test window

### 14.7 Recommended tooling gap closure

| Tool | Purpose | Status in repo |
|------|---------|----------------|
| **k6** | HTTP load tests | Not present — **recommended** |
| **Laravel Horizon** | Queue dashboard + worker management | **Installed** — use in staging and production |
| **Laravel Pulse** | App health | Not installed |
| **Telescope** | Request/query debug (staging only) | Not installed |
| **Prometheus + Grafana** | Infra dashboards | External |
| **MySQL Performance Schema** | Query analysis | Enable in staging |
| **Elastic APM / Datadog** | Distributed tracing | Optional |

---

## 15. Resilience / failure tests (staging only)

| # | Failure | Injection method | Expected behaviour |
|---|---------|------------------|-------------------|
| R1 | Redis unavailable 60 s | `iptables` / stop redis | 503 or graceful queue failure; no data corruption |
| R2 | Horizon worker crash | Kill Horizon worker mid-job | Job retries; payment idempotent |
| R3 | Payment callback ×5 | Mock webhook replay | Single settlement |
| R4 | Callback delayed 30 min | Scheduled mock POST | Poll + callback converge once |
| R5 | Gateway timeout | Mock 504 | User sees failed; no orphan invoice |
| R6 | Upload interrupted | Abort multipart | No partial DB row; user can retry |
| R7 | Oversized upload 3.1 MB | k6 file upload | 422 validation message |
| R8 | DB slow query | `SELECT SLEEP(2)` injection via staging flag | Timeouts; no cascade 500 |
| R9 | Storage full / read-only | Chmod volume | Upload fails gracefully |
| R10 | Session expiry mid-wizard | Wait 16 min idle | Redirect login + message; draft preserved |

---

## 16. Acceptance criteria (summary)

| Category | Threshold |
|----------|-----------|
| Overall success rate | ≥ 99% (excl. intentional 422) |
| HTTP 5xx | < 0.5% |
| List pages p95 | ≤ 2 s |
| Detail pages p95 | ≤ 3 s |
| Search/filter p95 | ≤ 3 s |
| Upload p95 (3 MB) | ≤ 10 s |
| Payment callback processing p95 | ≤ 1 s (excl. gateway) |
| Queue drain after peak | ≤ 15 min |
| Duplicate payments/invoices/receipts | **0** |
| Duplicate auto-verification jobs | **0** |
| Validation/upload/session errors | No 500; friendly 422/419/redirect |
| L1/L2 pages | Paginated; no full-table scan to browser |

---

## 17. Tools recommended

| Tool | Role | Priority |
|------|------|----------|
| **k6** | Primary HTTP load generator | **P0** |
| k6 Browser (optional) | Inertia UX validation | P2 |
| **Grafana k6 Cloud / InfluxDB** | Results storage | P1 |
| Artillery | Alternative HTTP scenarios | P3 |
| Locust | Python-based uploads | P3 |
| JMeter | Enterprise reporting | P3 |
| WireMock / MockServer | Payment gateway + webhook | **P0** |
| MySQL slow log + PT-query-digest | SQL analysis | **P0** |
| redis-cli INFO / RedisInsight | Queue monitoring | **P0** |
| nginx stub_status / php-fpm status | Web tier | P1 |

---

## 18. Example k6 folder structure (future implementation)

```text
tests/load/
  k6/
    README.md
    lib/
      auth.js              # login helper, session cookie jar
      config.js            # BASE_URL, thresholds
      payments.js          # mock gateway helpers
      uploads.js           # multipart builders
    scenarios/
      applicant_browsing.js
      institutional_multiple_application.js
      card_payments.js
      mobile_money_payments.js
      mobile_money_callbacks.js
      uploads.js
      admin_level1_level2_queues.js
      finance_reports.js
      public_certificate_verify.js
      soak_mixed.js
    data/
      users.csv            # email,password,role
      applications.csv     # id,uuid,status
      qualifications.csv   # id,state,officer_id
      certificate_tokens.csv
      files/
        cert_2.9mb.pdf
        nrc_1.5mb.jpg
        oversized_3.1mb.pdf
    mocks/
      cgrate-webhook-payload.json
      cybersource-sandbox-responses.json
  results/                 # gitignored — JSON, HTML summaries
  sql/
    explain/
      pool_default.sql
      pool_search_q.sql
      assigned_to_me.sql
      awaiting_l1_deadline_sort.sql
      qualification_show.sql
```

### 18.1 Example scenario outline — `admin_level1_level2_queues.js`

```javascript
// Pseudocode outline only — not implemented
import { login } from '../lib/auth.js';
import { thresholds, BASE_URL } from '../lib/config.js';

export const options = {
  stages: [
    { duration: '5m', target: 200 },
    { duration: '20m', target: 500 },
    { duration: '5m', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{page:assigned_to_me}': ['p(95)<2000'],
    'http_req_duration{page:qualification_show}': ['p(95)<3000'],
  },
};

export default function () {
  const user = login(__VU % 200); // Level 1 officers from CSV
  // GET assigned-to-me, optional ?q=, GET qualification show
}
```

### 18.2 Example scenario outline — `mobile_money_payments.js`

```javascript
// Pseudocode — uses mock cGrate + poll endpoint
// 4000 VUs initiate; separate scenario replays webhooks with idempotency checks
```

---

## 19. Risks identified (from codebase analysis)

| ID | Risk | Severity | Area |
|----|------|----------|------|
| RSK-01 | `LIKE '%q%'` search on 300k qualifications | **High** | L1/L2 pool |
| RSK-02 | Qualification show eager-loads large object graph | **High** | Detail pages |
| RSK-03 | Database queue driver in repo defaults vs Redis in prod | **High** | Misleading local/staging parity |
| RSK-04 | 15-minute session → login storm on soak tests | **Medium** | Auth / sessions |
| RSK-05 | Payment poll + applicant poll + scheduler triple pressure | **High** | Payments |
| RSK-06 | No Pulse/Telescope — limited runtime introspection during tests | **Medium** | Observability |
| RSK-07 | Dashboard `count()` on large tables | **Medium** | Admin home |
| RSK-08 | Audit log growth on show page | **Medium** | Detail pages |
| RSK-09 | Local disk storage for 3k concurrent uploads | **High** | Uploads |
| RSK-10 | JSON metadata search paths not indexed | **High** | Search |
| RSK-11 | Auto-verification job on `default` queue may lag behind payments | **Medium** | Queues |
| RSK-12 | cGrate webhook throttle 120/min may drop simulated callbacks | **Low** | Load test design |

---

## 20. Recommended remediation backlog format

When bottlenecks are confirmed in staging, log items as:

```text
PERF-{NNN} | Priority (P0–P3) | Area | Finding | Evidence (k6/SQL trace) | Recommendation | Effort | Owner
```

**Example entries (hypothetical until tests run):**

| ID | P | Area | Finding | Recommendation |
|----|---|------|---------|----------------|
| PERF-001 | P0 | DB | Pool search p95 8 s @ 300k rows | **Done:** reference-only prefix search on indexed columns |
| PERF-002 | P0 | App | Show page 120 queries | Lazy-load tabs; reduce eager loads |
| PERF-003 | P0 | Infra | payments-high backlog | Increase Horizon `supervisor-payments-high` maxProcesses in `config/horizon.php` |
| PERF-004 | P1 | App | Dashboard 12 count queries | Cache KPIs 60 s |
| PERF-005 | P1 | DB | Awaiting L1 sort filesort | Composite index `(verification_state, service_deadline_at)` |
| PERF-006 | P2 | Ops | Horizon installed | Monitor `/horizon` in staging + prod |
| PERF-007 | P2 | Storage | Disk IO saturate @ 3k uploads | Move to S3 + multipart |

---

## 21. Open questions for the team

1. **What exactly does “15,000 concurrent users” mean?** (VUs with think time vs 15k simultaneous authenticated sessions vs RPS target)
2. **Production Redis topology:** single instance, sentinel, or cluster? Memory limit?
3. **Production Horizon supervisor settings** — current `minProcesses` / `maxProcesses` per queue in `config/horizon.php`?
4. **Session driver in production:** Redis or database? (Impacts 15k session load)
5. **Storage backend in production:** local NFS vs S3? CDN for downloads?
6. **CyberSource production vs sandbox** credentials for staging load tests?
7. **cGrate rate limits** — max initiations per minute in sandbox?
8. **Horizon snapshot schedule** — is `horizon:snapshot` in cron for throughput metrics?
9. **Acceptable queue lag** for auto-verification after payment peak (minutes/hours)?
10. **SLA for finance report exports** — max rows / date range?
11. **Institutional multiple max qualifications per application** — worst-case for performance?
12. **Are read replicas** available for reporting queries?
13. **Target RPO/RTO** if Redis fails during business hours?
14. **Can staging receive 100k+ row seed**, or incremental seed acceptable?

---

## 22. Implementation checklist (for a later phase)

Use this when the team approves script implementation:

- [ ] Provision staging environment matching production topology
- [ ] Confirm “15,000 concurrent” definition with stakeholders
- [ ] Build synthetic seed command / dataset to target volumes
- [ ] Deploy payment mock (CyberSource sandbox + cGrate webhook simulator)
- [ ] Install observability: slow query log, Redis monitoring, APM
- [ ] Create `tests/load/k6/` structure per §18
- [ ] Run baseline (50 VUs) and record thresholds
- [ ] Run scenario S1 (15k VUs conservative mix)
- [ ] Run payment scenarios P1 + P2 with idempotency assertions
- [ ] Run upload scenario U1
- [ ] Run L1/L2 scenario on seeded 300k qualifications
- [ ] Run resilience matrix R1–R10
- [ ] EXPLAIN ANALYZE critical SQL; file PERF backlog items
- [ ] Publish test report with graphs and remediation priorities
- [ ] Re-test after P0 fixes

---

## Appendix A — Key route reference

| Name | Path |
|------|------|
| `admin.verification.pool.index` | GET `/admin/verification/pool` |
| `admin.verification.assigned_to_me` | GET `/admin/verification/assigned-to-me` |
| `admin.verification.awaiting_level1_assignment` | GET `/admin/verification/awaiting-level1-assignment` |
| `admin.verification.awaiting_level2_assignment` | GET `/admin/verification/awaiting-level2-assignment` |
| `admin.verification.qualifications.show` | GET `/admin/verification/qualifications/{id}` |
| `admin.verification.qualifications.edit` | GET `/admin/verification/qualifications/{id}/edit` |
| `applicant.payments.attempts.status` | GET payment attempt status (polling) |
| `webhooks.cgrate.payment` | POST `/webhooks/cgrate/payment` |
| `certificates.verify` | GET `/certificates/{token}` |
| `applicant.applications.multiple.create` | GET `/applicant/applications/multiple/new` |

## Appendix B — Related internal documentation

- `docs/zaqa_qualification_verification_implementation_guide.md` — §17 Performance and Scalability
- `docs/01_FOUNDATION_AND_ARCHITECTURE.md` — performance rules
- `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md` — queue worker examples
- `docs/07_NOTIFICATIONS_FILES_REPORTING_SECURITY_AND_TESTING.md` — testing strategy

---

*End of plan — analysis only; no application code was modified to produce this document.*
