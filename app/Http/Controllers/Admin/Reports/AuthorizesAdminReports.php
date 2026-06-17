<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Support\Reports\ReportAuthorization;

trait AuthorizesAdminReports
{
    protected function authorizeVerificationReportView(): void
    {
        ReportAuthorization::abortUnlessVerificationView(auth()->user());
    }

    protected function authorizeVerificationReportDownload(): void
    {
        ReportAuthorization::abortUnlessVerificationDownload(auth()->user());
    }

    protected function authorizeCertificatesReportView(): void
    {
        ReportAuthorization::abortUnlessCertificatesView(auth()->user());
    }

    protected function authorizeCertificatesReportDownload(): void
    {
        ReportAuthorization::abortUnlessCertificatesDownload(auth()->user());
    }
}
