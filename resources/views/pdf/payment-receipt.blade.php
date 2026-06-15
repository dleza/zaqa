<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14px 16px; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #000;
            margin: 0;
            padding: 0;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        td, th {
            border: 2px solid #000;
            padding: 5px 7px;
            vertical-align: top;
        }
        .receipt-box {
            border: 3px solid #000;
            padding: 10px 12px 10px;
        }
        .header-table td {
            border: none;
            padding: 0 6px 8px 0;
        }
        .logo-cell { width: 20%; }
        .logo-img { max-height: 54px; }
        .org-cell { width: 44%; text-align: center; padding: 0 8px; }
        .org-name {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.35;
        }
        .org-line { font-size: 9px; line-height: 1.35; margin-top: 2px; font-weight: bold; }
        .contact-cell { width: 36%; font-size: 9px; line-height: 1.45; font-weight: bold; }
        .title-wrap { text-align: center; margin: 8px 0 10px; }
        .title-box {
            display: inline-block;
            border: 2px solid #000;
            font-size: 17px;
            font-weight: bold;
            padding: 5px 28px;
        }
        .meta-table { margin-bottom: 8px; }
        .meta-table td { border: none; padding: 2px 0; font-weight: bold; }
        .meta-right { text-align: right; width: 50%; }
        .details-table td { font-size: 9.5px; line-height: 1.35; }
        .details-left { width: 62%; }
        .details-right-label { width: 22%; font-weight: bold; white-space: nowrap; }
        .details-right-value { width: 16%; text-align: right; font-weight: bold; white-space: nowrap; }
        .footer-table { margin-top: 8px; }
        .footer-table td { border: none; padding: 2px 0; font-size: 8px; font-weight: bold; }
        .footer-right { text-align: right; }
        .sig-qr-table { margin-top: 8px; }
        .sig-qr-table td { border: none; padding: 0; vertical-align: bottom; }
        .sig-cell { width: 58%; padding-right: 10px; }
        .qr-cell { width: 42%; text-align: right; }
        .signature-box {
            border: 2px solid #000;
            min-height: 58px;
            padding: 6px 8px 4px;
        }
        .signature-img { max-height: 40px; max-width: 180px; }
        .signature-line { font-size: 10px; font-weight: bold; margin-top: 8px; }
        .qr-img { width: 78px; height: 78px; }
    </style>
</head>
<body>
    <div class="receipt-box">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if (!empty($logo_data_uri))
                        <img src="{{ $logo_data_uri }}" alt="ZAQA logo" class="logo-img">
                    @else
                        <div class="org-name">ZAMBIA<br>QUALIFICATIONS<br>AUTHORITY</div>
                    @endif
                </td>
                <td class="org-cell">
                    <div class="org-name">{{ $organization['legal_name'] ?? 'ZAMBIA QUALIFICATIONS AUTHORITY' }}</div>
                    @if (!empty($organization['address_line_1']))
                        <div class="org-line">{{ $organization['address_line_1'] }}</div>
                    @endif
                    @if (!empty($organization['address_line_2']))
                        <div class="org-line">{{ $organization['address_line_2'] }}</div>
                    @endif
                    @if (!empty($organization['address_line_3']))
                        <div class="org-line">{{ $organization['address_line_3'] }}</div>
                    @elseif (!empty($organization['address']))
                        <div class="org-line">{{ $organization['address'] }}</div>
                    @endif
                </td>
                <td class="contact-cell">
                    @if (!empty($organization['tel']))
                        <div>Tel: {{ $organization['tel'] }}</div>
                    @endif
                    @if (!empty($organization['fax']))
                        <div>Fax: {{ $organization['fax'] }}</div>
                    @endif
                    @if (!empty($organization['email']))
                        <div>Email: {{ $organization['email'] }}</div>
                    @endif
                    @if (!empty($organization['website']))
                        <div>Website: {{ $organization['website'] }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="title-wrap">
            <div class="title-box">Receipt</div>
        </div>

        <table class="meta-table">
            <tr>
                <td>No. {{ $receipt_number_display }}</td>
                <td class="meta-right">Date : {{ $receipt_date ?: '—' }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="meta-right">Time : {{ $receipt_time ?: '—' }}</td>
            </tr>
        </table>

        <table class="details-table">
            <tr>
                <td class="details-left" colspan="2">
                    <strong>Account</strong> {{ $account_label }} {{ $account_reference }}
                </td>
                <td class="details-right-label">Cheque No</td>
                <td class="details-right-value">{{ $breakdown['cheque_no'] }}</td>
            </tr>
            <tr>
                <td class="details-left" colspan="2">
                    <strong>Description:</strong> {{ $description }}
                </td>
                <td class="details-right-label">Cheque Amount</td>
                <td class="details-right-value">{{ $breakdown['cheque_amount'] }}</td>
            </tr>
            <tr>
                <td class="details-left" colspan="2" rowspan="2">
                    <strong>Amount In Words:</strong> {{ $amount_in_words }}
                </td>
                <td class="details-right-label">Cash Amount</td>
                <td class="details-right-value">{{ $breakdown['cash_amount'] }}</td>
            </tr>
            <tr>
                <td class="details-right-label">Electronic Cash Transfer</td>
                <td class="details-right-value">{{ $breakdown['electronic_amount'] }}</td>
            </tr>
            <tr>
                <td class="details-left" colspan="2">
                    <strong>Reference:</strong> {{ $reference }}
                </td>
                <td class="details-right-label"><strong>Total</strong></td>
                <td class="details-right-value"><strong>{{ $breakdown['total'] }}</strong></td>
            </tr>
        </table>

        <table class="sig-qr-table">
            <tr>
                <td class="sig-cell">
                    <div class="signature-box">
                        @if (!empty($signature_data_uri))
                            <img src="{{ $signature_data_uri }}" alt="Signature" class="signature-img">
                        @endif
                        <div class="signature-line">Signature..........................................</div>
                    </div>
                </td>
                <td class="qr-cell">
                    @if (!empty($qr_data_uri))
                        <img src="{{ $qr_data_uri }}" alt="Receipt QR" class="qr-img">
                    @endif
                </td>
            </tr>
        </table>

        <table class="footer-table">
            <tr>
                <td>This Is An Official Electronic Receipt From Zambia Qualifications Authority.</td>
                <td class="footer-right">You Learn, We Standardize</td>
            </tr>
        </table>
    </div>
</body>
</html>
