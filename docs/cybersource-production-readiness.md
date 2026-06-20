# CyberSource Production Readiness Checklist

This checklist records the Phase 9 production-readiness review for the card-only CyberSource REST integration.

Scope boundaries:

- CyberSource applies only to `PaymentMethod::Card`.
- Mobile money, deposit/bank proof, finance review, invoice generation, receipt generation, and application submission remain on their existing paths.
- No database migrations were added in this phase.
- No application code changes were required by this review.

## Review Summary

Security-sensitive terms searched across the repository:

- `transient_token_jwt`
- `capture_context`
- `card_number`
- `cvv`
- `securityCode`
- `expirationMonth`
- `expirationYear`

Findings:

- No raw PAN or CVV field is posted to Laravel by the CyberSource card UI.
- No raw PAN, CVV, or expiry is stored in `payments.raw_payload`.
- The transient token is sent once to `POST /applicant/payments/{payment}/card/confirm` and is not persisted.
- Capture context is returned to the browser for Microform loading and is not stored by Laravel.
- CyberSource stored payloads go through `CyberSourcePayloadSanitizer`.
- The card confirmation endpoint applies results through `PaymentService::applyGatewayVerificationResult()`, preserving the existing status, invoice, receipt, and auto-submission path.

## Environment Variables

Configure these values in the deployment environment. Do not commit real values.

```dotenv
CYBERSOURCE_ENABLED=false
CYBERSOURCE_RUN_ENVIRONMENT=apitest.cybersource.com
CYBERSOURCE_MERCHANT_ID=
CYBERSOURCE_KEY_ID=
CYBERSOURCE_SECRET_KEY=
CYBERSOURCE_AUTH_TYPE=JWT
CYBERSOURCE_JWT_KEY_TYPE=SHARED_SECRET
CYBERSOURCE_TARGET_ORIGINS=
CYBERSOURCE_ALLOWED_CARD_NETWORKS=VISA,MASTERCARD
CYBERSOURCE_ALLOWED_PAYMENT_TYPES=CARD
CYBERSOURCE_CAPTURE=true
CYBERSOURCE_TIMEOUT=30
CYBERSOURCE_LOGGING_ENABLED=false
```

Environment notes:

- `CYBERSOURCE_ENABLED` defaults to `false`. Enable it only after sandbox signoff.
- `CYBERSOURCE_RUN_ENVIRONMENT=apitest.cybersource.com` is the sandbox value.
- `CYBERSOURCE_RUN_ENVIRONMENT=api.cybersource.com` is the production value documented by the official CyberSource PHP SDK README.
- `CYBERSOURCE_AUTH_TYPE` must remain `JWT`.
- `CYBERSOURCE_JWT_KEY_TYPE=SHARED_SECRET` uses `CYBERSOURCE_KEY_ID` and `CYBERSOURCE_SECRET_KEY`.
- `CYBERSOURCE_TARGET_ORIGINS` must be a comma-separated list of exact HTTPS origins allowed to load Microform, for example `https://portal.example.org`.
- `CYBERSOURCE_LOGGING_ENABLED` must remain `false` in production unless a separate security review approves a safe logging design.

## Sandbox Setup Checklist

Before enabling card payment in any shared environment:

- Create or confirm the CyberSource sandbox merchant account.
- Generate JWT shared-secret REST credentials in the CyberSource business center.
- Set `CYBERSOURCE_ENABLED=true` only in the sandbox/test environment.
- Set `CYBERSOURCE_RUN_ENVIRONMENT=apitest.cybersource.com`.
- Set `CYBERSOURCE_TARGET_ORIGINS` to the exact test portal origin.
- Confirm `CYBERSOURCE_ALLOWED_CARD_NETWORKS` matches the merchant configuration.
- Confirm `CYBERSOURCE_CAPTURE=true` is the intended behavior for application verification fees.
- Confirm `CYBERSOURCE_LOGGING_ENABLED=false`.
- Clear and recache Laravel config after setting environment values.
- Run the automated test suite listed in this document.
- Complete the manual QA checklist with CyberSource sandbox test cards.

