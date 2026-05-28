<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
            margin: 0;
            padding: 0;
            position: relative;
        }
        .watermark {
            position: fixed;
            left: 50%;
            top: 50%;
            width: 320px;
            height: auto;
            transform: translate(-50%, -50%);
            opacity: 0.06;
            z-index: 0;
            pointer-events: none;
        }
        .layer { position: relative; z-index: 1; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        .logo-box { width: 42%; }
        .logo-img { max-height: 52px; }
        .title-cell { text-align: right; width: 58%; padding-left: 12px; }
        .main-title {
            font-size: 11px;
            font-weight: bold;
            color: #0076BD;
            text-transform: uppercase;
            line-height: 1.25;
            margin: 0;
        }
        .act-line {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 10px 0 16px;
        }
        .fields-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .fields-table td { padding: 4px 0; vertical-align: top; }
        .label { font-weight: bold; width: 38%; color: #111827; }
        .value { width: 62%; }
        .fw-bold-level { font-weight: bold; margin-top: 8px; }
        .subjects-wrap {
            margin-top: 16px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 10px 12px 8px;
            background: rgba(248, 250, 252, 0.8);
        }
        .subjects-title {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .subjects-table th,
        .subjects-table td {
            border: 1px solid #cbd5e1;
            padding: 6px 7px;
            vertical-align: top;
        }
        .subjects-table th {
            background: #eff6ff;
            color: #0f172a;
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
        .issued-by {
            margin-top: 20px;
            text-align: center;
            color: #EA580C;
            font-weight: bold;
            font-size: 11px;
        }
        .sig-name {
            margin-top: 12px;
            text-align: center;
            color: #0076BD;
            font-weight: bold;
            font-size: 12px;
        }
        .sig-title {
            text-align: center;
            color: #0076BD;
            font-style: italic;
            font-size: 11px;
            margin-top: 2px;
        }
        .divider {
            margin: 14px auto 10px;
            width: 92%;
            border: none;
            border-top: 1px solid #d1d5db;
        }
        .footer-note {
            font-size: 9px;
            color: #4b5563;
            text-align: center;
            line-height: 1.35;
            padding: 0 8px;
        }
        .qr-wrap {
            margin-top: 12px;
            text-align: center;
        }
        .qr-wrap img { height: 118px; width: auto; }
        .powered {
            margin-top: 6px;
            font-size: 8px;
            letter-spacing: 0.08em;
            color: #6b7280;
            text-align: center;
        }
    </style>
</head>
<body>
@if(!empty($coat_of_arms_watermark_data_uri))
    <img class="watermark" src="{{ $coat_of_arms_watermark_data_uri }}" alt="">
@endif
<div class="layer">
    <table class="header-table">
        <tr>
            <td class="logo-box">
                @if(!empty($logo_data_uri))
                    <img class="logo-img" src="{{ $logo_data_uri }}" alt="ZAQA">
                @else
                    <div style="font-size:22px;font-weight:bold;color:#0076BD;">ZAQA</div>
                    <div style="font-size:10px;color:#0076BD;">Zambia Qualifications Authority</div>
                @endif
            </td>
            <td class="title-cell">
                <p class="main-title">Certificate of Verification and Evaluation of Qualification</p>
            </td>
        </tr>
    </table>

    <div class="act-line">The Zambia Qualifications Authority Act, No. 8 of 2024</div>

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

    <div class="issued-by">Issued by The Zambia Qualifications Authority</div>
    <div class="sig-name">{{ $director_name }}</div>
    <div class="sig-title">{{ $director_title }}</div>

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
</body>
</html>
