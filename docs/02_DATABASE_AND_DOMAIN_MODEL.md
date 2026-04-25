# 02 — Database and Domain Model

## Objective
Define the full production-ready data model for the ZAQA Qualification Verification Platform, ensuring no business rule from the requirements is lost.

## Database engine
Use **MySQL**.

## Why MySQL
- strong relational integrity
- robust indexing
- JSON columns for flexible structured fields
- excellent fit for workflow-heavy business systems
- reliable transactional behavior for finance and certificate issuance

## Core data entities
- users
- applicant_profiles
- institution_profiles
- roles
- permissions
- model_has_roles
- model_has_permissions
- applications
- application_status_histories
- qualifications
- qualification_subject_results
- billing_categories
- qualification_types
- fee_structures
- countries
- awarding_bodies
- qualification_documents
- consent_forms
- invoices
- payments
- receipts
- payment_proofs
- verification_assignments
- verification_reviews
- review_comments
- certificates
- certificate_templates
- certificate_status_histories
- notifications
- sms_logs
- email_logs
- audit_logs
- service_feedback
- system_settings
- reference_data_imports

## Identity model
### users
Fields:
- id
- uuid
- name
- email
- phone_primary
- phone_secondary nullable
- password nullable when using OTP-first activation path
- applicant_type nullable for applicant users
- is_active
- email_verified_at nullable
- phone_verified_at nullable
- last_login_at nullable
- disabled_at nullable
- disabled_reason nullable
- created_at
- updated_at

### applicant_profiles
For individual applicants:
- id
- user_id
- first_name
- middle_name nullable
- surname
- nrc_number nullable
- passport_number nullable
- email
- phone_primary
- phone_secondary nullable
- created_at
- updated_at

### institution_profiles
For institution or organization applicants:
- id
- user_id
- institution_name
- email
- phone_primary
- phone_secondary nullable
- tpin
- contact_person_name nullable
- created_at
- updated_at

## Applications model
### applications
Fields:
- id
- uuid
- application_number
- applicant_user_id
- applicant_type enum: individual, institution
- service_type enum: verification, evaluation
- current_status enum
- is_foreign boolean
- country_id nullable
- awarding_body_id nullable
- qualification_category enum
- assigned_level1_user_id nullable
- assigned_by_level2_user_id nullable
- submitted_at nullable
- paid_at nullable
- completed_at nullable
- service_deadline_at nullable
- sent_back_at nullable
- approved_at nullable
- rejected_at nullable
- metadata JSON nullable
- created_at
- updated_at

### application_status_histories
Store full lifecycle transitions.
Fields:
- id
- application_id
- from_status nullable
- to_status
- changed_by_user_id
- comment nullable
- changed_at
- metadata JSON nullable

## Qualification data
### qualifications
Each application should have one primary qualification record, but the model must still remain extensible.
Fields:
- id
- application_id
- awarding_institution_name
- qualification_holder_name
- country_id nullable
- country_name_other nullable
- awarding_body_id nullable
- awarding_body_name_other nullable
- nrc_passport_number
- certificate_number nullable
- student_number nullable
- examination_number nullable
- title_of_qualification
- award_date
- qualification_type_id (FK → qualification_types)
- qualification_type legacy string (for backward compatibility / human label only)
- transcript_required boolean
- transcript_reason nullable
- notes nullable
- raw_subject_results JSON nullable
- created_at
- updated_at

### qualification_subject_results
Needed for school certificates and equivalents.
Fields:
- id
- qualification_id
- subject_name
- grade
- display_order
- created_at
- updated_at

## Reference data
### countries
Fields:
- id
- iso_code
- name
- is_active
- sort_order

### awarding_bodies
Fields:
- id
- name
- code nullable
- type enum: local, foreign_partner, other
- country_id nullable
- is_active
- sort_order

## Documents
### qualification_documents
Fields:
- id
- application_id
- qualification_id nullable
- document_type enum:
  - nrc_copy
  - passport_copy
  - certificate_copy
  - transcript
  - consent_form_signed
  - payment_proof
  - generated_receipt
  - generated_certificate
  - other_supporting_document
