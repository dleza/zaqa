# 04 — Finance, Payments, and Receipts

## Objective
Implement the full finance module for invoicing, payment collection, proof review, receipt generation, search, filtering, auditability, and reporting.

## Finance users
Finance team accounts must be created by system administrators.

Roles to support:
- Finance Officer
- Finance Supervisor if needed
- Admin with finance oversight

## Invoice requirements
The system must generate an invoice for verification and evaluation services.

### Invoice behavior
- every application that reaches billable submission must have an invoice
- invoice number must be unique
- invoice amount must come from system-managed fee rules (fee structures)
- invoice must be visible to the applicant in the portal
- invoice status must be tracked separately from payment status
- invoice is the immutable billing record; it must not be altered when the applicant switches payment method
- applicants and authorized finance users can download invoices as PDF from invoice and payment pages
- applicants and authorized finance users can download official payment receipts as PDF for **confirmed** payments only (`PaymentReceiptPdfService`, route `applicant.payments.receipt.download` / `admin.finance.payments.receipt.download`)
- receipt PDFs include a QR code linking to the public verification page (`GET /receipts/{token}`)
- receipt and certificate PNG signatures are managed under **System Settings → Document Signatures**

## Fee master data and versioning (critical)
Fees must be configured as first-class entities:
- qualification types (ZQF levels) map into billing categories
- billing categories drive processing time and fee logic
- fee structures are **effective-dated versions** per billing category

Foreign qualification billing rule:
- **country of award controls locality**:
  - Zambia => local
  - non-Zambia => foreign
- if an application is foreign (`is_foreign = true`), billing must use the **Foreign Qualifications** fee path (billing category `FOREIGN_QUALIFICATIONS`) regardless of the selected qualification type’s normal local billing category mapping
- invoice generation and the applicant Step 2 fee preview must use the same resolver logic (no mismatches)

Historical correctness rules:
- do not compute fees only dynamically at render time
- when an invoice is generated, store:
  - applied `fee_structure_id`
  - `billing_category_id`
  - `qualification_type_id`
  - billed amount + currency
  - local/foreign snapshot context
  - processing time snapshot where useful
- later fee changes must not alter already-issued invoices

### Invoice fields
- invoice number
- application number
- applicant details
- service type
- amount
- currency
- generated date
- due date where applicable
- status

## Payment methods required
Support through integration or controlled workflows:
- Mobile Money
- VISA
- Bank deposit
- Bank transfer

## Payment architecture
Use a provider abstraction layer.

### Online methods
- Mobile Money
- VISA

These should support:
- payment initiation
- callback or webhook handling
- transaction verification
- idempotent reconciliation

### Manual methods
- Bank deposit
- Bank transfer

These should support:
- applicant uploads proof of payment
- proof routed to finance queue
- finance review and manual confirmation
- rejection with reason if proof is invalid

## Payment flow
1. invoice generated
2. applicant selects payment mode
3. system initiates online payment or collects proof for manual payment
4. payment record created with pending status
5. finance or gateway confirms payment
6. receipt generated
7. receipt stored on applicant portal
8. application payment state updated
9. audit trail recorded

## Applicant wizard integration (required)
The applicant application wizard must include a dedicated **Payment** step:
- order: Consent → **Payment** → Review & Submit
- the applicant must not be able to finally submit the application until payment is **confirmed**

Payment accounting rules:
- payment methods are ways to settle the same invoice (they do not redefine the invoice)
- payments link to both `application_id` and `invoice_id`
- once a payment is confirmed and the invoice is settled, the Payment step becomes **read-only** for applicants:
  - hide payment method options
  - show invoice + payment summary only
  - block any attempt to initiate a different payment method

Confirmation rules:
- VISA/card: provider callback/webhook marks payment successful
- Mobile Money: provider callback/status update marks payment successful
- Bank deposit/transfer: finance manual approval confirms payment (rejection requires a reason)

## Mobile Money gateway: cGrate (Konik) (implemented)

Production async architecture, Horizon-managed Redis queues, and applicant-safe status mapping:
see `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md`.

The applicant Mobile Money flow is implemented using cGrate’s Konik SOAP webservice (push + poll):

1. Initiate payment (`processCustomerPayment`) which triggers a USSD/STK prompt on the customer phone.
2. Store a local `payment_attempts` row (gateway = `cgrate`) and set `payments.status = pending_confirmation`.
3. Poll cGrate (`queryCustomerPayment`) using the server-generated `payment_reference` until a terminal status is reached.
4. Only mark the invoice/application paid after the query confirms approval.

