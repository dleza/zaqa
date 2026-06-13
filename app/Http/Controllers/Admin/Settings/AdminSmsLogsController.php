<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Notifications\Sms\SmsLogAdminPresenter;
use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSmsLogsController extends Controller
{
    public function __construct(
        private readonly SmsLogAdminPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sms.logs.view'), 403);

        $status = trim((string) $request->query('status', ''));
        $messageType = trim((string) $request->query('message_type', ''));
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        $logs = SmsLog::query()
            ->with(['user:id,name', 'application:id,application_number'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($messageType !== '', fn ($q) => $q->where('message_type', 'like', "%{$messageType}%"))
            ->when($from !== '', fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SmsLog $log) => $this->presenter->presentSummary($log));

        return Inertia::render('Admin/Settings/Sms/Logs/Index', [
            'logs' => $logs,
            'filters' => [
                'status' => $status !== '' ? $status : null,
                'message_type' => $messageType !== '' ? $messageType : null,
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
            ],
        ]);
    }

    public function show(Request $request, SmsLog $smsLog): Response
    {
        abort_unless($request->user()?->can('sms.logs.view'), 403);

        $smsLog->load(['user:id,name,email', 'application:id,application_number', 'balanceAdjustment']);

        return Inertia::render('Admin/Settings/Sms/Logs/Show', [
            'log' => $this->presenter->presentDetail($smsLog),
            'index_url' => route('admin.settings.sms.logs.index'),
        ]);
    }
}
