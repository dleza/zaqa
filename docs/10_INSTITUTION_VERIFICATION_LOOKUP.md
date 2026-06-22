# Institution Verification Lookup

## Applicant portal (institution accounts)

Institution applicant users can open **Certificate / Verification Lookup** in the applicant sidebar.

| Item | Value |
|------|--------|
| Route (GET) | `/applicant/institution/verification-lookup` |
| Route (POST) | `/applicant/institution/verification-lookup` |
| Access | Institution `applicant_type` only |

### Search rules

- Provide **either** `application_reference` **or** `qualification_reference` (not both).
- Minimum three characters; exact or prefix match on indexed fields only.
- No name, NRC, institution name, or title search.

### Applicant-safe response fields

- Application and qualification references
- Holder name, qualification title, awarding institution, country, award date
- High-level status (`In Review`, `Returned for Correction`, `Approved`, `Rejected`, `Certificate Issued`, `Certificate Recalled`, `Not Found`)
- Certificate type/number/issue date when issued
- Public verification URL when a certificate exists

### Audit

Each portal search logs `institution_verification_lookup.performed`.

---

## Integrated awarding institution API

| Item | Value |
|------|--------|
| Endpoint | `GET /api/institution/v1/verification-records/lookup` |
| Auth | Bearer token (Sanctum) via Institution API client |
| Ability | `verification-records:lookup` |
| Rate limit | `institution-api` limiter (default 60/min per client token; see `institution_api.rate_limit_per_minute`) |

### Query parameters

| Parameter | Required | Notes |
|-----------|----------|--------|
| `application_reference` | One of two | e.g. `2026-000245` |
| `qualification_reference` | One of two | e.g. `2026-000245-01` |

### Authorization scope

API results are restricted to qualifications where `awarding_institution_id` matches the token’s awarding institution. Institution portal lookups are not restricted (validity lookup for any ZAQA reference).

### Response examples

**Found (qualification reference):**

```json
{
  "found": true,
  "searched_by": "qualification_reference",
  "application_reference": "2026-000245",
  "qualification_reference": "2026-000245-01",
  "status": "certificate_issued",
  "status_label": "Certificate Issued",
  "message": "A ZAQA verification certificate has been issued for this qualification.",
  "qualification": {
    "holder_name": "Martin Mwale",
    "title": "Bachelor of Science in Information Systems and Technology",
    "awarding_institution": "University of Lusaka",
    "country": "Zambia",
    "award_date": "2020-11-06"
  },
  "certificate": {
    "exists": true,
    "type": "verification",
    "type_label": "Verification Certificate",
    "number": "ZAQA-CVEQ-2026-000008",
    "issued_at": "2026-06-19",
    "revoked": false,
    "revoked_at": null,
    "public_verification_url": "https://verify.example.test/certificates/{token}"
  }
}
```

**Not found:**

```json
{
  "found": false,
  "message": "No ZAQA verification record was found for the supplied reference."
}
```

**Revoked certificate:**

```json
{
  "found": true,
  "status": "certificate_revoked",
  "status_label": "Certificate Recalled",
  "message": "The reference is recognized, but the certificate is no longer valid.",
  "certificate": {
    "exists": true,
    "revoked": true,
    "revoked_at": "2026-06-20",
    "public_verification_url": "https://verify.example.test/certificates/{token}"
  }
}
```

### Privacy / security

Never returned: internal revocation reason, officer names, assignment levels, audit trails, payment data, uploaded documents, NRC/passport.

### Audit

Each API lookup logs `integrated_verification_lookup.performed` with client and institution metadata.

---

## Shared service

`App\Domain\Verification\VerificationReferenceLookupService` powers both the portal and API. Reference filtering reuses `App\Support\Search\ReferenceSearch` (prefix `LIKE 'term%'`, no leading wildcards).

Public QR verification (`GET /certificates/{token}`) is unchanged.
