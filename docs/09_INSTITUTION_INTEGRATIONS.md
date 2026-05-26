# Institution Integrations (Push + Pull)

## Overview

ZAQA supports two complementary integration patterns with Awarding Institutions:

1. **Push (institution → ZAQA)** — the primary and recommended mode.
2. **Pull (ZAQA → institution)** — used only during auto-verification for a specific qualification when internal records are insufficient and the institution has a configured lookup endpoint.

## Push (institution → ZAQA)

Institutions push learner achievement records into ZAQA using the Institution Integration API.

- API base: `/api/institution/v1`
- Swagger UI: `/docs/institution-api` (admin-permission protected)
- Auth: Bearer token (Sanctum) scoped to a single awarding institution and abilities.

### Token handling

- Tokens are shown only once at generation time.
- Tokens can be emailed immediately after generation (if a client contact email is configured).
- Tokens can be revoked or rotated by Super Admin.
- Plain tokens are never stored by ZAQA.

## Pull (ZAQA → institution)

Pull lookups are optional and only used when:

- Internal learner record matching does not find a safe match.
- The awarding institution has pull lookup enabled and configured in Admin → Integrations.

Pull lookups:

- Run asynchronously (queued).
- Are time-bounded (timeout and retry settings).
- Are logged in a separate sanitized log stream.
- Must never block the verification workflow indefinitely; failures fall back to normal Level 1 routing.

## Standard Pull Lookup Contract (Institution-hosted endpoint)

This contract defines the **institution-hosted** HTTP endpoint ZAQA will call when pull lookup is enabled for an awarding institution.

Important:
- This is **not** a ZAQA API endpoint and is **not** exposed in ZAQA Swagger.
- Each institution hosts its own lookup endpoint (e.g. `https://institution.example/api/zaqa/learner-lookup`).
- ZAQA will call the configured `lookup_url` asynchronously during auto-verification only when internal learner records do not produce a safe match.

### Request (ZAQA → institution)

Method: `POST` (recommended; configurable per institution)

Headers (recommended):
- `Content-Type: application/json`
- `Accept: application/json`
- `X-Request-Id: {correlation_id}`

Payload:
```json
{
  "correlation_id": "c7f1f4de-7d1d-4b75-9a35-bcbe6a7b3bbf",
  "qualification_reference": "ZAQA-QUAL-10",
  "student_id": "STU-001",
  "certificate_no": "CERT-123",
  "nrc_number": "111111/11/1",
  "passport_no": null,
  "first_name": "John",
  "last_name": "Doe",
  "other_names": null,
  "program_of_study": "Diploma in Testing",
  "year_awarded": 2024,
  "award_date": "2024-01-10",
  "awarding_institution_id": 15,
  "awarding_institution_name": "Example University"
}
```

Field notes:
- `correlation_id` — unique request ID for tracing; echo this in your logs/monitoring.
- `qualification_reference` — ZAQA reference for the qualification (stable per qualification).
- Identifier fields (`student_id`, `certificate_no`, `nrc_number`, `passport_no`) may be null/empty; ZAQA sends what it has.
- `program_of_study` is applicant-provided/known at time of lookup and may not match institution wording exactly.

### Response (institution → ZAQA)

The response must be a JSON object containing a required boolean `found`.

#### Found (success)
```json
{
  "found": true,
  "source_reference": "INST-REF-0001",
  "confidence_hint": 95,
  "record": {
    "student_id": "STU-001",
    "certificate_no": "CERT-123",
    "first_name": "John",
    "last_name": "Doe",
    "other_names": null,
    "gender": null,
    "nrc_number": "111111/11/1",
    "passport_no": null,
    "program_of_study": "Diploma in Testing",
    "year_awarded": 2024,
    "award_date": "2024-01-10"
  }
}
```

Rules for `found=true`:
- `source_reference` is required and must be a non-empty string (institution-side reference for audit/reconciliation).
- `confidence_hint` is optional; if provided it must be an integer `0..100`. ZAQA clamps values outside this range.
- `record` is required and must include:
  - `first_name`, `last_name`, `program_of_study`, `year_awarded`
  - at least one identifier: `student_id` or `certificate_no` or `nrc_number` or `passport_no`

#### Not found
```json
{
  "found": false,
  "source_reference": null,
  "confidence_hint": null,
  "record": null
}
```

#### Error (temporary/permanent failure)
```json
{
  "found": false,
  "error": {
    "code": "TEMPORARILY_UNAVAILABLE",
    "message": "System is down for maintenance"
  }
}
```

Error guidance:
- Use `TEMPORARILY_UNAVAILABLE` for outages/timeouts; ZAQA will treat this as a failed lookup and continue workflow safely.
- Do not include sensitive personal data in `message`.

### Timeouts, retries, and auth

- Institutions should respond within **15 seconds** (default), otherwise ZAQA treats the lookup as timed out.
- ZAQA may retry a small number of times per institution configuration.
- Institutions should protect the lookup endpoint with authentication (e.g. bearer token, basic auth, or mutual TLS) and rate-limit requests.

### Security guidance

- Treat `nrc_number`, `passport_no`, and names as sensitive.
- Always use HTTPS.
- Implement IP allowlisting and/or credential-based auth on the lookup endpoint.
- Log using `correlation_id` and avoid logging full NRC/passport values where possible.

## Admin management

- Admin → Integrations → Institution API Clients:
  - Create institution-scoped clients and issue tokens
  - Copy token once
  - Email token immediately (optional)
  - Rotate/revoke tokens
  - View institution API push logs
- Admin → Integrations → Institution Pull Integrations:
  - Enable/disable pull lookup per institution
  - Configure lookup URL, auth, timeout, retries
  - Test connection (non-sensitive)
  - View pull lookup logs