### Key files
- `config/cgrate.php` — gateway configuration (env-driven)
- `app/Domain/Payments/Gateways/CGrate/CGrateClient.php` — SOAP over HTTP client (manual XML; no PHP SOAP extension)
- `app/Domain/Payments/Gateways/CGrate/CGratePaymentGateway.php` — `PaymentGateway` adapter for Mobile Money
- `app/Jobs/Payments/QueryCGratePaymentAttemptJob.php` — polling job (query + status update)
- `app/Domain/Payments/CGratePollingService.php` + `bootstrap/app.php` — scheduled sweep for due attempts (every minute)

### Environment variables
Required:
- `CGRATE_ENABLED` (`true|false`)
- `CGRATE_BASE_URL` (e.g. `https://test.543.cgrate.co.zm`)
- `CGRATE_USERNAME`
- `CGRATE_PASSWORD`

Operational:
- `CGRATE_TIMEOUT`, `CGRATE_CONNECT_TIMEOUT`
- `CGRATE_VERIFY_SSL` (default `true`; set `false` only for staging environments with invalid TLS)
- `CGRATE_POLL_INTERVAL_SECONDS`, `CGRATE_MAX_QUERY_ATTEMPTS`, `CGRATE_PAYMENT_EXPIRY_MINUTES`
- `CGRATE_DEFAULT_CURRENCY` (default `ZMW`)

Formatting/safety:
- `CGRATE_AMOUNT_MODE` (`kwacha_decimal` or `minor_units`)
- `CGRATE_MSISDN_FORMAT` (`local` or `international_without_plus`)
- `CGRATE_UNKNOWN_FAIL_AFTER_ATTEMPTS` (how long to tolerate transient “unknown/no transaction”)

### SOAP endpoint + auth
- Endpoint: `${CGRATE_BASE_URL}/Konik/KonikWs`
- Namespace: `http://konik.cgrate.com`
- Auth: WS-Security `UsernameToken` with `PasswordText` in SOAP header.

### Status / response code handling
Important response codes (simplified mapping):
- Pending (keep polling): `206` (PENDING_APPROVAL), `8` (PROCESSING_DELAY), `17` (TIMEOUT)
- Confirmed (settle invoice/application): `207` (APPROVED), `226` (PAYMENT_PROCESSED)
- Rejected: `208` (REJECTED)
- Failed: `7` (INVALID_MSISDN), `210` (PAYMENT_FAILED), `214` (TRANSACTION_REVERSED)
- Unknown/no transaction: `12` (UNKNOWN_TRANSACTION), `213` (NO_TRANSACTIONS_FOUND) → treated as pending for the first `CGRATE_UNKNOWN_FAIL_AFTER_ATTEMPTS` polls

Notes:
- `responseCode = 0` indicates a successful SOAP request, but is not treated as a final “paid” state on queries by default (environment-dependent).

### Audit / logging
- Gateway events are recorded via the existing `payment_webhook_logs` table using event types such as `cgrate.query` / `cgrate.expired`.
- Payloads are sanitized (no credentials / WSSE headers are stored).

### UAT tooling
Command (non-production by default):
- `php artisan cgrate:test-payment {mobile} {amount} --query --wait=10`

## Receipt requirements
The system must generate a receipt after successful payment. A copy of the receipt must be stored on the applicant portal.

### Receipt design rules
- receipt number must be unique
- receipt number should bear the payment source and application ID as the reference logic
- receipt must capture invoice and payment linkage
- receipt must be available for applicant download
- receipt generation must be reproducible and auditable

## Proof upload requirements
Applicants must be able to upload:
- bank deposit slip
- bank transfer proof

The original requirement also states notification to finance on proof upload.

Implementation:
- create a pending proof review queue
- notify finance immediately by email and in-app alert
- allow finance to accept or reject proof with comment
- on acceptance, confirm payment and trigger receipt generation
- on rejection, notify applicant with reason and next action

## Search and filters
The system must have search and filter functionality to easily retrieve and view specific invoices, payments, or receipts.

### Finance filters
- invoice number
- application number
- applicant name
- payment method
- payment status
- receipt number
- date range
- proof review state
- amount range
- service type

## Import and export
The requirements mention easy-to-use import/export functionality.

Implement:
- CSV export for invoices
- CSV export for payments
- CSV export for receipts
- controlled import tools for reconciliation data where needed
- strict validation on imports
- import audit history

