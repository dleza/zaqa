<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
@include('pdf.partials.cveq-certificate-styles')
    </style>
</head>
<body>
<div class="certificate-shell">
    @if(!empty($coat_of_arms_watermark_data_uri))
        <img class="watermark" src="{{ $coat_of_arms_watermark_data_uri }}" alt="">
    @endif
    <div class="certificate-content">
    <table class="header-table">
        <tr>
            <td class="logo-box">
                @if(!empty($logo_data_uri))
                    <img class="logo-img" src="{{ $logo_data_uri }}" alt="ZAQA">
                @else
                    <div style="font-size:22px;font-weight:bold;color:#0073BA;">ZAQA</div>
                    <div style="font-size:10px;color:#0073BA;">Zambia Qualifications Authority</div>
                @endif
            </td>
            <td class="title-cell">
                <p class="main-title">
                    <span class="title-line">Certificate of Verification</span>
                    <span class="title-line">and Evaluation of Qualification</span>
                </p>
            </td>
        </tr>
    </table>

    <div class="act-line">The Zambia Qualifications Authority Act, No. 8 of 2024</div>

    <div class="content-block">
        <table class="fields-table">
            <tr>
                <td class="label">Qualification Holder</td>
                <td class="value">{{ $holder_name }}</td>
            </tr>
            <tr>
                <td class="label">NRC/Passport ID</td>
                <td class="value">{{ $holder_id }}</td>
            </tr>
            <tr>
                <td class="label">ZAQA Reference Number</td>
                <td class="value">{{ $certificate_number }}</td>
            </tr>
            <tr>
                <td class="label">Date of Validation</td>
                <td class="value">{{ $issued_at->timezone(config('app.timezone'))->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td class="label">Recognised Zambian Qualification</td>
                <td class="value">{{ $recognised_zambian_qualification }}</td>
            </tr>
            <tr>
                <td class="label">This qualification bearing title of</td>
                <td class="value">{{ $qualification_title }}</td>
            </tr>
            <tr>
                <td class="label">has been validated as genuinely awarded to</td>
                <td class="value">{{ $holder_name }}</td>
            </tr>
            <tr>
                <td class="label">on</td>
                <td class="value">{{ $award_date }}</td>
            </tr>
            <tr>
                <td class="label">by</td>
                <td class="value">{{ $awarding_institution }}</td>
            </tr>
            @if(!empty($recognition_statement))
            <tr>
                <td class="label">A registered and recognised institution</td>
                <td class="value">{{ $recognition_statement }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">This qualification is recognised in Zambia as</td>
                <td class="value">{{ $recognised_zambian_qualification }}</td>
            </tr>
            <tr>
                <td colspan="2" class="fw-bold-level">Framework Level: {{ $framework_line }}</td>
            </tr>
        </table>
    </div>

    <div class="signature-section">
        <div class="issued-by">Issued by The Zambia Qualifications Authority</div>
        @if(!empty($signature_data_uri))
            <div class="sig-block">
                <img class="sig-img" src="{{ $signature_data_uri }}" alt="Authorized signature">
            </div>
        @endif
        <div class="sig-name">{{ $director_name }}</div>
        <div class="sig-title">{{ $director_title }}</div>
    </div>

    <div class="footer-section">
        <hr class="divider">

        <div class="footer-note">
            ZAQA has confirmed the above information for digital certification at {{ $issued_for_footer }}.
            Verify using the QR code below or reference {{ $certificate_number }}.
        </div>

        <div class="qr-wrap">
            @if(!empty($qr_data_uri))
                <img src="{{ $qr_data_uri }}" alt="Verification QR">
            @endif
        </div>
        <div class="powered">VERIFICATION — SCAN QR TO CONFIRM</div>
    </div>
    </div>
</div>
</body>
</html>