## Production Setup Checklist

Before enabling card payment in production:

- Obtain production CyberSource REST JWT shared-secret credentials.
- Confirm the production merchant ID, key ID, and secret key are stored only in the secret manager or server environment.
- Set `CYBERSOURCE_RUN_ENVIRONMENT=api.cybersource.com`.
- Set `CYBERSOURCE_TARGET_ORIGINS` to production HTTPS origins only.
- Confirm the production domain is served over HTTPS with a valid certificate.
- Confirm CyberSource account rules, allowed networks, capture behavior, fraud rules, currency, and settlement account are approved by finance.
- Confirm support staff have a reconciliation process using payment `provider_reference`, `provider_transaction_id`, and sanitized `raw_payload` summary.
- Confirm rollback instructions are accepted by operations and finance.
- Confirm 3DS/payer authentication requirements with the acquiring bank and CyberSource before broad rollout.
- Keep `CYBERSOURCE_LOGGING_ENABLED=false`.

## Security Rules

Frontend:

- Use only CyberSource Microform fields for card number and CVV.
- Do not add plain `<input>` fields for card number or CVV.
- Do not bind card number or CVV to Vue refs, models, stores, or logs.
- Do not log capture context or transient token values.
- Do not persist the transient token in local storage, session storage, cookies, or application state.
- Expiry month and year are passed to Microform token creation only; they are not posted to Laravel.

Backend:

- Accept only `transient_token_jwt` on the CyberSource confirm endpoint.
- Never accept PAN, CVV, or raw expiry fields in Laravel request validation.
- Do not store transient tokens in `payments.raw_payload`, `payment_webhook_logs.payload`, audit logs, or exception logs.
- Store only sanitized CyberSource response summaries.
- Do not add CyberSource request bodies to logs while they contain `tokenInformation.transientTokenJwt`.
- Do not log full `ApiException` payloads. Persist only sanitized status, reason, message, request ID, HTTP status, processor response code, approval code, and reconciliation ID.

## PCI Notes

The current design uses CyberSource Microform/Flex to keep PAN and CVV inside CyberSource-hosted fields. Laravel receives only a CyberSource transient token and payment ID. This reduces PCI scope compared with direct card handling, but it does not remove all PCI obligations.

Production requirements:

- Serve every payment page over HTTPS.
- Maintain secure headers and session cookie settings.
- Keep dependency and vulnerability scanning active for frontend and backend packages.
- Review CSP before production launch so CyberSource scripts and frames are explicitly allowed without opening unnecessary script origins.
- Confirm the exact PCI Self-Assessment Questionnaire scope with the organization's QSA or compliance owner.
- Keep access to CyberSource credentials restricted and audited.

## Logging Review

Current state:

- `config/cybersource.php` defaults `CYBERSOURCE_LOGGING_ENABLED=false`.
- The CyberSource SDK `LogConfiguration` default has logging disabled.
- The application does not enable CyberSource SDK debug logging.
- `CyberSourcePaymentService` catches `ApiException` and builds a sanitized verification result instead of dumping full request payloads.
- `CyberSourcePayloadSanitizer` removes transient tokens, raw card keys, expiry keys, tokenized card structures, and card-like scalar values.

Operational rule:

- If SDK logging is ever enabled for a support incident, it must be done only in a restricted non-production environment or behind a separate approved redaction implementation. Production must not log CyberSource request or response bodies that could contain payment credentials or tokens.

## Backend Controls

Confirmed controls:

- `ConfirmCyberSourceCardPaymentRequest::authorize()` validates applicant ownership through the related application policy.
- `ConfirmCyberSourceCardPaymentRequest` requires `transient_token_jwt` and rejects non-card or non-`cybersource` payments.
- Already confirmed payments are rejected before a gateway charge.
- Pending confirmation payments are rejected before a duplicate gateway charge.
- Already paid invoices are rejected before a gateway charge.
- `ApplicantPaymentController::assertCyberSourceCardPaymentCanBeCharged()` provides a second duplicate guard before calling the gateway.
- Failed, rejected, expired, and pending CyberSource statuses do not call `markApplicationPaid()`.
- Only `confirmed` gateway status triggers paid invoice conversion and application auto-submission through `PaymentService`.
- `CyberSourceStatusMapper` returns `failed` for unknown responses and never defaults to `confirmed`.

