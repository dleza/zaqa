<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Activate your ZAQA account</title>
    </head>
    <body style="font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #111;">
        <p>Dear {{ $recipientName }},</p>

        <p>
            Thank you for registering on the ZAQA Qualification Verification Platform.
            To activate your account, please click the link below:
        </p>

        <p>
            <a href="{{ $activationUrl }}">{{ $activationUrl }}</a>
        </p>

        <p>
            This link expires on {{ $expiresAt->toDayDateTimeString() }}.
        </p>

        <p>
            If you did not create this account, you may ignore this email.
        </p>

        <p>ZAQA Qualification Verification Platform</p>
    </body>
</html>