## Audit trail
The finance module must have a full audit trail.

Track:
- invoice generation
- payment initiation
- proof upload
- proof review
- manual confirmation
- receipt generation
- payment status changes
- export generation
- import execution

## Integration rules
Because the requirements mention integration with other systems:
- separate payment gateway integrations from finance core logic
- use service contracts and adapters
- log raw gateway payloads in JSON
- ensure webhook idempotency
- support retry-safe verification

## Internal dashboards
### Finance dashboard widgets
- total pending payments
- pending proof reviews
- confirmed payments today
- receipts issued today
- rejected proofs
- revenue by payment method
- overdue pending confirmations

### Finance work screens
- invoice list
- payment list
- proof review queue
- receipt list
- export center
- reconciliation log

## Finance back-office pages (implemented)
Finance operations are part of the admin portal and are permission-gated. Routes:

- `GET /admin/finance` — Finance dashboard (`finance.dashboard.view`)
- `GET /admin/finance/payment-proofs` — Payment proof review queue (`finance.payment_proofs.view`)
- `GET /admin/finance/payment-proofs/{payment}` — Proof detail/review page (`finance.payment_proofs.view`)
- `POST /admin/finance/payment-proofs/{payment}/approve` — Approve proof (`finance.payment_proofs.approve`)
- `POST /admin/finance/payment-proofs/{payment}/reject` — Reject proof (`finance.payment_proofs.reject`)
- `GET /admin/finance/payments` — Processed payments registry with server-side filters (`finance.payments.view`)
- `GET /admin/finance/payments/{payment}` — Payment detail page (`finance.payments.detail`)

### Proof review model notes
The system does not use a separate `payment_proofs` table. Manual proof uploads are stored as:

- `payments.proof_document_id` → `qualification_documents.id` (document type `payment_proof`)
- `payments.status = awaiting_finance_review` after upload
- review metadata stored on `payments` (`reviewed_by_user_id`, `reviewed_at`, `review_comment`, `rejection_reason`)

### Approval / rejection behavior
When finance approves a proof:
- `payments.status` becomes `confirmed` and `confirmed_at` is set
- the invoice is settled (`invoices.status = paid`, `paid_at` set)
- the application is marked paid (`applications.paid_at` set) for submission gating
- lifecycle milestone recorded (`payment.finance_approved`) and audit log recorded (`finance.payment_approved`)
- applicant notifications are dispatched (email + SMS log)

When finance rejects a proof:
- `payments.status` becomes `rejected` and `rejected_at` is set (reason required)
- lifecycle milestone recorded (`payment.finance_rejected`) and audit log recorded (`finance.payment_rejected`)
- applicant notifications are dispatched (email + SMS log)

Idempotency rules:
- approving an already-confirmed payment is a no-op (no duplicate confirmation)
- rejecting a confirmed payment is blocked

### Applicant notifications
Manual proof outcomes trigger queued notifications:
- **Approved**: `PaymentProofApproved` → `SendPaymentProofApprovedNotification` → `PaymentProofApprovedMail`
- **Rejected**: `PaymentProofRejected` → `SendPaymentProofRejectedNotification` → `PaymentProofRejectedMail`

## Backend services
Create:
- `InvoiceService`
- `PaymentService`
- `PaymentGatewayManager`
- `PaymentProofService`
- `ReceiptService`
- `FinanceSearchService`
- `FinanceExportService`

## Critical validation and rules
- receipt cannot be created before payment confirmation
- payment confirmation must capture confirming user for manual flows
- online webhook handlers must be idempotent
- proof rejection must require a comment
- all finance actions must generate audit log records
- applicant portal must always show current invoice and payment state clearly

## UI expectations
### Applicant finance area
- outstanding invoice card
- pay now actions
- upload proof flow for manual methods
- receipt download card
- clear payment status badge

### Finance back-office
- filterable data tables
- bulk export actions
- side panel for proof review
- receipt preview
- audit trail drawer

## Definition of Done
This phase is complete when:
- invoices are generated correctly and linked to applications
- all four required payment methods are implemented through proper flows
- manual proof upload and finance review are complete
- receipt generation is automatic after confirmed payment
- receipts are visible in applicant portal
- finance search, filters, exports, and audit logs are fully implemented
- payment integrations follow adapter patterns without controller-bound logic
- no TODOs, placeholders, or missing payment states remain
