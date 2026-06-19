# CyberSource Card Payment Implementation Plan

## Summary

This document records the implementation plan for replacing the current dummy card payment behavior with a CyberSource REST API card payment integration.

The integration scope is intentionally narrow:

- Only the applicant `Pay by Card` option changes.
- Mobile money must continue using the existing mobile money provider and logic.
- Deposit and bank transfer proof upload, finance review, invoice generation, receipt handling, and application submission must continue working through the existing flows.
- CyberSource failures or misconfiguration must make only card payment unavailable.

## Current Card Flow

The current card flow is a dummy/test provider flow:

1. The applicant clicks `Pay by Card` on the payment step.
2. Vue posts to `/applicant/applications/{application}/payment/initiate-card`.
3. `ApplicantPaymentController::initiateCardForApplication()` authorizes the applicant, creates or reuses a card draft payment, and calls `PaymentService::initiateOnline()`.
4. `PaymentService::createDraftPayment()` currently assigns card payments to provider `test`.
5. `PaymentService::initiateCardPayment()` resolves the gateway through `PaymentGatewayManager`.
6. `PaymentGatewayManager` returns `TestPaymentGateway` for provider `test`.
7. `TestPaymentGateway::initiate()` creates a `TEST-*` provider reference and redirects to the test redirect route.
8. `ApplicantPaymentController::testRedirect()` simulates a successful provider return by default.
9. `TestPaymentGateway::verify()` maps the simulated success response to normalized status `confirmed`.
10. `PaymentService::applyVerifiedStatus()` updates the payment to `PaymentStatus::Confirmed`.
11. The existing downstream payment side effects run:
    - the payment receives `confirmed_at`;
    - the invoice is converted/marked through the existing finance flow;
    - the application receives `paid_at`;
    - receipt behavior remains based on the existing confirmed payment data;
    - existing auto-submission logic submits the application after payment satisfaction.

The dummy success behavior is therefore not isolated to a single line. It is the combined result of card payments using provider `test`, the test gateway redirect, and the test gateway verification response.

## Existing Payment Architecture

The payment architecture is already gateway-oriented:

- `PaymentGateway` defines the gateway contract:
  - `providerKey(): string`
  - `initiate(Payment $payment, PaymentMethod $method, array $payload): array`
  - `verify(string $providerReference, array $payload): array`
- `PaymentGatewayManager` resolves a provider key to a gateway implementation.
- `PaymentService` owns payment state transitions and the side effects of confirmed payments.
- `PaymentMethod` separates card, mobile money, bank deposit, and bank transfer behavior.
- `PaymentStatus` values currently used by the system are:
  - `draft`
  - `initiated`
  - `pending_confirmation`
  - `awaiting_finance_review`
  - `confirmed`
  - `rejected`
  - `failed`
  - `expired`
- Only confirmed payments should satisfy the application payment requirement.

The existing status application path should be preserved:

- Gateway-specific code should normalize external responses.
- `PaymentService::applyGatewayVerificationResult()` and `PaymentService::applyVerifiedStatus()` should remain responsible for applying normalized statuses.
- `PaymentService::markApplicationPaid()` should remain the path that triggers existing invoice, receipt, and application auto-submission behavior.

## CyberSource Implementation Strategy

The CyberSource integration must use the REST API path only. Hosted Checkout and Secure Acceptance are out of scope and must not be introduced.

The target design is:

1. Applicant selects `Pay by Card`.
2. Laravel creates or reuses a card payment draft.
3. The card draft uses provider `cybersource`, not `test`.
4. The frontend loads CyberSource Microform/Flex fields.
5. The applicant enters card details into CyberSource-controlled fields.
6. CyberSource Microform/Flex returns a transient token.
7. Vue posts only the transient token and payment ID to Laravel.
8. Laravel submits the transient token to the CyberSource REST Payments API through the official CyberSource PHP REST SDK.
9. Laravel maps the CyberSource response into the existing normalized payment statuses.
10. `PaymentService` applies the result using the existing status application path.

