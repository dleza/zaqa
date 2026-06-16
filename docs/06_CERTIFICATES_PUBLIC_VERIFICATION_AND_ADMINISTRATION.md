# 06 — Certificates, Public Verification, and Administration

## Objective
Implement certificate issuance, certificate security controls, public verification, technical reissue handling, and system administration capabilities.

## Certificate requirements from source
The system must:
- issue certificates based on prescribed templates
- include security features:
  - Quick Response Code
  - Court of Arms background watermark
  - ZAQA logo
  - Director General signature
- allow Level 2 to issue Certificate of Recognition or Notice of Rejection
- allow Level 2 to change status of an issued certificate with comment
- allow Systems Administrators to re-issue a certificate that failed to be issued on technical grounds

## Qualification verification certificate (CVEQ)
The **Certificate of Verification and Evaluation of Qualification (CVEQ)** is issued **per qualification** (a single application may list several qualifications; each can receive its own CVEQ once that line is approved and paid).

- **Eligibility:** parent application `verification_state` is `approved_for_certificate`, cumulative **confirmed** payments cover the current fee total, and the qualification’s `verification_state` is `approved_for_certificate` (or, for **reissue** only, `certificate_issued` and the user is **Super Admin**).
- **Admin action:** users with `verification.certificate.issue` use **Issue Certificate** on the qualification verification page. **Super Admin** may **Reissue**, which supersedes the prior active row (old row status `reissued`, new row `issued`).
- **Output:** PDF generated via **DomPDF** from `resources/views/pdf/qualification-certificate.blade.php`, stored on the `local` disk at `qualification-certificates/{year}/{qualification_id}/{verification_token}.pdf`, with a `qualification_certificates` record and a **QR code** pointing at `config('certificates.verify_url_base')/{token}` (no PII in the QR payload).
- **Applicant:** receives email `QualificationCertificateIssuedMail` with the PDF attached; can **download** only their own certificate from the application/qualification UI (authorization on the parent application).
- **Admin registry:** Staff with `admin.certificates.view` can open **`/admin/certificates`** to search all CVEQ rows (issued / reissued / revoked), open the related verification task when they also have `verification.pool.view`, and **download PDF** via **`/admin/certificates/{id}/download`**.

## Certificate generation pipeline
### Trigger
Certificate generation occurs only after:
- application approved by Level 2
- certificate issuance action confirmed
- required payment and review conditions already satisfied

### Steps
1. load certificate template
2. load application and qualification data snapshot
3. generate verification token and QR payload
4. render certificate HTML
5. apply watermark, logo, and signature asset
6. generate PDF
7. hash generated output
8. store private certificate file
9. create certificate database record
10. create public verification record via token
11. log issuance and notify applicant

## Certificate contents
At minimum include:
- certificate number
- qualification holder name or masked public representation depending on policy
- qualification title
- issuing authority information
- date issued
- QR code
- Court of Arms watermark
- ZAQA logo
- Director General signature
- verification instructions or tokenized lookup

## Public verification page
A public verification page must exist so that certificate authenticity can be checked.

### Public page should show
- verification result
- certificate number
- certificate status
- qualification title
- issue date
- limited holder representation according to privacy rules
- whether the certificate is valid, reissued, revoked, or technically failed

### Public page must not expose
- sensitive applicant data beyond defined public verification scope
- internal review notes
- full identity data if not policy-approved
- financial information

## QR code behavior
The QR code should resolve to the public verification endpoint.

Recommended QR target:
- signed or tokenized verification URL using certificate verification token

## Certificate statuses
Support:
- issued
- reissued
- revoked
- technically_failed

If the business wants a separate suspended state later, implement via extensible enum structure.

## Certificate status change rules
The source says Level 2 must be able to change the status of an issued certificate with a comment.

Implementation:
- require comment on status change
- preserve certificate status history
- audit who changed it and when
- show current status in public verification page

## Certificate revocation (Phase 1A — implemented)

Implementation:
- permission: `certificates.revoke`
- route: `POST /admin/certificates/{certificate}/revoke`
- required internal reason; optional public note shown on the public verification page
- revoked certificate row and PDF are preserved; status becomes `revoked`
- public QR token continues to resolve but shows recalled / no longer valid
- after revocation, Level 2 may issue a replacement CVEQ (new number and token) when payment and qualification state allow
- technical Super Admin reissue (`reissued` status) remains separate from business revocation

## Reissue on technical grounds
Systems Administrators must be able to re-issue a certificate that failed to be issued on technical grounds.

Implementation rules:
- reissue allowed only to authorized admin roles
- reason must be recorded as technical failure
- original certificate must remain preserved in history
- reissued certificate should reference original certificate
- applicant should be notified of successful reissue where applicable
- public verification should point to valid latest certificate state

