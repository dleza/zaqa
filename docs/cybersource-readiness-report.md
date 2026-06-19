# CyberSource Gateway Readiness Assessment

## Scope

This assessment covers preparation for a future CyberSource REST card gateway only. It does not implement CyberSource, install packages, add routes, add controllers, or create migrations.

The required behavior boundary is:

- Card payments may change from the current dummy/test gateway to a future `cybersource` gateway.
- Mobile money must continue to use the existing cGrate implementation.
- Deposit/bank transfer proof upload and finance review must continue unchanged.
- Invoice generation, receipt generation, and application submission must continue through the existing services.

## 1. Payment Provider Routing

### Payment methods

`App\Enums\PaymentMethod` defines four payment methods:

- `card`
- `mobile_money`
- `bank_deposit`
- `bank_transfer`

Source: `app/Enums/PaymentMethod.php`.

### Card -> `test`

Card payments are currently routed to provider `test` by default through `PaymentService::createDraftPayment()`.

Relevant code:

- `PaymentService::createDraftPayment()` creates new online payment drafts.
- It sets provider with:

```php
'provider' => $method === PaymentMethod::MobileMoney ? 'cgrate' : 'test',
```

Impact:

- `PaymentMethod::Card` is not `PaymentMethod::MobileMoney`, so it receives provider `test`.
- Any other method that reaches this generic draft path would also receive `test`.
- Existing card drafts are not corrected from `test` to another card provider today.

Source: `app/Domain/Payments/PaymentService.php:104-163`.

### Mobile money -> `cgrate`

Mobile money is routed to cGrate in three places:

- Existing mobile money drafts are corrected to `cgrate` if needed:

```php
if ($method === PaymentMethod::MobileMoney && $existingDraft->provider !== 'cgrate') {
    $existingDraft->forceFill(['provider' => 'cgrate'])->save();
}
```

- New mobile money drafts receive provider `cgrate` through the same ternary assignment used above.
- `PaymentService::initiateOnline()` routes `PaymentMethod::MobileMoney` to `initiateCGrateMobileMoney()`.

The cGrate initiation path then:

- creates or reuses a `payment_attempts` row with `gateway = cgrate`;
- sets `payments.provider = cgrate`;
- sets `payments.status = pending_confirmation`;
- dispatches `DispatchMobileMoneyPaymentPromptJob` or polling jobs.

Sources:

- `app/Domain/Payments/PaymentService.php:143-157`
- `app/Domain/Payments/PaymentService.php:281-317`
- `app/Domain/Payments/PaymentService.php:388-549`

### Deposit/bank transfer -> manual proof flow

Deposit and bank transfer do not use an online `PaymentGateway` implementation.

Manual proof upload starts from:

- `POST /applicant/applications/{application}/payment/upload-proof`
- `POST /applicant/payments/{payment}/upload-proof`

Both call `PaymentService::paymentForManualProofUpload()` and `PaymentService::attachProof()`.

`paymentForManualProofUpload()`:

- reuses an existing non-confirmed bank deposit/bank transfer payment if one exists;
- otherwise creates a `PaymentMethod::BankTransfer` draft;
- currently sets provider `test`;
- does not call `PaymentGatewayManager`;
- does not call `PaymentGateway::initiate()`.

`attachProof()`:

- allows only `PaymentMethod::BankDeposit` and `PaymentMethod::BankTransfer`;
- sets status to `awaiting_finance_review`;
- records `awaiting_finance_review_at`;
- dispatches the existing `PaymentProofSubmitted` event.

Sources:

- `routes/web.php:218,223`
- `app/Http/Controllers/Applicant/ApplicantPaymentController.php:168-198`
- `app/Domain/Payments/PaymentService.php:201-275`
- `app/Domain/Payments/PaymentService.php:639-700`

### Provider-name references

Payment-runtime provider/gateway names currently referenced:

- `test`
  - default provider in `payments.provider` migration: `database/migrations/2026_04_23_000011_create_payments_table.php:22`
  - non-mobile draft provider in `PaymentService::createDraftPayment()`: `app/Domain/Payments/PaymentService.php:157`
  - manual proof draft provider in `PaymentService::paymentForManualProofUpload()`: `app/Domain/Payments/PaymentService.php:234`
  - default provider fallback in `PaymentGatewayManager`: `app/Domain/Payments/PaymentGatewayManager.php:12,15,17`
  - provider key in `TestPaymentGateway`: `app/Domain/Payments/TestPaymentGateway.php:11-14`
  - test redirect route name: `routes/web.php:947-948`