Required implementation pieces:

- Add the official CyberSource PHP REST SDK.
- Configure CyberSource through Laravel config and environment variables.
- Use JWT authentication, preferably shared-secret JWT configuration where supported by the SDK.
- Add `CyberSourcePaymentGateway`.
- Add a CyberSource client factory/service layer for SDK configuration.
- Add a capture-context endpoint for Microform/Flex.
- Add a transient-token confirmation endpoint for card payment submission.
- Sanitize CyberSource responses before storing them in `raw_payload`.
- Disable or hide only the card option when CyberSource is disabled or misconfigured.

Expected provider routing after implementation:

- Card -> `cybersource`
- Mobile money -> existing mobile money provider
- Deposit/bank transfer -> existing manual proof upload and finance review flow

## Risks

- The current card flow is redirect-based, while the CyberSource Microform/Flex flow is token-based and may require Vue state changes.
- The existing `PaymentGatewayManager` falls back to `TestPaymentGateway` for unknown providers; this must not mask CyberSource misconfiguration in production.
- CyberSource status mapping must be conservative. Ambiguous or pending responses must not be marked as confirmed.
- SDK or credential misconfiguration must not affect mobile money or deposit/bank transfer options.
- Sensitive card data must not touch Laravel request validation, logs, database payloads, audit logs, or exception traces.
- The transient token must not be stored long term.
- Duplicate submissions must be guarded so repeated clicks or retries cannot create multiple confirmed payments for the same invoice.
- Any database changes for gateway metadata must be additive and nullable so existing payment reads continue to work.

## Rollback Strategy

Rollback should be scoped to card payments only:

- Gate CyberSource card availability behind configuration, for example `CYBERSOURCE_ENABLED`.
- If CyberSource is disabled or misconfigured, hide or disable only the `Pay by Card` option.
- Keep mobile money and deposit/bank transfer options available.
- Preserve the existing status application path so confirmed payments continue to drive invoice, receipt, and application submission behavior.
- Avoid destructive or incompatible database changes.
- Keep any new CyberSource metadata columns nullable.
- Do not remove existing payment records or alter historical test/mobile money/bank transfer records.

The previous dummy card behavior should not be used as an automatic production fallback. If card is unavailable, the user should see a clear card-unavailable state and choose another payment method.

## Test Plan

Card payment tests:

- Card draft creation uses provider `cybersource`, not `test`.
- CyberSource capture context can be created for an eligible applicant/payment.
- Successful CyberSource token payment maps to `PaymentStatus::Confirmed`.
- Confirmed CyberSource payment triggers the existing invoice, receipt, and application submission behavior.
- Declined CyberSource payment maps to `PaymentStatus::Rejected` or `PaymentStatus::Failed` and does not mark the invoice or application as paid.
- Pending or ambiguous CyberSource responses map to `PaymentStatus::PendingConfirmation`.
- Expired or abandoned card attempts can be retried safely.
- Duplicate card submission does not create multiple confirmed payments for the same invoice.
- CyberSource disabled or misconfigured disables only card payment.

Regression tests:

- Mobile money still routes to the existing mobile money provider.
- Mobile money prompt, polling, and callback behavior continue to work.
- Deposit and bank transfer proof upload still enters finance review.
- Finance approval and rejection for proof payments continue to work.
- Existing invoice generation tests continue to pass.
- Existing receipt tests continue to pass.
- Existing application auto-submission tests continue to pass.

Security tests and checks:

- Laravel requests never include raw card number, CVV, or full expiry.
- Logs do not include raw card data or transient token values.
- Stored `raw_payload` values are sanitized.
- Capture-context target origins come from server-side configuration, not browser input.

## Acceptance Criteria

- The first commit on this branch is documentation-only.
- The commit contains only `docs/cybersource-integration-plan.md`.
- No PHP, Vue, route, migration, config, or test files are changed in the first commit.
- After the commit, `git status --short` is clean.
