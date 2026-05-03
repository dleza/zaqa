<?php

namespace App\Domain\Reports;

use App\Models\QualificationCertificate;
use App\Support\Reports\SqlDialect;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class CertificatesIssuedReportService
{
    /**
     * @return array{
     *   summary: array<string, int>,
     *   by_month: array{labels: array<int, string>, values: array<int, int>},
     *   by_qualification_type: list<array{label: string, value: int}>,
     *   by_institution: list<array{name: string, count: int}>,
     *   by_status: array{labels: array<int, string>, values: array<int, int>}
     * }
     */
    public function dashboard(
        Carbon $from,
        Carbon $to,
        ?int $qualificationTypeId,
        ?int $awardingInstitutionId,
    ): array {
        return [
            'summary' => $this->summary($from, $to, $qualificationTypeId, $awardingInstitutionId),
            'by_month' => $this->byMonth($from, $to, $qualificationTypeId, $awardingInstitutionId),
            'by_qualification_type' => $this->byQualificationType($from, $to, $qualificationTypeId, $awardingInstitutionId),
            'by_institution' => $this->byInstitution($from, $to, $qualificationTypeId, $awardingInstitutionId),
            'by_status' => $this->byStatus($from, $to, $qualificationTypeId, $awardingInstitutionId),
        ];
    }

    private function base(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): Builder
    {
        $q = QualificationCertificate::query()
            ->whereBetween('qualification_certificates.issued_at', [$from, $to]);

        if ($qualificationTypeId || $awardingInstitutionId) {
            $q->join('qualifications as q', 'q.id', '=', 'qualification_certificates.qualification_id');
            if ($qualificationTypeId) {
                $q->where('q.qualification_type_id', $qualificationTypeId);
            }
            if ($awardingInstitutionId) {
                $q->where('q.awarding_institution_id', $awardingInstitutionId);
            }
            $q->select('qualification_certificates.*');
        }

        return $q;
    }

    /**
     * @return array<string, int>
     */
    private function summary(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): array
    {
        $b = $this->base($from, $to, $qualificationTypeId, $awardingInstitutionId);

        return [
            'total' => (clone $b)->count(),
            'issued' => (clone $b)->where('qualification_certificates.status', QualificationCertificate::STATUS_ISSUED)->count(),
            'reissued' => (clone $b)->where('qualification_certificates.status', QualificationCertificate::STATUS_REISSUED)->count(),
            'revoked' => (clone $b)->where('qualification_certificates.status', QualificationCertificate::STATUS_REVOKED)->count(),
        ];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function byMonth(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): array
    {
        $month = SqlDialect::monthBucket('qualification_certificates.issued_at');
        $rows = $this->base($from, $to, $qualificationTypeId, $awardingInstitutionId)
            ->selectRaw("{$month} as ym, count(*) as c")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r->ym;
            $values[] = (int) $r->c;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function byQualificationType(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): array
    {
        $rows = QualificationCertificate::query()
            ->join('qualifications as q', 'q.id', '=', 'qualification_certificates.qualification_id')
            ->leftJoin('qualification_types as qt', 'qt.id', '=', 'q.qualification_type_id')
            ->whereBetween('qualification_certificates.issued_at', [$from, $to])
            ->when($qualificationTypeId, fn ($q) => $q->where('q.qualification_type_id', $qualificationTypeId))
            ->when($awardingInstitutionId, fn ($q) => $q->where('q.awarding_institution_id', $awardingInstitutionId))
            ->selectRaw('coalesce(qt.name, q.qualification_type) as label, count(*) as c')
            ->groupByRaw('coalesce(qt.name, q.qualification_type)')
            ->orderByDesc('c')
            ->limit(12)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['label' => (string) $r->label, 'value' => (int) $r->c];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private function byInstitution(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): array
    {
        $rows = QualificationCertificate::query()
            ->join('qualifications as q', 'q.id', '=', 'qualification_certificates.qualification_id')
            ->leftJoin('awarding_institutions as ai', 'ai.id', '=', 'q.awarding_institution_id')
            ->whereBetween('qualification_certificates.issued_at', [$from, $to])
            ->when($qualificationTypeId, fn ($q) => $q->where('q.qualification_type_id', $qualificationTypeId))
            ->when($awardingInstitutionId, fn ($q) => $q->where('q.awarding_institution_id', $awardingInstitutionId))
            ->selectRaw('coalesce(ai.name, q.awarding_institution_name) as name, count(*) as c')
            ->groupByRaw('coalesce(ai.name, q.awarding_institution_name)')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r->name, 'count' => (int) $r->c];
        }

        return $out;
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function byStatus(Carbon $from, Carbon $to, ?int $qualificationTypeId, ?int $awardingInstitutionId): array
    {
        $rows = $this->base($from, $to, $qualificationTypeId, $awardingInstitutionId)
            ->selectRaw('qualification_certificates.status, count(*) as c')
            ->groupBy('qualification_certificates.status')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r->status;
            $values[] = (int) $r->c;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return LengthAwarePaginator<int, QualificationCertificate>
     */
    public function paginateDetail(
        Carbon $from,
        Carbon $to,
        ?int $qualificationTypeId,
        ?int $awardingInstitutionId,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return QualificationCertificate::query()
            ->with(['qualification.awardingInstitution:id,name', 'qualification.qualificationTypeMaster:id,name'])
            ->whereBetween('issued_at', [$from, $to])
            ->when($qualificationTypeId || $awardingInstitutionId, function ($q) use ($qualificationTypeId, $awardingInstitutionId) {
                $q->whereHas('qualification', function ($qq) use ($qualificationTypeId, $awardingInstitutionId) {
                    if ($qualificationTypeId) {
                        $qq->where('qualification_type_id', $qualificationTypeId);
                    }
                    if ($awardingInstitutionId) {
                        $qq->where('awarding_institution_id', $awardingInstitutionId);
                    }
                });
            })
            ->orderByDesc('issued_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(
        Carbon $from,
        Carbon $to,
        ?int $qualificationTypeId,
        ?int $awardingInstitutionId,
    ): \Generator {
        yield ['id', 'certificate_number', 'qualification_id', 'application_id', 'issued_at', 'status'];

        $q = QualificationCertificate::query()
            ->whereBetween('issued_at', [$from, $to])
            ->when($qualificationTypeId || $awardingInstitutionId, function ($q) use ($qualificationTypeId, $awardingInstitutionId) {
                $q->whereHas('qualification', function ($qq) use ($qualificationTypeId, $awardingInstitutionId) {
                    if ($qualificationTypeId) {
                        $qq->where('qualification_type_id', $qualificationTypeId);
                    }
                    if ($awardingInstitutionId) {
                        $qq->where('awarding_institution_id', $awardingInstitutionId);
                    }
                });
            })
            ->orderBy('id');

        foreach ($q->cursor() as $c) {
            /** @var QualificationCertificate $c */
            yield [
                $c->id,
                $c->certificate_number,
                $c->qualification_id,
                $c->application_id,
                $c->issued_at?->toIso8601String(),
                $c->status,
            ];
        }
    }
}
