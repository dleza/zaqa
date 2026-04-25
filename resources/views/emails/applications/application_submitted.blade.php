<p>Hello {{ $recipientName }},</p>

@if ($isResubmission)
    <p>Your ZAQA application <strong>{{ $applicationNumber }}</strong> has been resubmitted successfully.</p>
@else
    <p>Your ZAQA application <strong>{{ $applicationNumber }}</strong> has been submitted successfully.</p>
@endif

<p>Please keep your application number for reference. If payment is required for your service type, follow the payment instructions provided in the portal once they are available.</p>

<p>Regards,<br />ZAQA</p>

