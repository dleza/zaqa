<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate issued</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.5; color: #111827;">
    <p>Your <strong>Certificate of Verification and Evaluation of Qualification</strong> has been issued.</p>

    <ul style="padding-left: 1.25rem;">
        <li><strong>Application reference:</strong> {{ $applicationNumber }}</li>
        <li><strong>Qualification:</strong> {{ $qualificationTitle !== '' ? $qualificationTitle : '—' }}</li>
        <li><strong>Certificate number:</strong> {{ $certificateNumber }}</li>
    </ul>

    <p>The PDF is attached to this email. You can also download it anytime when signed in:</p>
    <p><a href="{{ $downloadUrl }}" style="color: #0076BD;">Download certificate (PDF)</a></p>

    <p style="font-size: 12px; color: #6b7280;">If the button or link does not work, sign in to the applicant portal and open your application.</p>
</body>
</html>
