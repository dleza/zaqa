<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
@include('pdf.partials.cveq-certificate-styles')
        .subjects-wrap {
            margin-top: 12px;
            border: 1px solid #c5d9ea;
            border-radius: 10px;
            padding: 10px 12px 8px;
            background: rgba(255, 255, 255, 0.55);
        }
        .subjects-title {
            font-size: 11px;
            font-weight: bold;
            color: #1a2e3f;
            margin-bottom: 8px;
        }
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .subjects-table th,
        .subjects-table td {
            border: 1px solid #c5d9ea;
            padding: 6px 7px;
            vertical-align: top;
        }
        .subjects-table th {
            background: #eef6fc;
            color: #1a2e3f;
            font-weight: bold;
            text-align: left;
        }
        .subjects-table td:first-child,
        .subjects-table th:first-child {
            width: 34px;
            text-align: center;
        }
        .subjects-table td:last-child,
        .subjects-table th:last-child {
            width: 70px;
            text-align: center;
        }
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
                <td class="value">{{ $zaqa_reference !== '' ? $zaqa_reference : '—' }}</td>
            </tr>
            <tr>
                <td class="label">CVEQ Certificate Number</td>
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
                <td class="label">Qualification title</td>
                <td class="value">{{ $qualification_title }}</td>
            </tr>
            <tr>
                <td class="label">Awarding institution</td>
                <td class="value">{{ $awarding_institution }}</td>
            </tr>
            <tr>
                <td class="label">Award date / year</td>
                <td class="value">
                    {{ $award_date }}
                    @if(!empty($award_year))
                        ({{ $award_year }})
                    @endif
                </td>
            </tr>
            @if(!empty($recognition_statement))
            <tr>
                <td class="label">Accreditation statement</td>
                <td class="value">{{ $recognition_statement }}</td>
            </tr>
            @endif
            <tr>
                <td colspan="2" class="fw-bold-level">Framework Level: {{ $framework_line }}</td>
            </tr>
        </table>

        <div class="subjects-wrap">
            <div class="subjects-title">Validated subject results</div>
            <table class="subjects-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Subject</th>
                    <th>Grade</th>
                </tr>
                </thead>
                <tbody>
                @foreach($subject_results as $row)
                    <tr>
                        <td>{{ $row['index'] }}</td>
                        <td>{{ $row['subject_name'] }}</td>
                        <td>{{ $row['grade'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
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