- `cgrate`
  - mobile money existing-draft correction: `app/Domain/Payments/PaymentService.php:143-144`
  - mobile money new draft provider: `app/Domain/Payments/PaymentService.php:157`
  - mobile money attempt queries and writes: `app/Domain/Payments/PaymentService.php:419,436,457,481,565`
  - registered gateway in `PaymentGatewayManager`: `app/Domain/Payments/PaymentGatewayManager.php:16`
  - provider key and response payload wrapper in `CGratePaymentGateway`: `app/Domain/Payments/Gateways/CGrate/CGratePaymentGateway.php:18-20,49-74`
  - mobile money prompt job gateway lookup: `app/Jobs/Payments/DispatchMobileMoneyPaymentPromptJob.php:58`
  - cGrate polling attempt query: `app/Domain/Payments/CGratePollingService.php:24-35`
  - `payment_attempts.gateway` default: `database/migrations/2026_05_21_000000_create_payment_attempts_table.php:18`
  - applicant payment polling branch: `app/Http/Controllers/Applicant/ApplicantPaymentController.php:283`
  - Inertia config prop names in applicant edit pages: `app/Http/Controllers/Applicant/ApplicantApplicationController.php:620-624`, `app/Http/Controllers/Applicant/ApplicantInstitutionalMultipleApplicationController.php:115-119`
- `manual`
  - not a runtime payment provider in current application code.
  - appears in tests and unrelated accreditation/learner-record source fields.
  - one test creates a bank deposit payment with provider `manual`; application runtime creates manual proof payments with provider `test`.

Payment metadata fields displayed or searched by finance/applicant screens:

- `provider`
- `provider_reference`
- `provider_transaction_id`

These are used for admin finance views, applicant payment views, finance search, webhook logs, audit metadata, and receipts. The future CyberSource gateway should populate these consistently and avoid breaking existing reads.

## 2. PaymentGatewayManager Analysis

### Registered gateways

`PaymentGatewayManager::gateway(string $provider): PaymentGateway` currently registers:

- `test` -> `TestPaymentGateway`
- `cgrate` -> `CGratePaymentGateway`

Source: `app/Domain/Payments/PaymentGatewayManager.php:8-19`.

### Fallback behavior

Current behavior:

```php
$provider = $provider !== '' ? $provider : 'test';

return match ($provider) {
    'test' => App::make(TestPaymentGateway::class),
    'cgrate' => App::make(CGratePaymentGateway::class),
    default => App::make(TestPaymentGateway::class),
};
```

Implications:

- Blank provider resolves to `test`.
- Unknown provider resolves to `test`.
- This is convenient for the current dummy flow but unsafe for production card payments because a misspelled or misconfigured CyberSource provider could silently become a dummy successful payment path.

### Required changes for future `cybersource`

Future implementation should:

- add an explicit `cybersource` match arm;
- bind or resolve `CyberSourcePaymentGateway`;
- stop falling back to `TestPaymentGateway` for unknown providers in production code;
- preserve `test` only for intentional local/test scenarios if still needed;
- leave `cgrate` mapping untouched.

Recommended future shape:

```php
return match ($provider) {
    'test' => App::make(TestPaymentGateway::class),
    'cgrate' => App::make(CGratePaymentGateway::class),
    'cybersource' => App::make(CyberSourcePaymentGateway::class),
    default => throw new UnsupportedPaymentProviderException($provider),
};
```

### Existing gateway impact

Registering `cybersource` should not affect existing gateways if:

- the `cgrate` match arm remains unchanged;
- mobile money continues to set provider `cgrate`;
- manual proof upload continues to bypass `PaymentGatewayManager`;
- unknown-provider fallback removal is tested against any existing records that rely on blank/unknown provider values.

The only intentional provider routing change should be:

- `PaymentMethod::Card` -> `cybersource`

## 3. Card Payment UI Analysis

### Components involved

Card payment UI exists in two payment surfaces:

