<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\InstitutionIntegrationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminInstitutionIntegrationLogsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('institution_api.logs.view'), 403);

        $q = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');
        $status = trim((string) $request->query('status', ''));
        $endpoint = trim((string) $request->query('endpoint', ''));
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        $logs = InstitutionIntegrationLog::query()
            ->with(['awardingInstitution:id,name', 'client:id,name'])
            ->when($institutionId, fn ($qq) => $qq->where('awarding_institution_id', (int) $institutionId))
            ->when($status !== '', fn ($qq) => $qq->where('status', $status))
            ->when($endpoint !== '', fn ($qq) => $qq->where('endpoint', 'like', "%{$endpoint}%"))
            ->when($from !== '', fn ($qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($qq) => $qq->whereDate('created_at', '<=', $to))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('correlation_id', 'like', "%{$q}%")
                    ->orWhere('endpoint', 'like', "%{$q}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InstitutionIntegrationLog $l) => [
                'id' => (int) $l->id,
                'created_at' => optional($l->created_at)->toIso8601String(),
                'awarding_institution' => $l->awardingInstitution ? ['id' => $l->awardingInstitution->id, 'name' => $l->awardingInstitution->name] : null,
                'client' => $l->client ? ['id' => $l->client->id, 'name' => $l->client->name] : null,
                'endpoint' => $l->endpoint,
                'method' => $l->method,
                'correlation_id' => $l->correlation_id,
                'status' => $l->status,
                'status_code' => $l->status_code,
                'latency_ms' => $l->latency_ms,
                'ip_address' => $l->ip_address,
                'request_payload' => $l->request_payload,
                'response_payload' => $l->response_payload,
            ]);

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/Integrations/InstitutionApiLogs/Index', [
            'logs' => $logs,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'awarding_institution_id' => is_string($institutionId) ? $institutionId : null,
                'status' => $status !== '' ? $status : null,
                'endpoint' => $endpoint !== '' ? $endpoint : null,
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
            ],
        ]);
    }
}