## Rollback Plan

Rollback must affect card payments only:

- Set `CYBERSOURCE_ENABLED=false`.
- Clear and recache config.
- Confirm the card form returns the card-unavailable message.
- Keep mobile money and deposit/bank proof options available.
- Do not change `PaymentService` status application behavior.
- Do not delete or mutate existing CyberSource payment records.
- If code rollback is required, revert the CyberSource card UI/endpoints/gateway commits as a set on the feature branch or release branch.
- Notify finance that new card attempts are disabled and reconciliation should use existing payment records only.

## Manual QA Checklist

Card success:

- Applicant completes verification requirements and reaches the payment step.
- Card tab requests capture context successfully.
- CyberSource-hosted card number and CVV fields render.
- Applicant enters sandbox card details.
- Microform returns a transient token.
- Laravel confirms payment through the CyberSource confirm endpoint.
- Payment status becomes `confirmed`.
- Invoice becomes paid.
- Receipt behavior remains unchanged.
- Application submission behavior remains unchanged.

Card failure:

- Use a sandbox decline test card.
- Confirm status becomes `failed` or `rejected`, not `confirmed`.
- Confirm invoice remains unpaid.
- Confirm application is not auto-submitted.
- Confirm the applicant sees a clear failure message and can retry or choose another payment method.

Duplicate protection:

- Submit the same confirmed payment again.
- Confirm the gateway is not charged again.
- Confirm the endpoint returns a validation error for the already confirmed payment or already paid invoice.

Configuration failure:

- Set `CYBERSOURCE_ENABLED=false`.
- Confirm only card payment is unavailable.
- Confirm mobile money still initiates.
- Confirm deposit/bank proof upload still works.

Non-card regression:

- Initiate mobile money payment and confirm polling/status behavior.
- Upload bank/deposit proof and confirm finance review workflow.
- Confirm finance approval/rejection paths still own manual proof decisions.

## Known Limitations

- 3DS/payer authentication is not implemented yet. If the acquiring bank, CyberSource account, card schemes, or local regulations require payer authentication, production rollout must wait for a separate 3DS implementation and review.
- CyberSource webhook/reconciliation endpoints are not implemented. Current confirmation is synchronous through the applicant card-confirm endpoint.
- No dedicated CyberSource metadata columns exist yet. Sanitized gateway metadata is stored in `payments.raw_payload`.
- The current UI handles disabled or misconfigured CyberSource by showing a card-unavailable error after capture-context failure. Fully hiding the card tab based on server-provided availability would require a separate UI availability prop.
- SDK debug logging is intentionally not enabled. A future support logging feature must include explicit redaction and access controls.

## Automated Test Results

The following checks passed during Phase 9:

```bash
php artisan test tests/Unit/CyberSourceStatusMapperTest.php
php artisan test tests/Unit/CyberSourcePayloadSanitizerTest.php
php artisan test tests/Unit/CyberSourcePaymentServiceTest.php
php artisan test tests/Unit/CyberSourceCaptureContextServiceTest.php
php artisan test tests/Unit/CyberSourceClientFactoryTest.php
php artisan test tests/Unit/PaymentGatewayManagerTest.php
php artisan test tests/Feature/PaymentServiceProviderRoutingTest.php
php artisan test tests/Feature/CyberSourceCardPaymentEndpointTest.php
php artisan test tests/Feature/ApplicantMobileMoneyPaymentFlowTest.php
php artisan test tests/Feature/FinancePaymentOperationsTest.php --filter=uploading_proof
npm run build
```

Feature tests that use the database should be run sequentially against the shared MySQL test database to avoid migration collisions.

## Reference

- Official CyberSource PHP REST SDK README: https://github.com/CyberSource/cybersource-rest-client-php
