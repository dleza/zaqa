<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Settings\QualificationTitleQueryService;
use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Support\CountryIso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantReferenceController extends Controller
{
    public function __construct(
        private readonly QualificationTitleQueryService $qualificationTitles,
    ) {}

    public function awardingInstitutions(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $countryId = $request->query('country_id');

        $institutions = AwardingInstitution::query()
            ->with('country:id,name,iso_code')
            ->where('is_active', true)
            ->when($countryId, fn ($q2) => $q2->where('country_id', (int) $countryId))
            ->when($query !== '', fn ($q2) => $q2->where('name', 'like', '%'.$query.'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'country_id', 'name', 'consent_form_path'])
            ->map(function (AwardingInstitution $i) {
                $iso = strtoupper((string) ($i->country?->iso_code ?? ''));
                $isZambian = CountryIso::isZambia($iso);

                return [
                    'id' => $i->id,
                    'country' => $i->country ? ['id' => $i->country->id, 'name' => $i->country->name, 'iso_code' => $i->country->iso_code] : null,
                    'name' => $i->name,
                    'is_zambian' => $isZambian,
                    'is_foreign' => ! $isZambian,
                    'has_consent_form' => (bool) $i->has_consent_form,
                    'consent_form_url' => $i->consent_form_url,
                ];
            })
            ->values();

        return JsonResource::collection($institutions);
    }

    /**
     * Title options from admin-managed qualification_titles master data.
     */
    public function qualificationTitles(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');
        $qualificationTypeId = $request->query('qualification_type_id');
        $limit = (int) $request->query('limit', 30);

        $institutionIdInt = is_numeric($institutionId) ? (int) $institutionId : null;

        $titles = $this->qualificationTitles->searchForApplicant(
            awardingInstitutionId: $institutionIdInt,
            search: $query,
            qualificationTypeId: is_numeric($qualificationTypeId) ? (int) $qualificationTypeId : null,
            limit: $limit,
        )->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->name,
            'qualification_type_id' => $t->qualification_type_id,
        ])->values();

        return JsonResource::collection($titles);
    }
}
