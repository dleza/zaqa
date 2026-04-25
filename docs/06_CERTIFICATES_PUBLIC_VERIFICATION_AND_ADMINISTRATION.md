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

## Reissue on technical grounds
Systems Administrators must be able to re-issue a certificate that failed to be issued on technical grounds.

Implementation rules:
- reissue allowed only to authorized admin roles
- reason must be recorded as technical failure
- original certificate must remain preserved in history
- reissued certificate should reference original certificate
- applicant should be notified of successful reissue where applicable
- public verification should point to valid latest certificate state

## Notice of Rejection
Where Level 2 rejects an application, support a formal generated rejection notice document.

Implementation:
- use templated PDF
- store as private file
- expose applicant download if policy allows
- include decision date and controlled reason text

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