- original_name
- stored_name
- disk
- path
- mime_type
- extension
- size_bytes
- sha256_hash
- visibility enum: private, signed-temporary
- uploaded_by_user_id
- version_number
- is_current_version
- created_at
- updated_at

## Consent
### consent_forms
Fields:
- id
- application_id
- consent_type enum: local_embedded, foreign_uploaded
- embedded_text_version nullable
- agreed_by_name nullable
- agreed_at nullable
- uploaded_document_id nullable
- source_awarding_body_name nullable
- created_at
- updated_at

## Finance
### invoices
Fields:
- id
- application_id
- invoice_number
- amount
- currency
- status enum: draft, issued, pending_payment, paid, cancelled, expired
- generated_at
- due_date nullable
- created_by_user_id
- metadata JSON nullable
- fee_structure_id nullable (FK → fee_structures) — **snapshot of the applied fee version**
- billing_category_id nullable (FK → billing_categories) — snapshot
- qualification_type_id nullable (FK → qualification_types) — snapshot
- is_foreign_snapshot nullable
- processing_days_snapshot nullable
- fee_label_snapshot nullable
- created_at
## Fee master data (reference + versioning)
### billing_categories
Fee-driving categories used for billing and processing time.
Fields:
- id
- name
- code unique
- description nullable
- local_processing_days nullable
- foreign_processing_days nullable
- is_active
- sort_order
- created_at
- updated_at

### qualification_types
ZAQA recognized qualification levels (ZQF 1–10) used in the applicant wizard.
Fields:
- id
- zqf_level_code (e.g. L10, L2A, L2B)
- level_label
- name
- short_name nullable
- description nullable
- billing_category_id
- requires_subject_results boolean
- is_active
- sort_order
- created_at
- updated_at

### fee_structures
Effective-dated fee versions per billing category.
Fields:
- id
- billing_category_id
- local_fee_amount / local_fee_cents nullable
- foreign_fee_amount / foreign_fee_cents nullable
- currency
- effective_from
- effective_to nullable
- is_active
- approved_by_user_id nullable
- change_reason nullable
- created_at
- updated_at

Rules:
- fee resolution must select the active structure effective at billing time
- invoice must store the resolved fee structure reference + billed amount to preserve history
- updated_at

### payments
Fields:
- id
- invoice_id
- application_id
- payment_method enum: mobile_money, visa, bank_deposit, bank_transfer
- reference_number
- amount
- currency
- status enum: initiated, pending_confirmation, confirmed, rejected, failed
- provider_name nullable
- gateway_transaction_id nullable
- gateway_response JSON nullable
- confirmed_by_user_id nullable
- confirmed_at nullable
- paid_at nullable
- created_at
- updated_at

### receipts
Fields:
- id
- invoice_id
- payment_id
- application_id
- receipt_number
- amount
- currency
- payment_method
- issued_at
- generated_document_id nullable
- created_at
- updated_at

### payment_proofs
Fields:
- id
- payment_id
- uploaded_document_id
- received_at
- reviewed_by_user_id nullable
- reviewed_at nullable
- review_status enum: pending, accepted, rejected
- review_comment nullable
- created_at
- updated_at

## Verification
### verification_assignments
Fields:
- id
- application_id
- level2_user_id
- level1_user_id
- assigned_at
- unassigned_at nullable
- active boolean
- comment nullable
- created_at
- updated_at

### verification_reviews
Fields:
- id
- application_id
- review_level enum: level1, level2
- reviewer_user_id
- assignment_id nullable
- outcome enum: pending, in_review, sent_back, approved, rejected, amended
- comment nullable
- editable_snapshot JSON nullable
- decision_at nullable
- created_at
- updated_at

### review_comments
Fields:
- id
- application_id
- review_id nullable
- comment_scope enum: internal, applicant_visible
- author_user_id
- body
- created_at
- updated_at

## Certificates
### certificate_templates
Fields:
- id
- name
- template_key
- html_template
- watermark_asset_path
- logo_asset_path
- signature_asset_path
- is_active
- created_at
- updated_at

