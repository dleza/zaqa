<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSmsLogsController extends Controller
{
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
            ->through(fn (SmsLog $log) => [
                'id' => $log->id,
                'status' => $log->status,
                'skip_reason' => $log->skip_reason,
                'message_type' => $log->message_type,
                'phone_number' => $this->maskPhone((string) $log->phone_number),
                'provider' => $log->provider,
                'http_status' => $log->http_status,
                'message_length' => $log->message_length,
                'application' => $log->application ? [
                    'id' => $log->application->id,
                    'application_number' => $log->application->application_number,
                ] : null,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'created_at' => optional($log->created_at)->toIso8601String(),
                'sent_at' => optional($log->sent_at)->toIso8601String(),
                'show_url' => route('admin.settings.sms.logs.show', ['smsLog' => $log->id]),
            ]);

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
            'log' => [
                'id' => $smsLog->id,
                'status' => $smsLog->status,
                'skip_reason' => $smsLog->skip_reason,
                'message_type' => $smsLog->message_type,
                'phone_number' => $smsLog->phone_number,
                'normalized_phone' => $smsLog->normalized_phone,
                'message_body' => $smsLog->message_body,
                'message_length' => $smsLog->message_length,
                'provider' => $smsLog->provider,
                'provider_reference' => $smsLog->provider_reference,
                'http_status' => $smsLog->http_status,
                'provider_response' => $smsLog->provider_response,
                'attempt_count' => $smsLog->attempt_count,
                'application' => $smsLog->application ? [
                    'id' => $smsLog->application->id,
                    'application_number' => $smsLog->application->application_number,
                ] : null,
                'user' => $smsLog->user ? [
                    'id' => $smsLog->user->id,
                    'name' => $smsLog->user->name,
                    'email' => $smsLog->user->email,
                ] : null,
                'balance_adjustment' => $smsLog->balanceAdjustment ? [
                    'id' => $smsLog->balanceAdjustment->id,
                    'balance_before' => $smsLog->balanceAdjustment->balance_before,
                    'balance_after' => $smsLog->balanceAdjustment->balance_after,
                ] : null,
                'created_at' => optional($smsLog->created_at)->toIso8601String(),
                'sent_at' => optional($smsLog->sent_at)->toIso8601String(),
            ],
            'index_url' => route('admin.settings.sms.logs.index'),
        ]);
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;
        if (strlen($digits) <= 4) {
            return $phone;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
