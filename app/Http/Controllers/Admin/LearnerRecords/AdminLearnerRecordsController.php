<?php

namespace App\Http\Controllers\Admin\LearnerRecords;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminLearnerRecordsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('learner_records.view'), 403);

        $q = trim((string) $request->query('q', ''));
        $countryId = $request->query('country_id');
        $institutionId = $request->query('awarding_institution_id');
        $yearAwarded = $request->query('year_awarded');

        $records = LearnerRecord::query()
            ->with(['awardingInstitution:id,name'])
            ->when($countryId, fn ($qq) => $qq->whereHas('awardingInstitution', fn ($ai) => $ai->where('country_id', (int) $countryId)))
            ->when($institutionId, fn ($qq) => $qq->where('awarding_institution_id', (int) $institutionId))
            ->when($yearAwarded, fn ($qq) => $qq->where('year_awarded', (int) $yearAwarded))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('student_id', 'like', "%{$q}%")
                    ->orWhere('certificate_no', 'like', "%{$q}%")
                    ->orWhere('nrc_number', 'like', "%{$q}%")
                    ->orWhere('passport_no', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('other_names', 'like', "%{$q}%")
                    ->orWhere('program_of_study', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LearnerRecord $r) => [
                'id' => $r->id,
                'awarding_institution' => $r->awardingInstitution ? ['id' => $r->awardingInstitution->id, 'name' => $r->awardingInstitution->name] : null,
                'institution_name_raw' => $r->institution_name_raw,
                'student_id' => $r->student_id,
                'certificate_no' => $r->certificate_no,
                'nrc_number' => $r->nrc_number,
                'passport_no' => $r->passport_no,
                'first_name' => $r->first_name,
                'last_name' => $r->last_name,
                'other_names' => $r->other_names,
                'gender' => $r->gender,
                'program_of_study' => $r->program_of_study,
                'year_awarded' => $r->year_awarded,
                'award_date' => optional($r->award_date)->format('Y-m-d'),
                'source_type' => $r->source_type?->value,
                'is_active' => (bool) $r->is_active,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ]);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])
            ->values();

        $institutions = AwardingInstitution::query()
            ->when($countryId, fn ($qq) => $qq->where('country_id', (int) $countryId))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name, 'is_active' => (bool) $i->is_active])
            ->values();

        return Inertia::render('Admin/LearnerRecords/Index', [
            'records' => $records,
            'countries' => $countries,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'country_id' => is_string($countryId) ? $countryId : null,
                'awarding_institution_id' => is_string($institutionId) ? $institutionId : null,
                'year_awarded' => is_string($yearAwarded) ? $yearAwarded : null,
            ],
            'can' => [
                'view' => (bool) $request->user()?->can('learner_records.view'),
                'import' => (bool) $request->user()?->can('learner_records.import'),
            ],
        ]);
    }

    public function show(Request $request, LearnerRecord $learnerRecord): Response
    {
        abort_unless($request->user()?->can('learner_records.view'), 403);

        $learnerRecord->loadMissing(['awardingInstitution:id,name', 'import:id,original_filename,status,created_at']);

        return Inertia::render('Admin/LearnerRecords/Show', [
            'record' => [
                'id' => $learnerRecord->id,
                'awarding_institution' => $learnerRecord->awardingInstitution ? ['id' => $learnerRecord->awardingInstitution->id, 'name' => $learnerRecord->awardingInstitution->name] : null,
                'import' => $learnerRecord->import ? [
                    'id' => $learnerRecord->import->id,
                    'original_filename' => $learnerRecord->import->original_filename,
                    'status' => $learnerRecord->import->status?->value,
                    'created_at' => optional($learnerRecord->import->created_at)->toIso8601String(),
                ] : null,
                'institution_name_raw' => $learnerRecord->institution_name_raw,
                'student_id' => $learnerRecord->student_id,
                'certificate_no' => $learnerRecord->certificate_no,
                'nrc_number' => $learnerRecord->nrc_number,
                'passport_no' => $learnerRecord->passport_no,
                'first_name' => $learnerRecord->first_name,
                'last_name' => $learnerRecord->last_name,
                'other_names' => $learnerRecord->other_names,
                'gender' => $learnerRecord->gender,
                'program_of_study' => $learnerRecord->program_of_study,
                'year_awarded' => $learnerRecord->year_awarded,
                'award_date' => optional($learnerRecord->award_date)->format('Y-m-d'),
                'source_type' => $learnerRecord->source_type?->value,
                'source_reference' => $learnerRecord->source_reference,
                'is_active' => (bool) $learnerRecord->is_active,
                'created_at' => optional($learnerRecord->created_at)->toIso8601String(),
                'updated_at' => optional($learnerRecord->updated_at)->toIso8601String(),
            ],
        ]);
    }
}
