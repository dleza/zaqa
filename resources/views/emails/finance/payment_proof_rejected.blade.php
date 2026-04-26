@php
/** @var \App\Models\Application $application */
/** @var \App\Models\Payment $payment */
@endphp

<p>Dear {{ $applicant->name ?? 'Applicant' }},</p>

<p>Your payment proof has been <strong>rejected</strong>.</p>

<ul>
    <li><strong>Application</strong>: {{ $application->application_number }}</li>
    <li><strong>Amount</strong>: {{ number_format(($payment->amount_cents ?? 0) / 100, 2) }} {{ strtoupper($payment->currency ?? 'ZMW') }}</li>
    <li><strong>Method</strong>: {{ str_replace('_', ' ', (string) ($payment->method?->value ?? $payment->method)) }}</li>
</ul>

<p><strong>Reason:</strong></p>
<p style="white-space: pre-wrap">{{ $reason }}</p>

<p>Next step: login and upload a corrected proof of payment (or choose another payment method if available).</p>

<p><a href="{{ $nextUrl }}">Open payment step</a></p>

<p>Regards,<br/>ZAQA</p>

