<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\InstitutionPullLookupLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminInstitutionPullLookupLogsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('institution_api.logs.view'), 403);

        $q = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');
        $status = trim((string) $request->query('status', ''));

        $logs = InstitutionPullLookupLog::query()
            ->with(['awardingInstitution:id,name', 'qualification:id,application_id,title_of_qualification,student_number,certificate_number'])
            ->when($institutionId, fn ($qq) => $qq->where('awarding_institution_id', (int) $institutionId))
            ->when($status !== '', fn ($qq) => $qq->where('status', $status))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('endpoint', 'like', "%{$q}%")
                    ->orWhere('correlation_id', 'like', "%{$q}%")
                    ->orWhereHas('qualification', fn ($qual) => $qual->where('student_number', 'like', "%{$q}%")->orWhere('certificate_number', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InstitutionPullLookupLog $l) => [
                'id' => (int) $l->id,
                'created_at' => optional($l->created_at)->toIso8601String(),
                'awarding_institution' => $l->awardingInstitution ? ['id' => $l->awardingInstitution->id, 'name' => $l->awardingInstitution->name] : null,
                'qualification_id' => (int) $l->qualification_id,
                'endpoint' => $l->endpoint,
                'method' => $l->method,
                'correlation_id' => $l->correlation_id,
                'status' => $l->status,
                'status_code' => $l->status_code,
                'latency_ms' => $l->latency_ms,
                'request_payload' => $l->request_payload,
                'response_payload' => $l->response_payload,
                'error_message' => $l->error_message,
            ]);

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/Integrations/InstitutionPullLookupLogs/Index', [
            'logs' => $logs,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'awarding_institution_id' => is_string($institutionId) ? $institutionId : null,
                'status' => $status !== '' ? $status : null,
            ],
        ]);
    }
}

