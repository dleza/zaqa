        @page {
            margin: 20mm 18mm 16mm;
            size: A4 portrait;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
        }
        .page {
            position: relative;
            width: 100%;
        }
        .watermark {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 360px;
            height: auto;
            margin-left: -180px;
            margin-top: -140px;
            opacity: 0.06;
            z-index: 0;
            pointer-events: none;
        }
        .certificate-frame {
            position: relative;
            z-index: 1;
            width: 92%;
            max-width: 92%;
            margin: 8px auto 0;
            padding: 18px 20px 22px;
        }
        .certificate-shell {
            width: 87%;
            max-width: 87%;
            margin: 0 auto;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0 16px;
        }
        .header-table td {
            vertical-align: top;
            border: none;
            padding: 0;
        }
        .logo-cell {
            width: 46%;
            padding: 12px 18px 0 8px;
        }
        .logo-img {
            max-height: 56px;
            width: auto;
        }
        .title-cell {
            width: 54%;
            text-align: right;
            padding: 14px 8px 0 18px;
        }
        .cert-title {
            margin: 0;
            font-size: 11.5px;
            font-weight: bold;
            color: #0073BA;
            text-transform: uppercase;
            line-height: 1.5;
            letter-spacing: 0.06em;
        }
        .cert-title-line {
            display: block;
        }
        .act-line {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin: 18px auto 22px;
            padding: 0 12px;
        }
        .body-block {
            width: 100%;
            margin: 0 auto;
        }
        .meta-columns {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 20px;
        }
        .meta-columns > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            padding: 0 12px 0 0;
        }
        .meta-columns > tbody > tr > td.meta-col-right {
            padding: 0 0 0 12px;
        }
        .meta-rows {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-rows td {
            padding: 5px 0;
            vertical-align: top;
            line-height: 1.45;
        }
        .meta-rows .meta-label {
            font-weight: bold;
            white-space: nowrap;
            padding-right: 10px;
            width: 46%;
        }
        .meta-rows .meta-value {
            width: 54%;
        }
        .meta-spacer td {
            padding: 5px 0;
            line-height: 1.45;
        }
        .title-section {
            margin: 4px 0 18px;
        }
        .section-text {
            margin: 0 0 8px;
            line-height: 1.45;
        }
        .qualification-title {
            font-weight: bold;
            margin: 0 0 16px;
            line-height: 1.45;
        }
        .results-intro {
            margin: 0 0 14px;
            line-height: 1.45;
        }
        .table-wrap {
            width: 100%;
            margin: 8px 0 20px;
        }
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        .subjects-table th,
        .subjects-table td {
            border: 2px solid #000;
            padding: 7px 8px;
            vertical-align: top;
            text-align: left;
        }
        .subjects-table th {
            font-weight: bold;
            background: #fff;
        }
        .subjects-table td.grade,
        .subjects-table th.grade,
        .subjects-table td.year,
        .subjects-table th.year {
            width: 11%;
            text-align: center;
        }
        .subjects-table td.exam,
        .subjects-table th.exam {
            width: 26%;
        }
        .institution-section {
            margin-top: 4px;
            padding-top: 14px;
            border-top: 1px solid #d1d5db;
        }
        .awarding-block {
            line-height: 1.5;
        }
        .awarding-block .label {
            font-weight: bold;
        }
        .institution-block {
            margin-top: 10px;
            line-height: 1.5;
        }
        .institution-block .label {
            font-weight: bold;
        }
        .signature-section {
            margin-top: 0;
            padding-top: 18px;
            border-top: 1px solid #d1d5db;
            text-align: center;
            page-break-inside: avoid;
        }
        .issued-by {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 10px;
        }
        .sig-block { margin: 4px 0; }
        .sig-img {
            display: inline-block;
            max-width: 150px;
            max-height: 52px;
            width: auto;
            height: auto;
        }
        .sig-name {
            margin-top: 6px;
            font-weight: bold;
            font-size: 12px;
        }
        .sig-title {
            font-size: 11px;
            margin-top: 2px;
        }
        .qr-section {
            margin-top: 0;
            padding-top: 16px;
            border-top: 1px solid #d1d5db;
            text-align: center;
            page-break-inside: avoid;
        }
        .qr-wrap img {
            height: 92px;
            width: auto;
        }
        .qr-note {
            margin-top: 8px;
            font-size: 8.5px;
            color: #333;
            line-height: 1.35;
            padding: 0 12px;
        }