1. Single application edit page:
   - `resources/js/Pages/Applicant/Applications/Edit.vue`
   - Card initiation function: `initiateCardPayment()`
   - Card tab and button: payment method tab panel

2. Reusable applicant payment panel:
   - `resources/js/Components/Applicant/ApplicantApplicationPaymentPanel.vue`
   - Used by the institutional/multiple application edit page:
     - `resources/js/Pages/Applicant/Applications/Multiple/Edit.vue`

Sources:

- `resources/js/Pages/Applicant/Applications/Edit.vue:1040-1050`
- `resources/js/Pages/Applicant/Applications/Edit.vue:2409-2463`
- `resources/js/Components/Applicant/ApplicantApplicationPaymentPanel.vue:138-142`
- `resources/js/Components/Applicant/ApplicantApplicationPaymentPanel.vue:307-349`
- `resources/js/Pages/Applicant/Applications/Multiple/Edit.vue:469-477`

### Current redirect-based flow entry points

The redirect-based flow begins in Vue:

```ts
cardInitiateForm.post(`/applicant/applications/${props.application.id}/payment/initiate-card`, {
  preserveScroll: true,
})
```

On the single application page, the UI text explicitly says:

> You’ll be redirected to the payment gateway and returned here after the attempt.

The controller then expects `PaymentService::initiateOnline()` to return `redirect_url`.

Sources:

- `resources/js/Pages/Applicant/Applications/Edit.vue:1040-1050`
- `resources/js/Components/Applicant/ApplicantApplicationPaymentPanel.vue:138-142`
- `app/Http/Controllers/Applicant/ApplicantPaymentController.php:68-84`

### Recommended Microform mount points

Microform should be mounted inside the existing card tab content, replacing the redirect-only message and button.

Recommended mount locations:

- Single application:
  - inside `activePaymentTab === 'card'` block in `Edit.vue`;
  - currently starts at `resources/js/Pages/Applicant/Applications/Edit.vue:2446`.
- Reusable/multiple payment panel:
  - inside `activePaymentTab === 'card'` block in `ApplicantApplicationPaymentPanel.vue`;
  - currently starts at `resources/js/Components/Applicant/ApplicantApplicationPaymentPanel.vue:339`.

Implementation guidance for future work:

- Keep the existing tab structure.
- Do not alter bank transfer or mobile money tab logic.
- Add isolated card-only state for capture context loading, Microform mount status, token submission, and card errors.
- Use CyberSource-hosted fields for PAN/CVV. Do not bind raw card number or CVV to Vue state.
- Prefer extracting a reusable `CyberSourceCardPaymentForm.vue` only if both payment surfaces need identical behavior.

## 4. CyberSource Implementation Inventory

No implementation should be added in this phase. The following inventory describes future work only.

### Required new classes

Recommended classes:

- `app/Domain/Payments/Gateways/CyberSource/CyberSourcePaymentGateway.php`
  - Implements `PaymentGateway`.
  - Owns provider key `cybersource`.
  - Initiates internal CyberSource card attempts.
  - Verifies/query-normalizes CyberSource results if needed.
- `app/Domain/Payments/Gateways/CyberSource/CyberSourceClientFactory.php`
  - Builds official CyberSource SDK clients from Laravel config.
  - Applies JWT authentication settings.
- `app/Domain/Payments/Gateways/CyberSource/CyberSourceCaptureContextService.php`
  - Creates Microform/Flex capture contexts.
  - Uses server-side configured target origins and allowed card networks.
- `app/Domain/Payments/Gateways/CyberSource/CyberSourcePaymentService.php`
  - Submits transient tokens to CyberSource REST Payments API.
  - Builds order, amount, billing, and token payloads.
- `app/Domain/Payments/Gateways/CyberSource/CyberSourceStatusMapper.php`
  - Maps CyberSource response status/reason fields to normalized statuses consumed by `PaymentService`.
- `app/Domain/Payments/Gateways/CyberSource/CyberSourcePayloadSanitizer.php`
  - Removes transient tokens, PAN, CVV, expiry, and sensitive payload fragments before logging/storing.
- `app/Domain/Payments/Exceptions/UnsupportedPaymentProviderException.php`
  - Optional but recommended if `PaymentGatewayManager` stops falling back to `test`.
