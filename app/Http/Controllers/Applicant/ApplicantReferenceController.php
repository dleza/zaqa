<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
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
            ->where('is_active', true)
            ->when($countryId, fn ($q2) => $q2->where('country_id', (int) $countryId))
            ->when($query !== '', fn ($q2) => $q2->where('name', 'like', '%'.$query.'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'country_id', 'name']);

        return JsonResource::collection($institutions);
    }
}