## Rejection certificates (Phase 1B — implemented)

When a qualification is rejected, Level 2 / Super Admin may issue a formal rejection notice stored in `qualification_certificates` with `certificate_type = rejection`.

Implementation:
- route: `POST /admin/verification/qualifications/{qualification}/issue-rejection-certificate`
- permission: `verification.certificate.issue`
- numbering: `ZAQA-REJ-{YEAR}-{000001}`
- PDF template: `resources/views/pdf/rejection-certificate.blade.php`
- public QR shows rejection notice verified (not approval certificate)
- rejection certificates can be revoked via the same Phase 1A workflow
- after revocation, another rejection notice may be issued if the qualification remains rejected
- cross-type issue: if a revoked rejection exists and the qualification is later set to `approved_for_certificate`, a verification CVEQ may be issued and linked via `replaces_certificate_id`
- at most one active `issued` certificate per qualification (verification or rejection)

## Notice of Rejection
Where Level 2 rejects a qualification, a formal generated rejection notice document is available via **Issue rejection certificate** on the qualification review page.

Implementation:
- templated PDF with applicant-visible decision summary when available
- stored as private file in `qualification_certificates`
- public QR verification at `/certificates/{token}`
- include decision date and controlled reason text (applicant-visible comments only)

## Level 2 decision reopen after revocation (Phase 1C — implemented)

When all active certificates for a qualification have been revoked, authorized Level 2 officers and Super Admins may formally reopen the Level 2 decision instead of manually changing qualification state.

Implementation:
- permission: `verification.decision.reopen`
- route: `POST /admin/verification/qualifications/{qualification}/reopen-level2-decision`
- requires reason, intended review action, and confirmation
- blocked while an active `issued` certificate exists
- blocked when no revoked certificate history exists
- sets qualification to `under_level2_review` and assigns `level2_review_owner_id` to the reopening actor
- audit: `verification.level2_decision_reopened_after_certificate_revocation`
- after reopen, normal Level 2 approve/reject actions apply; appropriate certificate type may then be issued

## Applicant rejection notice download (Phase 1C — implemented)

Applicants may download an active rejection notice from their application show/edit pages when `certificate_type = rejection` and status is `issued`.

Route: `GET /applicant/applications/{application}/qualifications/{qualification}/rejection-notice`

Revoked rejection notices are not offered as the current downloadable outcome; a recalled notice message is shown instead.

## Certificate revocation applicant notification (Phase 1C — implemented)

When a certificate is revoked, the applicant receives:
- a portal notification (`certificates.certificate_revoked`)
- an email when an applicant email address is available

Internal revocation reasons are never included. `revocation_public_note` is included when present.

Optional reject modal checkbox: **Generate rejection notice after rejection** (unchecked by default).

## Administration requirements
Systems Administrators must be able to:
- create all back-office processing accounts
- re-issue failed certificates on technical grounds
- access database and system management functions according to environment policy
- change user roles and rights, including reassigning Level 1 to Level 2 or reverse
- modify all user rights including disabling and enabling accounts

## Admin module features
### User management
- create users
- assign roles
- enable users
- disable users
- reset passwords
- force activation reset if necessary
- review audit history (activity feed)

### Role and permission management
- assign roles
- remove roles
- manage permission groupings
- audit every role change
- restrict high-risk permissions to privileged admins only

### Reference data management
- countries
- awarding bodies
- fee rules
- certificate templates
- notification templates
- system settings

### Certificate administration
- template management
- certificate reissue center
- status history review
- verification token troubleshooting
- issuance failure audit

## Services
Create:
- `CertificateTemplateService`
- `CertificateIssuanceService`
- `CertificateVerificationService`
- `CertificateReissueService`
- `AdminUserManagementService`
- `RoleManagementService`

## Security rules
- certificate files stored privately
- downloads provided using signed URLs
- public verification token must be unique and hard to guess
- certificate status changes must require audit entries
- role changes and user disable actions require elevated permissions
- technical reissue must not silently overwrite original records

## UI page map
### Public
- Certificate Verification Page

### Admin
- User Management
- Roles and Permissions
- Awarding Bodies
- Countries
- System Settings
- Template Management
- Certificate Reissue Center
- Certificate Status History
- Audit Monitor

## Definition of Done
This phase is complete when:
- certificates are generated from real templates without placeholders
- QR code, watermark, logo, and signature are included correctly
- public verification is secure and functional
- certificate status history and comment-based changes are implemented
- technical reissue flow is complete and audited
- admin user, role, enable, disable, and reference-data management are production-ready
- rejection notice generation is implemented where applicable
- no certificate or admin workflow relies on manual ad hoc steps outside the system
