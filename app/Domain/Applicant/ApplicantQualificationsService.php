<?php

namespace App\Domain\Applicant;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ApplicantQualificationsService
{
    public const FILTER_TOTAL = 'total';

    public const FILTER_DRAFT = 'draft';

    public const FILTER_PROCESSING = 'processing';

    public const FILTER_SENT_BACK = 'sent_back';

    public const FILTER_COMPLETED = 'completed';

    /**
     * @return array<string, int>
     */
    public function countsFor(User $user): array
    {
        $userId = (int) $user->id;

        return [
            'total' => $this->applyFilter($this->baseQuery($userId), self::FILTER_TOTAL)->count(),
            'draft' => $this->applyFilter($this->baseQuery($userId), self::FILTER_DRAFT)->count(),
            'processing' => $this->applyFilter($this->baseQuery($userId), self::FILTER_PROCESSING)->count(),
            'sent_back' => $this->applyFilter($this->baseQuery($userId), self::FILTER_SENT_BACK)->count(),
            'completed' => $this->applyFilter($this->baseQuery($userId), self::FILTER_COMPLETED)->count(),
        ];
    }

    /**
     * @return list<array{
     *   id:int,
     *   application_id:int,
     *   application_number:string|null,
     *   title_of_qualification:string|null,
     *   verification_reference_number:string|null,
     *   verification_state:string|null,
     *   status_label:string,
     *   href:string,
     *   updated_at:string|null
     * }>
     */
    public function listFor(User $user, string $filter = self::FILTER_TOTAL): array
    {
        $userId = (int) $user->id;

        return $this->applyFilter($this->baseQuery($userId), $filter)
            ->with(['application:id,application_number,current_status'])
            ->latest('qualifications.updated_at')
            ->latest('qualifications.id')
            ->get()
            ->map(fn (Qualification $qualification) => $this->serializeListItem($qualification))
            ->values()
            ->all();
    }

    public function filterLabel(string $filter): string
    {
        return match ($this->normalizeFilter($filter)) {
            self::FILTER_DRAFT => 'Draft qualifications',
            self::FILTER_PROCESSING => 'Processing qualifications',
            self::FILTER_SENT_BACK => 'Sent back qualifications',
            self::FILTER_COMPLETED => 'Completed qualifications',
            default => 'All submitted qualifications',
        };
    }

    public function normalizeFilter(string $filter): string
    {
        return in_array($filter, [
            self::FILTER_TOTAL,
            self::FILTER_DRAFT,
            self::FILTER_PROCESSING,
            self::FILTER_SENT_BACK,
            self::FILTER_COMPLETED,
        ], true) ? $filter : self::FILTER_TOTAL;
    }

    /**
     * @param  Builder<Qualification>  $query
     * @return Builder<Qualification>
     */
    private function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($this->normalizeFilter($filter)) {
            self::FILTER_DRAFT => $query->whereHas(
                'application',
                fn (Builder $q) => $q->where('current_status', ApplicationStatus::Draft)
            ),
            self::FILTER_PROCESSING => $query
                ->whereHas('application', fn (Builder $q) => $q->whereNotIn('current_status', $this->inactiveApplicationStatuses()))
                ->where(function (Builder $q) {
                    $q->whereNull('verification_state')
                        ->orWhereNotIn('verification_state', $this->terminalVerificationStates());
                }),
            self::FILTER_SENT_BACK => $query->where('verification_state', VerificationState::ReturnedToApplicant),
            self::FILTER_COMPLETED => $query->whereIn('verification_state', [
                VerificationState::ApprovedForCertificate->value,
                VerificationState::Rejected->value,
                VerificationState::CertificateIssued->value,
                VerificationState::Closed->value,
            ]),
            default => $query->whereHas(
                'application',
                fn (Builder $q) => $q->where('current_status', '!=', ApplicationStatus::Draft)
            ),
        };
    }

    /**
     * @return Builder<Qualification>
     */
    private function baseQuery(int $userId): Builder
    {
        return Qualification::query()
            ->whereHas('application', fn (Builder $q) => $q->where('applicant_user_id', $userId));
    }

    /**
     * @return list<string>
     */
    private function terminalVerificationStates(): array
    {
        return [
            VerificationState::ApprovedForCertificate->value,
            VerificationState::Rejected->value,
            VerificationState::CertificateIssued->value,
            VerificationState::Closed->value,
            VerificationState::ReturnedToApplicant->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function inactiveApplicationStatuses(): array
    {
        return [
            ApplicationStatus::Draft->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::CertificateReady->value,
            ApplicationStatus::Completed->value,
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   application_id:int,
     *   application_number:string|null,
     *   title_of_qualification:string|null,
     *   verification_reference_number:string|null,
     *   verification_state:string|null,
     *   status_label:string,
     *   href:string,
     *   updated_at:string|null
     * }
     */
    private function serializeListItem(Qualification $qualification): array
    {
        /** @var Application $application */
        $application = $qualification->application;

        return [
            'id' => (int) $qualification->id,
            'application_id' => (int) $qualification->application_id,
            'application_number' => $application->application_number,
            'title_of_qualification' => $qualification->title_of_qualification,
            'verification_reference_number' => $qualification->verification_reference_number,
            'verification_state' => $qualification->verification_state?->value ?? (is_string($qualification->verification_state) ? $qualification->verification_state : null),
            'status_label' => $this->applicantStatusLabel($qualification, $application),
            'href' => $this->primaryHref($qualification, $application),
            'updated_at' => optional($qualification->updated_at)?->toIso8601String(),
        ];
    }

    public function applicantStatusLabel(Qualification $qualification, Application $application): string
    {
        if (($application->current_status?->value ?? (string) $application->current_status) === ApplicationStatus::Draft->value) {
            return 'Draft';
        }

        $state = $qualification->verification_state;

        return match ($state) {
            VerificationState::ReturnedToApplicant => 'Sent back',
            VerificationState::ApprovedForCertificate => 'Approved',
            VerificationState::Rejected => 'Rejected',
            VerificationState::CertificateIssued => 'Certificate issued',
            VerificationState::Closed => 'Closed',
            VerificationState::AwaitingAutoVerification,
            VerificationState::AwaitingAssignment,
            VerificationState::AssignedToLevel1,
            VerificationState::UnderLevel1Review,
            VerificationState::UnderLevel2Review,
            VerificationState::AutoVerifiedPendingLevel2 => 'Processing',
            default => 'Processing',
        };
    }

    private function primaryHref(Qualification $qualification, Application $application): string
    {
        $appStatus = $application->current_status?->value ?? (string) $application->current_status;

        if ($qualification->verification_state === VerificationState::ReturnedToApplicant) {
            return route('applicant.applications.qualifications.amend', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]);
        }

        if ($appStatus === ApplicationStatus::Draft->value) {
            return route('applicant.applications.edit', $application);
        }

        return route('applicant.applications.track', $application);
    }
}
