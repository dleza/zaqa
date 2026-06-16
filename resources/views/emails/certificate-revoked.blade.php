<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $headline }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    <p>Dear applicant,</p>
    <p><strong>{{ $headline }}</strong></p>
    <p>{{ $body }}</p>
    <p>Application reference: <strong>{{ $applicationNumber }}</strong></p>
    <p>
        <a href="{{ $portalUrl }}" style="color: #0f766e;">View your application on the ZAQA portal</a>
    </p>
    <p style="font-size: 12px; color: #6b7280;">This is an automated message from the Zambia Qualifications Authority.</p>
</body>
</html>
