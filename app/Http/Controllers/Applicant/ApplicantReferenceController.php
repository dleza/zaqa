<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\LearnerRecord;
use App\Support\CountryIso;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantReferenceController extends Controller
{
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
     * Title options sourced from internal Learner Records (non-sensitive).
     */
    public function qualificationTitles(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');

        $normalized = $query !== '' ? LearnerRecordNormalizer::normalizeProgramTitle($query) : null;

        $titles = LearnerRecord::query()
            ->where('is_active', true)
            ->whereNotNull('program_of_study')
            ->when(is_string($institutionId) && $institutionId !== '', fn ($q2) => $q2->where('awarding_institution_id', (int) $institutionId))
            ->when($normalized, fn ($q2) => $q2->where('qualification_title_normalized', 'like', '%'.$normalized.'%'))
            ->when(! $normalized && $query !== '', fn ($q2) => $q2->where('program_of_study', 'like', '%'.$query.'%'))
            ->select('program_of_study')
            ->distinct()
            ->orderBy('program_of_study')
            ->limit(30)
            ->get()
            ->map(fn ($r) => ['title' => (string) $r->program_of_study])
            ->values();

        return JsonResource::collection($titles);
    }
}
