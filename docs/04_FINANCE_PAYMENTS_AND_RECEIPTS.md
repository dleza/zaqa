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

## Fee master data and versioning (critical)
Fees must be configured as first-class entities:
- qualification types (ZQF levels) map into billing categories
- billing categories drive processing time and fee logic
- fee structures are **effective-dated versions** per billing category

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