- `app/Http/Requests/Applicant/ConfirmCyberSourceCardPaymentRequest.php`
  - Validates payment ID/token submission without accepting raw card data.

Optional future classes:

- `app/Http/Controllers/Webhooks/CyberSourceWebhookController.php`
  - Only if CyberSource webhook/reconciliation events are enabled.
- `resources/js/Components/Applicant/CyberSourceCardPaymentForm.vue`
  - Shared Microform UI for both applicant payment surfaces.

### Required new routes

Recommended future routes:

- `POST /applicant/applications/{application}/payment/card/capture-context`
  - Creates/reuses a card payment draft and returns capture context plus payment ID.
- `POST /applicant/payments/{payment}/card/capture-context`
  - Payment-scoped equivalent if existing payment-scoped card flows must remain supported.
- `POST /applicant/payments/{payment}/card/confirm`
  - Receives `transient_token_jwt`, calls CyberSource REST Payments API, applies normalized status.
- Optional: `GET /applicant/payments/{payment}/card/return`
  - Only if future 3DS/payer-auth return handling requires it.
- Optional: `POST /webhooks/cybersource/payment`
  - Only if webhooks are enabled.

Existing routes that should remain unchanged for non-card methods:

- `POST /applicant/applications/{application}/payment/initiate-mobile-money`
- `POST /applicant/payments/{payment}/initiate-mobile-money`
- `GET /applicant/payments/attempts/{attempt}/status`
- `POST /applicant/payments/{payment}/mobile-money/status`
- `POST /applicant/applications/{application}/payment/upload-proof`
- `POST /applicant/payments/{payment}/upload-proof`
- `POST /webhooks/cgrate/payment`

Sources: `routes/web.php:214-224,947-950`.

### Required configuration files

Recommended future config file:

- `config/cybersource.php`

Recommended optional updates:

- `.env.example` for non-secret environment variable names.
- A service provider binding only if constructor injection/autowiring is insufficient.

### Required environment variables

Recommended future variables:

