<p>A bank transfer proof of payment has been submitted for review.</p>

<p><strong>Application reference:</strong> {{ $application->application_number }}</p>
<p><strong>Invoice reference:</strong> {{ $payment->invoice?->invoice_number ?? 'Not available' }}</p>
<p><strong>Applicant:</strong> {{ $applicant->name }}</p>

@if(!empty($applicant->email))
<p><strong>Email:</strong> {{ $applicant->email }}</p>
@endif

@if(!empty($applicant->phone_primary))
<p><strong>Primary phone:</strong> {{ $applicant->phone_primary }}</p>
@endif

<p><strong>Amount:</strong> {{ number_format(((int) $payment->amount_cents) / 100, 2) }} {{ $payment->currency }}</p>
<p><strong>Submitted at:</strong> {{ optional($payment->awaiting_finance_review_at)->toDayDateTimeString() ?? optional($payment->updated_at)->toDayDateTimeString() }}</p>
<p><strong>Proof file:</strong> {{ $proofDocument?->original_name ?? 'Not available' }}</p>

@if($attachProof)
<p>The proof file is attached to this email.</p>
@else
<p>The proof file was not attached. Review it securely in the finance portal.</p>
@endif

<p><a href="{{ $financeReviewUrl }}">Open finance review page</a></p>
