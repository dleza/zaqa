# 07 — Notifications, Files, Reporting, Security, and Testing

## Objective
Implement the platform-wide operational qualities that make the system complete and production ready: notifications, file management, reporting, security controls, auditability, and automated testing.

## Notifications
The source requirements explicitly require notifications to the applicant by email address or phone number at several points.

### Channels
Support:
- Email
- SMS
- In-app database notifications

### Required notification events
- account activation token or OTP
- account activation success
- application successfully submitted
- payment receipt available on portal
- application has exceeded service time
- application sent back
- verification certificate ready for download
- certificate issued and process ended
- proof of payment received by finance if internal alerts are used
- assignment alerts for verification staff

### Notification implementation rules
- queue all outbound notifications
- store delivery logs
- support retries
- support template-based messages
- support channel fallback where policy requires
- include certificate verification link in issuance-related email where required

## File management
The system relies heavily on document uploads and generated documents.

### Supported file categories
- NRC copy
- passport copy
- certificate copy
- transcript
- foreign consent form
- deposit slip
- bank transfer proof
- generated receipt
- generated certificate
- rejection notice if implemented

### Storage rules
- private storage by default
- signed temporary URLs for authorized downloads
- original filename preserved for display
- generated internal storage name for uniqueness
- hash every file
- maintain file size and MIME metadata
- support versioning for resubmissions

### File protection rules
- allowlist MIME types
- scan uploads for malware
- reject oversized files
- prevent public directory storage
- verify authorization on every download
- log file access for sensitive documents if required by policy

## Reporting
The system requirements mention search, filters, import/export, and audit trail. Reporting should be implemented across modules.

### Core reports
- applications by status
- application lifecycle events by stage (wizard/payment/submission/review/decision)
- applications by local vs foreign
- applications by country of award
- applications by awarding institution
- overdue applications
- payment volumes by method
- receipts issued by date
- certificates issued by date
- rejections by date and category
- sent-back rates
- reviewer throughput
- SLA breach counts
- feedback score distribution

### Export formats
- CSV for tabular reporting
- PDF for printable summaries where needed
- background-generated exports for large datasets

## Search strategy
### Base implementation
Use MySQL indexes and server-side filtering first.

### Future enhancement
If the dataset grows significantly, add:
- Laravel Scout
- Meilisearch or Elasticsearch

## Security controls
### Identity and access
- strong password rules
- email and phone verification
- MFA for internal users strongly recommended
- role-based access control using spatie/laravel-permission
- per-record authorization through policies

### Sensitive operations requiring enhanced logging
- payment confirmation
- payment rejection
- certificate issuance
- certificate reissue
- certificate status change
- application sent-back and resubmission events
- reviewer acknowledge/review-start events
- role change
- permission change
- user disable and enable
- manual setting changes

### Web application security
- CSRF protection
- output escaping
- strict validation
- rate limiting on login and OTP
- rate limiting on public verification lookups
- secure session configuration
- secure file uploads
- signed URLs for sensitive downloads

### Data protection
- encrypt sensitive values where necessary
- keep logs tamper-evident as far as practical
- separate public verification data from internal review data
- avoid exposing personally sensitive information on public pages

## Audit logging
Standardize on a single, append-only `audit_logs` system that captures both:
- immutable sensitive change records (audit trail)
- operational, user-facing histories (activity feed) via descriptive messages and metadata

### Audit fields (minimum)
- actor user id when available
- actor name snapshot where useful
- event type
- module or domain
- entity type and entity id
- action name and descriptive message
- before state as JSON where applicable
- after state as JSON where applicable
- metadata as JSON where useful
- IP address where available
- user agent where available
- request id or correlation id where possible
- timestamp

## Testing strategy
### Unit tests
- services
- value objects
- state transition rules
- receipt numbering logic
- certificate numbering logic
- file validation logic

### Feature tests
- registration and activation
- application creation and submission
- local and foreign application validation
- draft saving
- sent-back resubmission
- invoice generation
- payment confirmation
- proof review
- receipt generation
- assignment rules
- Level 1 and Level 2 permissions
- approval and rejection
- certificate issuance
- certificate verification page
- admin reissue
- role management permissions
- notification dispatch
- audit log creation

### Browser or end-to-end tests
Cover critical user paths:
- applicant happy path
- manual bank proof flow
- verification review flow
- certificate issue and public verification flow

### Test data
Create factories and seeders for:
- users by role
- applicants
- local applications
- foreign applications
- invoices
- payments
- certificates
- reference data

## Operational monitoring
Implement:
- Horizon monitoring
- failed job review process
- structured logs
- error alerting
- export job status tracking
- payment webhook failure alerts
- certificate generation failure alerts

## Definition of Done
This phase is complete when:
- all required emails and SMS notifications are implemented and queued
- file storage, access control, validation, scanning, and versioning are complete
- reports, filters, and exports cover operational needs
- security controls are enforced for authentication, authorization, uploads, and public verification
- audit logs capture all sensitive and operational events
- automated unit, feature, and end-to-end coverage exists for major workflows
- the system has no missing production-readiness controls or placeholder operational features
