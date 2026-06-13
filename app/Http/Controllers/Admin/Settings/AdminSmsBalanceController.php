<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Notifications\Sms\SmsBalanceService;
use App\Domain\Notifications\Sms\SmsProviderManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\AddSmsBalanceRequest;
use App\Models\SmsBalanceAdjustment;
use App\Models\SmsLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSmsBalanceController extends Controller
{
    public function index(Request $request, SmsBalanceService $balance): Response
    {
        abort_unless($request->user()?->can('sms.balance.view'), 403);

        $account = \App\Models\SmsBalanceAccount::currentReadOnly();
        $stats = $balance->todayStatistics();

        $adjustments = SmsBalanceAdjustment::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (SmsBalanceAdjustment $row) => [
                'id' => $row->id,
                'adjustment_type' => $row->adjustment_type,
                'amount' => $row->amount,
                'reason' => $row->reason,
                'balance_before' => $row->balance_before,
                'balance_after' => $row->balance_after,
                'actor' => $row->actor ? ['id' => $row->actor->id, 'name' => $row->actor->name] : null,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])
            ->values();

        $recentLogs = SmsLog::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'message_type', 'status', 'created_at'])
            ->map(fn (SmsLog $log) => [
                'id' => $log->id,
                'message_type' => $log->message_type,
                'status' => $log->status,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Admin/Settings/Sms/Balance', [
            'account' => [
                'balance' => (int) $account->balance,
                'low_balance_threshold' => (int) $account->low_balance_threshold,
                'critical_balance_threshold' => (int) $account->critical_balance_threshold,
                'alert_level' => $balance->alertLevel(),
                'last_low_alert_at' => optional($account->low_balance_alert_sent_at)?->toIso8601String(),
                'last_critical_alert_at' => optional($account->critical_balance_alert_sent_at)?->toIso8601String(),
                'last_zero_alert_at' => optional($account->zero_balance_alert_sent_at)?->toIso8601String(),
            ],
            'statistics' => $stats,
            'adjustments' => $adjustments,
            'recent_logs' => $recentLogs,
            'config' => [
                'enabled' => (bool) config('sms.enabled'),
                'provider' => (string) config('sms.provider'),
            ],
            'can' => [
                'manage' => (bool) $request->user()?->can('sms.balance.manage'),
                'test_connection' => (bool) $request->user()?->can('sms.balance.manage'),
            ],
        ]);
    }

    public function store(AddSmsBalanceRequest $request, SmsBalanceService $balance): RedirectResponse
    {
        $data = $request->validated();

        $balance->credit(
            amount: (int) $data['amount'],
            reason: (string) $data['reason'],
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.settings.sms.balance.index')
            ->with('success', 'SMS balance updated.');
    }

    public function testConnection(Request $request, SmsProviderManager $providers): RedirectResponse
    {
        abort_unless($request->user()?->can('sms.balance.manage'), 403);

        $result = $providers->resolve()->healthCheck();

        return redirect()
            ->route('admin.settings.sms.balance.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message'])
            ->with('sms_health_details', $result['details']);
    }
}
