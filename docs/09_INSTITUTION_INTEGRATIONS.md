# Institution Integrations (Push + Pull)

## Overview

ZAQA supports two complementary integration patterns with Awarding Institutions:

1. **Push (institution → ZAQA)** — the primary and recommended mode for populating learner achievement records.
2. **Pull (ZAQA → institution)** — optional configuration for **manual preview**, connectivity testing, and **future/manual** lookup workflows. Pull integrations are **not** called automatically during auto-verification.

## Push (institution → ZAQA)

Institutions push learner achievement records into ZAQA using the Institution Integration API. **Push submissions are staged for ZAQA review** and are not inserted directly into the trusted `learner_records` catalog until an authorized user approves them. Pending submissions are **not** used by auto-verification.

- API base: `/api/institution/v1`
- Swagger UI: `/docs/institution-api` (public, feature-flag protected)
- Auth: Bearer token (Sanctum) scoped to a single awarding institution and abilities.

### Verification record lookup (ZAQA-hosted)

Integrated institutions can look up ZAQA verification/certificate status by official reference:

| Item | Value |
|------|--------|
| Endpoint | `GET /api/institution/v1/verification-records/lookup` |
| Ability | `verification-records:lookup` |
| Query | Exactly one of `application_reference` or `qualification_reference` (min 3 chars; prefix match) |
| Scope | Results limited to the token’s awarding institution |

Full request/response schemas and examples are in the Institution API Swagger spec (`resources/openapi/institution-api.yaml`, served at `/docs/institution-api/openapi.yaml`). See also `docs/10_INSTITUTION_VERIFICATION_LOOKUP.md`.

### Token handling

- Tokens are shown only once at generation time.
- Tokens can be emailed immediately after generation (if a client contact email is configured).
- Tokens can be revoked or rotated by Super Admin.
- Plain tokens are never stored by ZAQA.

## Pull (ZAQA → institution)

Pull lookup integrations remain available for configuration, testing, and future/manual use. They are **not** invoked automatically when a qualification is auto-verified.

Auto-verification checks **only** ZAQA learner achievement records (`learner_records`). When no safe internal match is found, the qualification routes directly to Level 1 assignment (or awaits Level 2 assignment).

Pull lookups can still be used for:

- Admin **pull lookup preview** on an awarding institution profile
- **Test connection** from integration admin screens
- Direct calls to `InstitutionPullLookupService` (future manual workflows)

When used (manually or in future workflows), pull lookups:

- Stage found records as pending submissions (they do not write directly to `learner_records`).
- Run asynchronously when dispatched as queued jobs.
- Are time-bounded (timeout and retry settings).
- Are logged in a separate sanitized log stream (`institution_pull_lookup_logs`).

Institutions that want auto-verification to match their learners must have records approved into `learner_records` via push API (after ZAQA review), Excel import, or other approved ingestion channels.

## Configuration model

ZAQA and each institution system configure opposite sides of the same integration.

| System | Source of truth | What is stored |
|--------|-----------------|----------------|
| **Institution (e.g. UNZA SIS)** | Institution `.env` | Token and flags that protect the institution-hosted lookup endpoint (`ZAQA_LOOKUP_ENABLED`, `ZAQA_LOOKUP_TOKEN`, optional IP allowlist, rate limit) |
| **ZAQA portal** | Admin UI + `institution_integrations` table | Per awarding institution: lookup URL, driver, auth type, encrypted bearer token, timeout, retries, active/pull toggles |

Important:

- ZAQA does **not** use `.env` variables for production institution lookup URLs or tokens.
- ZAQA may eventually manage hundreds of institution integrations; each is a row in `institution_integrations`, configured by Super Admins.
- The shared bearer token must match on both sides: institution `.env` (`ZAQA_LOOKUP_TOKEN`) and ZAQA admin UI (encrypted `credentials.bearer_token` for that institution).

### Production setup (example: University of Zambia)

1. **On UNZA SIS** — set `.env`:
   ```env
   ZAQA_LOOKUP_ENABLED=true
   ZAQA_LOOKUP_TOKEN=<shared-secret>
   ZAQA_ALLOWED_IPS=
   ZAQA_LOOKUP_RATE_LIMIT=60
   ```
2. **On ZAQA** — Admin → Integrations → Institution Pull Integrations → University of Zambia:
   - Enable **Active** and **Supports pull lookup**
   - Lookup URL: institution endpoint (e.g. `https://sis.unza.ac.zm/api/zaqa/v1/learner-lookup`)
   - Driver: `generic_rest`
   - Request method: `POST`
   - Auth type: `bearer_token`
   - Bearer token: same value as SIS `ZAQA_LOOKUP_TOKEN`
   - Timeout / retries as required
   - Use **Test connection** or **Pull lookup preview** on the awarding institution profile to verify connectivity

**Note:** Configuring UNZA (or any institution) pull lookup does **not** cause auto-verification to call the institution system. Populate `learner_records` via push API or import if auto-verification should match those learners.

### Local development convenience seeder

`UnzaInstitutionIntegrationSeeder` can pre-create the University of Zambia integration when optional **local-only** env vars are set:

```env
# LOCAL DEVELOPMENT ONLY — not used in production
UNZA_SIS_LOOKUP_URL=http://127.0.0.1:8001/api/zaqa/v1/learner-lookup
UNZA_SIS_LOOKUP_TOKEN=<same-as-sis-ZAQA_LOOKUP_TOKEN>
```

If `UNZA_SIS_LOOKUP_URL` is absent, the seeder no-ops. Production deployments should leave these unset and configure integrations via the admin UI.

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
  - Select awarding institution (one integration row per institution)
  - Enable/disable pull lookup per institution (**Active**, **Supports pull lookup**)
  - Configure lookup URL, driver (`generic_rest`), request method (`POST`), auth type (`bearer_token`), encrypted bearer token, timeout, retries
  - Test connection (POST probe for POST-configured integrations)
  - View pull lookup logs
- Admin → Integrations → Institution API Clients → **client detail page**:
  - Configure pull lookup integration via modal (same `institution_integrations` record)
  - Generate pull lookup bearer token (shown once; share with institution for `ZAQA_LOOKUP_TOKEN`)
  - Test connection and email token to client contact

The admin UI is the **only** supported way to manage institution pull integrations in production. Credentials are encrypted at rest; plain tokens are never logged by ZAQA.
