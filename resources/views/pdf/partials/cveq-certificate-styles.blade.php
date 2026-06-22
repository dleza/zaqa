        @page {
            margin: 0;
            size: A4 portrait;
            background-color: #edf5fb;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1a2e3f;
            background-color: #edf5fb;
        }
        .certificate-shell {
            position: relative;
            padding: 26px 34px 16px;
            background-color: #edf5fb;
            page-break-inside: avoid;
            page-break-after: avoid;
        }
        .watermark {
            position: absolute;
            left: 50%;
            top: 44%;
            width: 430px;
            height: auto;
            margin-left: -215px;
            margin-top: -165px;
            opacity: 0.085;
            z-index: 0;
            pointer-events: none;
        }
        .certificate-content {
            position: relative;
            z-index: 1;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .header-table td { vertical-align: top; }
        .logo-box { width: 44%; padding-top: 2px; }
        .logo-img { max-height: 52px; width: auto; }
        .title-cell {
            width: 56%;
            padding-left: 12px;
            padding-top: 6px;
            text-align: right;
        }
        .main-title {
            font-size: 9.5px;
            font-weight: bold;
            color: #0073BA;
            text-transform: uppercase;
            line-height: 1.5;
            margin: 0;
            letter-spacing: 0.05em;
        }
        .title-line { display: block; }
        .act-line {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            color: #1a2e3f;
            margin: 10px auto 12px;
            width: 88%;
        }
        .content-block {
            width: 84%;
            margin: 0 auto;
            padding: 0 1%;
        }
        .fields-table { width: 100%; border-collapse: collapse; }
        .fields-table td {
            padding: 3px 0;
            vertical-align: top;
            line-height: 1.45;
        }
        .label {
            font-weight: bold;
            width: 43%;
            color: #1a2e3f;
            padding-right: 10px;
        }
        .value { width: 57%; color: #111827; }
        .fw-bold-level {
            font-weight: bold;
            margin-top: 8px;
            color: #1a2e3f;
            padding-top: 2px;
        }
        .signature-section {
            width: 84%;
            margin: 22px auto 0;
            text-align: center;
        }
        .issued-by {
            color: #EA580C;
            font-weight: bold;
            font-size: 11px;
        }
        .sig-block { margin-top: 6px; text-align: center; }
        .sig-img {
            display: inline-block;
            max-width: 164px;
            max-height: 56px;
            width: auto;
            height: auto;
        }
        .sig-name {
            margin-top: 8px;
            text-align: center;
            color: #0073BA;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: 0.04em;
        }
        .sig-title {
            text-align: center;
            color: #0073BA;
            font-style: italic;
            font-size: 11px;
            margin-top: 2px;
        }
        .footer-section {
            width: 86%;
            margin: 12px auto 0;
            text-align: center;
            padding-bottom: 6px;
        }
        .divider {
            margin: 0 auto 10px;
            width: 78%;
            border: none;
            border-top: 1px solid #c5d9ea;
        }
        .footer-note {
            font-size: 9px;
            color: #475569;
            text-align: center;
            line-height: 1.4;
            padding: 0 8px;
        }
        .qr-wrap {
            margin-top: 8px;
            text-align: center;
        }
        .qr-wrap img { height: 108px; width: auto; }
        .powered {
            margin-top: 4px;
            font-size: 8px;
            letter-spacing: 0.08em;
            color: #64748b;
            text-align: center;
        }
