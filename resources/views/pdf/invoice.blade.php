<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .header-bar {
            background: #8f1d2f;
            color: #ffffff;
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 0.04em;
            padding: 18px 24px 16px;
        }
        .content {
            padding: 28px 42px 36px;
        }
        .brand-block {
            text-align: center;
            margin: 18px 0 24px;
        }
        .brand-logo {
            max-height: 72px;
            margin-bottom: 10px;
        }
        .brand-name {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .brand-line {
            font-size: 11px;
            line-height: 1.45;
            color: #374151;
        }
        .bill-to {
            margin: 18px 0 28px;
            line-height: 1.55;
        }
        .bill-to-label {
            font-weight: bold;
            margin-bottom: 6px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 22px;
        }
        .meta-table td {
            padding: 4px 0;
            vertical-align: top;
        }
        .meta-label {
            width: 34%;
            font-weight: bold;
        }
        .meta-value {
            width: 66%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .items-table th {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
        }
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 8px 10px;
            vertical-align: top;
        }
        .items-table .num {
            text-align: right;
            white-space: nowrap;
        }
        .totals-wrap {
            width: 100%;
            margin-top: 18px;
        }
        .totals-table {
            width: 42%;
            margin-left: auto;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 0;
        }
        .totals-table .label {
            text-align: right;
            padding-right: 12px;
            font-weight: bold;
        }
        .totals-table .value {
            text-align: right;
            white-space: nowrap;
        }
        .totals-table .grand .label,
        .totals-table .grand .value {
            font-size: 12px;
            font-weight: bold;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    <div class="header-bar">Invoice</div>

    <div class="content">
        <div class="brand-block">
            @if (!empty($logo_data_uri))
                <img src="{{ $logo_data_uri }}" alt="ZAQA logo" class="brand-logo">
            @else
                <div class="brand-name">{{ $organization['name'] ?? 'Zambia Qualifications Authority' }}</div>
            @endif
            <div class="brand-name">{{ $organization['name'] ?? 'Zambia Qualifications Authority.' }}</div>
            @if (!empty($organization['address']))
                <div class="brand-line">{{ $organization['address'] }}</div>
            @endif
            @if (!empty($organization['phone']))
                <div class="brand-line">{{ $organization['phone'] }}</div>
            @endif
            @if (!empty($organization['email']))
                <div class="brand-line">{{ $organization['email'] }}</div>
            @endif
        </div>

        <div class="bill-to">
            <div class="bill-to-label">BILL TO:</div>
            @if (!empty($bill_to['address']))
                <div>{{ $bill_to['address'] }}</div>
            @elseif (!empty($bill_to['name']))
                <div>{{ $bill_to['name'] }}</div>
            @endif
            @if (!empty($bill_to['phone']))
                <div>{{ $bill_to['phone'] }}</div>
            @endif
            @if (!empty($bill_to['email']))
                <div>{{ $bill_to['email'] }}</div>
            @endif
        </div>

        <table class="meta-table">
            <tr>
                <td class="meta-label">Invoice Number :</td>
                <td class="meta-value">#{{ $invoice_number }}</td>
            </tr>
            <tr>
                <td class="meta-label">Invoice Date :</td>
                <td class="meta-value">{{ $invoice_date ?: 'N/A' }}</td>
            </tr>
            <tr>
                <td class="meta-label">Status :</td>
                <td class="meta-value">{{ $status_label }}</td>
            </tr>
            <tr>
                <td class="meta-label">Application Id :</td>
                <td class="meta-value">#{{ $application_reference ?: $application_id ?: 'N/A' }}</td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="width: 12%;">Quantity</th>
                    <th style="width: 18%;" class="num">Amount</th>
                    <th style="width: 18%;" class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($line_items as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td class="num">{{ $currency }} {{ number_format($item['amount_cents'] / 100, 2) }}</td>
                        <td class="num">{{ $currency }} {{ number_format($item['total_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-wrap">
            <table class="totals-table">
                <tr>
                    <td class="label">Sub Total :</td>
                    <td class="value">{{ $currency }} {{ number_format($subtotal_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td class="label">VAT ({{ $vat_rate_label }}) :</td>
                    <td class="value">{{ $currency }} {{ number_format($vat_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td class="label">Discount ({{ $discount_rate_label }}) :</td>
                    <td class="value">{{ $currency }} {{ number_format($discount_cents / 100, 2) }}</td>
                </tr>
                <tr class="grand">
                    <td class="label">Total :</td>
                    <td class="value">{{ $currency }} {{ number_format($total_cents / 100, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
