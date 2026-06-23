<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
@include('pdf.partials.ecz-subject-certificate-styles')
    </style>
</head>
<body>
<div class="page">
    @if(!empty($coat_of_arms_watermark_data_uri))
        <img class="watermark" src="{{ $coat_of_arms_watermark_data_uri }}" alt="">
    @endif

    <div class="certificate-frame">
        <div class="certificate-shell">
            <table class="header-table">
                <tr>
                    <td class="logo-cell">
                        @if(!empty($logo_data_uri))
                            <img class="logo-img" src="{{ $logo_data_uri }}" alt="ZAQA">
                        @else
                            <div style="font-size:20px;font-weight:bold;color:#0073BA;">ZAQA</div>
                            <div style="font-size:9px;color:#0073BA;">Zambia Qualifications Authority</div>
                        @endif
                    </td>
                    <td class="title-cell">
                        <p class="cert-title">
                            <span class="cert-title-line">Certificate of</span>
                            <span class="cert-title-line">Verification of Qualification</span>
                        </p>
                    </td>
                </tr>
            </table>

            <div class="act-line">The Zambia Qualifications Authority Act # 8 of 2024</div>

            <div class="body-block">
                <table class="meta-columns">
                    <tr>
                        <td class="meta-col-left">
                            <table class="meta-rows">
                                <tr>
                                    <td class="meta-label">Qualification Holder:</td>
                                    <td class="meta-value">{{ $holder_name }}</td>
                                </tr>
                                <tr>
                                    <td class="meta-label">NRC/Passport ID:</td>
                                    <td class="meta-value">{{ $holder_id }}</td>
                                </tr>
                                <tr>
                                    <td class="meta-label">Date Verified:</td>
                                    <td class="meta-value">{{ $date_verified }}</td>
                                </tr>
                            </table>
                        </td>
                        <td class="meta-col-right">
                            <table class="meta-rows">
                                <tr>
                                    <td class="meta-label">ZAQA REF ID:</td>
                                    <td class="meta-value">{{ $certificate_number }}</td>
                                </tr>
                                <tr>
                                    <td class="meta-label">NQF level:</td>
                                    <td class="meta-value">{{ $nqf_level }}</td>
                                </tr>
                                <tr class="meta-spacer">
                                    <td colspan="2">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <div class="title-section">
                    <div class="section-text">This qualification bearing title of:</div>
                    <div class="qualification-title">{{ $qualification_title }}</div>
                    <div class="results-intro">Has been validated/verified as genuinely awarded with the following results:</div>
                </div>

                <div class="table-wrap">
                    <table class="subjects-table">
                        <thead>
                        <tr>
                            <th>Subject</th>
                            <th class="grade">Grade</th>
                            <th class="exam">Examination Number</th>
                            <th class="year">Year</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($subject_results as $row)
                            <tr>
                                <td>{{ $row['subject_name'] }}</td>
                                <td class="grade">{{ $row['grade'] }}</td>
                                <td class="exam">{{ $row['examination_number'] ?? '' }}</td>
                                <td class="year">{{ $row['year'] ?? '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="institution-section">
                    <div class="awarding-block">
                        <span class="label">By:</span> {{ $awarding_institution }}
                    </div>

                    @if(!empty($recognition_statement))
                        <div class="institution-block">
                            <div class="label">A registered and recognized institution:</div>
                            <div>{{ $recognition_statement }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="signature-section">
                <div class="issued-by">Issued by the Zambia Qualifications Authority</div>
                @if(!empty($signature_data_uri))
                    <div class="sig-block">
                        <img class="sig-img" src="{{ $signature_data_uri }}" alt="Authorized signature">
                    </div>
                @endif
                <div class="sig-name">{{ $director_name }}</div>
                <div class="sig-title">{{ $director_title }}</div>
            </div>

            <div class="qr-section">
                <div class="qr-wrap">
                    @if(!empty($qr_data_uri))
                        <img src="{{ $qr_data_uri }}" alt="Verification QR">
                    @endif
                </div>
                <div class="qr-note">
                    Verify using the QR code or reference {{ $certificate_number }}.
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
