<?php

namespace App\Support\Reports;

use App\Models\User;

class ReportAuthorization
{
    public static function canViewFinance(?User $user): bool
    {
        return $user !== null && (
            $user->can('finance.reports.view')
            || $user->can('reports.finance.view')
        );
    }

    public static function canDownloadFinance(?User $user): bool
    {
        return $user !== null && (
            $user->can('finance.reports.download')
            || $user->can('reports.finance.download')
        );
    }

    public static function canViewVerification(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.verification.view')
        );
    }

    public static function canDownloadVerification(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.verification.download')
        );
    }

    public static function canViewCertificates(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.certificates.view')
        );
    }

    public static function canDownloadCertificates(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.certificates.download')
        );
    }

    public static function canViewSla(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.sla.view')
        );
    }

    public static function canDownloadSla(?User $user): bool
    {
        return $user !== null && (
            $user->can('reports.view')
            || $user->can('reports.sla.view')
            || $user->can('reports.sla.download')
        );
    }

    public static function canViewLevel1Performance(?User $user): bool
    {
        return $user !== null && $user->can('verification.level1.process');
    }

    public static function canViewAny(?User $user): bool
    {
        return self::canViewFinance($user)
            || self::canViewVerification($user)
            || self::canViewCertificates($user)
            || self::canViewSla($user)
            || self::canViewLevel1Performance($user)
            || ($user !== null && $user->can('sms.logs.view'));
    }

    public static function abortUnlessFinanceView(?User $user): void
    {
        if (! self::canViewFinance($user)) {
            abort(403);
        }
    }

    public static function abortUnlessFinanceDownload(?User $user): void
    {
        if (! self::canDownloadFinance($user)) {
            abort(403);
        }
    }

    public static function abortUnlessVerificationView(?User $user): void
    {
        if (! self::canViewVerification($user)) {
            abort(403);
        }
    }

    public static function abortUnlessVerificationDownload(?User $user): void
    {
        if (! self::canDownloadVerification($user)) {
            abort(403);
        }
    }

    public static function abortUnlessCertificatesView(?User $user): void
    {
        if (! self::canViewCertificates($user)) {
            abort(403);
        }
    }

    public static function abortUnlessCertificatesDownload(?User $user): void
    {
        if (! self::canDownloadCertificates($user)) {
            abort(403);
        }
    }

    public static function abortUnlessSlaView(?User $user): void
    {
        if (! self::canViewSla($user)) {
            abort(403);
        }
    }

    public static function abortUnlessSlaDownload(?User $user): void
    {
        if (! self::canDownloadSla($user)) {
            abort(403);
        }
    }

    public static function abortUnlessAny(?User $user): void
    {
        if (! self::canViewAny($user)) {
            abort(403);
        }
    }

    /**
     * @return list<array{key: string, label: string, description: string, href: string}>
     */
    public static function indexCategories(?User $user): array
    {
        $categories = [];

        if (self::canViewFinance($user)) {
            $categories[] = [
                'key' => 'finance',
                'label' => 'Finance reports',
                'description' => 'Payments, invoices, and revenue for the selected period.',
                'href' => '/admin/reports/payments',
            ];
        }

        if (self::canViewVerification($user)) {
            $categories[] = [
                'key' => 'verification',
                'label' => 'Verification reports',
                'description' => 'Applications, qualifications, verifiers, and awarding institutions.',
                'href' => '/admin/reports/applications',
            ];
        }

        if (self::canViewCertificates($user)) {
            $categories[] = [
                'key' => 'certificates',
                'label' => 'Certificate reports',
                'description' => 'Certificates issued and verification outcomes.',
                'href' => '/admin/reports/certificates',
            ];
        }

        if (self::canViewSla($user)) {
            $categories[] = [
                'key' => 'sla',
                'label' => 'SLA reports',
                'description' => 'Turnaround times and SLA performance.',
                'href' => '/admin/reports/sla',
            ];
        }

        if (self::canViewLevel1Performance($user)) {
            $categories[] = [
                'key' => 'my_performance',
                'label' => 'My performance',
                'description' => 'Your Level 1 verification workload and throughput.',
                'href' => '/admin/reports/my-performance',
            ];
        }

        return $categories;
    }
}
