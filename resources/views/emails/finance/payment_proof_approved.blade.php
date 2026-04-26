@php
/** @var \App\Models\Application $application */
/** @var \App\Models\Payment $payment */
@endphp

<p>Dear {{ $applicant->name ?? 'Applicant' }},</p>

<p>Your payment proof has been <strong>approved</strong> and your payment is now confirmed.</p>

<ul>
    <li><strong>Application</strong>: {{ $application->application_number }}</li>
    <li><strong>Amount</strong>: {{ number_format(($payment->amount_cents ?? 0) / 100, 2) }} {{ strtoupper($payment->currency ?? 'ZMW') }}</li>
    <li><strong>Method</strong>: {{ str_replace('_', ' ', (string) ($payment->method?->value ?? $payment->method)) }}</li>
</ul>

@if(!empty($comment))
<p><strong>Finance note:</strong></p>
<p style="white-space: pre-wrap">{{ $comment }}</p>
@endif

<p>Next step: continue your application in the portal.</p>

<p><a href="{{ $nextUrl }}">Continue application</a></p>

<p>Regards,<br/>ZAQA</p>

