<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_balance_accounts', function (Blueprint $table) {
            $table->timestamp('low_balance_alert_sent_at')->nullable()->after('critical_balance_threshold');
            $table->timestamp('critical_balance_alert_sent_at')->nullable()->after('low_balance_alert_sent_at');
            $table->timestamp('zero_balance_alert_sent_at')->nullable()->after('critical_balance_alert_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_balance_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'low_balance_alert_sent_at',
                'critical_balance_alert_sent_at',
                'zero_balance_alert_sent_at',
            ]);
        });
    }
};
