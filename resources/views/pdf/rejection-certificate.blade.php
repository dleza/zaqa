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
            font-size: 12px;
            font-weight: bold;
            color: #B91C1C;
            text-transform: uppercase;
            line-height: 1.25;
            margin: 0;
        }
        .subtitle {
            font-size: 10px;
            color: #374151;
            margin-top: 4px;
        }
        .fields-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .fields-table td { padding: 6px 0; vertical-align: top; }
        .label { font-weight: bold; width: 38%; color: #111827; }
        .value { width: 62%; }
        .decision-box {
            margin-top: 18px;
            padding: 12px 14px;
            border: 1px solid #FECACA;
            background: #FEF2F2;
            border-radius: 4px;
        }
        .decision-title {
            font-weight: bold;
            color: #991B1B;
            margin-bottom: 6px;
            font-size: 11px;
        }
        .decision-body {
            line-height: 1.45;
            color: #1F2937;
        }
        .issued-by {
            margin-top: 24px;
            text-align: center;
            color: #B91C1C;
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
        .qr-wrap img { height: 110px; width: auto; }
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
                <p class="main-title">Notice of Rejection</p>
                <p class="subtitle">Qualification Verification Rejection Notice</p>
            </td>
        </tr>
    </table>

    <table class="fields-table">
        <tr>
            <td class="label">Notice reference</td>
            <td class="value">{{ $certificate_number }}</td>
        </tr>
        <tr>
            <td class="label">Issue date</td>
            <td class="value">{{ $issued_at_formatted }}</td>
        </tr>
        <tr>
            <td class="label">Application number</td>
            <td class="value">{{ $application_number }}</td>
        </tr>
        <tr>
            <td class="label">ZAQA reference</td>
            <td class="value">{{ $zaqa_reference ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Qualification holder</td>
            <td class="value">{{ $holder_name }}</td>
        </tr>
        <tr>
            <td class="label">NRC / Passport</td>
            <td class="value">{{ $holder_id }}</td>
        </tr>
        <tr>
            <td class="label">Qualification title</td>
            <td class="value">{{ $qualification_title }}</td>
        </tr>
        <tr>
            <td class="label">Awarding institution</td>
            <td class="value">{{ $awarding_institution }}</td>
        </tr>
    </table>

    <div class="decision-box">
        <div class="decision-title">{{ $decision_is_generic ? 'Decision' : 'Decision summary' }}</div>
        <div class="decision-body">{{ $decision_summary }}</div>
    </div>

    <div class="issued-by">Issued by the Zambia Qualifications Authority</div>

    @if(!empty($signature_data_uri))
        <div style="margin-top: 10px; text-align: center;">
            <img src="{{ $signature_data_uri }}" alt="Signature" style="max-height: 48px;">
        </div>
    @endif

    <div class="sig-name">{{ $director_name ?? 'Director General' }}</div>
    <div class="sig-title">{{ $director_title ?? 'Zambia Qualifications Authority' }}</div>

    <hr class="divider">

    <div class="qr-wrap">
        @if(!empty($qr_data_uri))
            <img src="{{ $qr_data_uri }}" alt="Verification QR code">
        @endif
    </div>

    <div class="footer-note">
        Scan the QR code or visit the verification URL to confirm this rejection notice on the official ZAQA registry.
        This document was issued at {{ $issued_for_footer }}.
    </div>
    <div class="powered">ZAMBIA QUALIFICATIONS AUTHORITY</div>
</div>
</body>
</html>
