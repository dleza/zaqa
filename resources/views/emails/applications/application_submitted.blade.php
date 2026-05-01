<p>Hello {{ $recipientName }},</p>

@if ($isResubmission)
    <p>Your ZAQA application <strong>{{ $applicationNumber }}</strong> has been resubmitted successfully.</p>
@else
    <p>Your ZAQA application <strong>{{ $applicationNumber }}</strong> has been submitted successfully.</p>
@endif

<p>
    Application reference: <strong>{{ $applicationNumber }}</strong><br />
    Track your application: <a href="{{ $trackingUrl }}">{{ $trackingUrl }}</a>
</p>

@if (count($qualificationReferences ?? []) > 0)
    <p>
        Each qualification you submitted is tracked separately in the verification pool. Use the reference below that matches the programme when you contact ZAQA about that specific item:
    </p>
    <ul style="margin-top:8px;padding-left:18px;">
        @foreach ($qualificationReferences as $row)
            <li style="margin-bottom:6px;">
                <strong>{{ $row['title'] }}</strong><br />
                Verification reference: <strong>{{ $row['reference'] }}</strong>
            </li>
        @endforeach
    </ul>
@endif

<p>Please keep these reference numbers for correspondence. If payment is required for your service type, follow the payment instructions provided in the portal once they are available.</p>

<p>Regards,<br />ZAQA</p>