### certificates
Fields:
- id
- application_id
- certificate_number
- verification_code
- verification_url_token
- template_id
- status enum: issued, reissued, revoked, technically_failed
- pdf_document_id nullable
- qr_payload
- certificate_hash
- issued_by_user_id
- issued_at
- reissued_from_certificate_id nullable
- revocation_comment nullable
- created_at
- updated_at

### certificate_status_histories
Fields:
- id
- certificate_id
- from_status nullable
- to_status
- changed_by_user_id
- comment nullable
- changed_at

## Notifications and logs
### notifications
Use Laravel database notifications plus a dedicated delivery table if needed for analytics.

### sms_logs
Fields:
- id
- user_id nullable
- application_id nullable
- phone_number
- message_type
- message_body
- provider
- status
- provider_reference nullable
- sent_at nullable
- created_at
- updated_at

### email_logs
Fields:
- id
- user_id nullable
- application_id nullable
- email
- subject
- template_key
- status
- sent_at nullable
- created_at
- updated_at

### audit_logs
Fields:
- id
- actor_user_id nullable
- actor_name_snapshot nullable
- event_type
- module
- entity_type nullable
- entity_id nullable
- action_name
- message
- before_state JSON nullable
- after_state JSON nullable
- metadata JSON nullable
- ip_address nullable
- user_agent nullable
- correlation_id nullable
- created_at

## Feedback
### service_feedback
Fields:
- id
- application_id
- applicant_user_id
- rating_value (1..5)
- rating_label nullable
- feedback_text nullable
- source
- source_step nullable
- metadata JSON nullable
- submitted_at
- created_at
- updated_at

## Settings
### system_settings
Fields:
- id
- setting_key
- setting_value JSON
- updated_by_user_id nullable
- updated_at

## Indexing strategy
Create indexes on:
- users.email
- users.phone_primary
- applications.application_number
- applications.current_status
- applications.applicant_user_id
- applications.is_foreign
- applications.country_id
- applications.awarding_body_id
- applications.assigned_level1_user_id
- applications.service_deadline_at
- qualifications.certificate_number
- invoices.invoice_number
- payments.reference_number
- receipts.receipt_number
- certificates.certificate_number
- certificates.verification_url_token
- audit_logs.event_type
- audit_logs.module
- audit_logs.entity_type, audit_logs.entity_id
- audit_logs.actor_user_id
- audit_logs.created_at

## Integrity rules
- application_number must be unique
- invoice_number must be unique
- receipt_number must be unique
- certificate_number must be unique
- verification_url_token must be unique
- only one active verification assignment per application
- only one current document version per document type per application where applicable
- payments cannot be confirmed without audit logging
- receipts cannot exist without a confirmed payment
- certificates cannot be issued unless the application is in an approval-ready state

## Recommended enums
Create PHP backed enums for:
- ApplicantType
- ApplicationStatus
- ServiceType
- QualificationType
- DocumentType
- ConsentType
- InvoiceStatus
- PaymentMethod
- PaymentStatus
- ReviewLevel
- ReviewOutcome
- CertificateStatus
- FeedbackScoreBand
- CommentScope

## Migration order
1. users and auth support
2. roles and permissions
3. applicant profiles and institution profiles
4. countries and awarding bodies
5. applications and status histories
6. qualifications and subject results
7. documents and consent forms
8. invoices, payments, receipts, payment proofs
9. verification assignments, reviews, comments
10. certificate templates, certificates, certificate status histories
11. notifications, email logs, sms logs
12. audit logs, settings, feedback

## Definition of Done
This phase is complete when:
- all entities required by the ZAQA requirements are modeled
- all relationships are explicit and implementable
- enums are defined for all controlled states
- uniqueness, integrity, and indexing rules are complete
- the schema supports local and foreign qualifications without ambiguity
- the schema supports applicant, finance, verification, certificates, admin, feedback, and audit requirements
- there are no placeholder tables, vague fields, or missing lifecycle histories