```env
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

If webhooks are introduced:

```env
CYBERSOURCE_WEBHOOK_SECRET=
```

## 5. Database Review

### Current `payments` support

The current `payments` table can support a minimal CyberSource integration.

Existing useful fields:

- `method`
- `status`
- `currency`
- `amount_cents`
- `provider`
- `provider_reference`
- `provider_transaction_id`
- `initiated_at`
- `confirmed_at`
- `failed_at`
- `rejected_at`
- `expires_at`
- `last_status_at`
- `raw_payload`

Source: `database/migrations/2026_04_23_000011_create_payments_table.php:11-47`.

Recommended minimal mapping:

- `provider = cybersource`
- `provider_reference = internal client reference or CyberSource payment ID`
- `provider_transaction_id = CyberSource ID or processor transaction ID`
- `raw_payload = sanitized CyberSource response summary`
- `confirmed_at`, `failed_at`, `rejected_at`, `expires_at` continue to be managed by `PaymentService`.

### Current `payment_attempts` support

`payment_attempts` is generic in name but currently shaped around cGrate:

- default `gateway = cgrate`;
- default `method = mobile_money`;
- `mobile_number` is required and indexed;
- has useful generic fields such as `payment_reference`, `gateway_status`, `response_code`, `response_message`, `request_payload`, `response_payload`, and `metadata`.

Source: `database/migrations/2026_05_21_000000_create_payment_attempts_table.php:11-55`.

CyberSource can initially use `payments` only. If attempt-level tracking is desired, `payment_attempts.mobile_number` must become nullable or a card-specific attempt table/columns must be introduced.

### Recommended additional fields

No migration should be created in this phase.

Recommended future nullable fields on `payments`:

- `gateway_status`
- `request_id`
- `reconciliation_id`
- `authorization_code`
- `processor_response_code`
- `failure_reason`
- `card_type`
- `card_last_four`

Model impact if these fields are added later:

- update `app/Models/Payment.php` `$fillable`;
- add casts for any timestamps/arrays as needed;
- keep all fields nullable to protect existing records.

## 6. Risk Assessment

### Mobile money risk

Risk areas:

- `PaymentService::createDraftPayment()` currently has shared provider assignment for mobile money and all non-mobile methods.
- Any card provider change in that method could accidentally alter mobile money if implemented as a broad refactor.
- `PaymentGatewayManager` fallback changes could affect cGrate if the `cgrate` match arm is edited incorrectly.
- `DispatchMobileMoneyPaymentPromptJob` explicitly resolves `gateway('cgrate')`.
- `CGratePollingService` queries `payment_attempts.gateway = cgrate`.

Controls:

- Preserve `PaymentMethod::MobileMoney -> cgrate`.
- Do not modify `initiateCGrateMobileMoney()`.
- Do not modify cGrate jobs, polling, callback, config, or route behavior.
- Add regression tests for mobile money initiation, pending attempt reuse, polling/callback, and successful confirmation.

### Deposit/bank transfer risk

Risk areas:

- `paymentForManualProofUpload()` currently creates manual proof payments with provider `test`.
- Deposit/bank transfer does not use `PaymentGatewayManager`.
- `attachProof()` is method-gated and sets status `awaiting_finance_review`.
- `assertApplicationNotLockedByPendingProofReview()` blocks other payment activity while proof is awaiting review.

Controls:

- Do not route bank/deposit through CyberSource.
- Do not change proof upload routes or request classes.
- Do not change finance review status transitions.
- Add regression tests for proof upload, awaiting finance review, approval, rejection, and locked pending-review behavior.

### Invoice generation risk

Risk areas:

- `createDraftPayment()` and `paymentForManualProofUpload()` both call `InvoiceService::ensureInvoice()`.
- `markApplicationPaid()` converts quotations/invoices through `QuotationConversionService::convertToInvoiceOnPayment()`.

Controls:

- Do not change invoice preparation.
- Keep CyberSource confirmation routed through `PaymentService::applyGatewayVerificationResult()` so existing invoice conversion logic remains the single path.
- Add regression tests confirming paid CyberSource card payments still mark/convert invoices exactly like the dummy confirmed card path.

Sources:

- `app/Domain/Payments/PaymentService.php:104-163`
- `app/Domain/Payments/PaymentService.php:201-275`
- `app/Domain/Payments/PaymentService.php:1156-1175`
- `app/Domain/Finance/QuotationConversionService.php:18-63`

### Receipt generation risk

Risk areas:

- Receipt data uses `provider_reference` as the payment reference fallback.
- Account label is method-based, not provider-based.
- Receipt payment breakdown includes card, mobile money, bank transfer, and bank deposit as electronic payments.

Controls:

- Populate CyberSource `provider_reference` with a stable reference safe for receipts.
- Do not store sensitive card data in receipt-accessible fields.
- Do not alter `PaymentReceiptPdfService` unless a specific CyberSource display requirement is approved.

Source: `app/Domain/Finance/PaymentReceiptPdfService.php:85-181`.

### Application submission risk

Risk areas:

- Only `PaymentStatus::Confirmed` counts toward payment satisfaction.
- `PaymentService::applyVerifiedStatus('confirmed')` calls `markApplicationPaid()`.
- `markApplicationPaid()` calls `ApplicationAutoSubmissionService::submitAfterPaymentSatisfied()`.
- Auto-submission is idempotent and only submits if payment satisfaction is true.

Controls:

- Do not call auto-submission directly from CyberSource code.
- Do not mark ambiguous CyberSource responses as confirmed.
- Use the existing normalized status application path.
- Add tests that failed/rejected/pending CyberSource responses do not submit applications.

Sources:

- `app/Domain/Payments/ApplicationPaymentSatisfaction.php:18-43`
- `app/Domain/Payments/PaymentService.php:1017-1175`
- `app/Domain/Applications/ApplicationAutoSubmissionService.php:37-155`

## Readiness Conclusion

The codebase is structurally ready for a new card gateway because online payments already pass through `PaymentGateway`, `PaymentGatewayManager`, and `PaymentService`.

The highest-risk preparation item is the current `PaymentGatewayManager` fallback to `TestPaymentGateway`. A future CyberSource implementation must register `cybersource` explicitly and fail closed for unsupported providers so card misconfiguration cannot silently become a dummy success flow.

The implementation should be card-only:

- change card provider assignment from `test` to `cybersource`;
- add CyberSource card initiation/token confirmation surfaces;
- keep cGrate and manual proof paths unchanged;
- route confirmed CyberSource results through the existing `PaymentService` status application behavior.
