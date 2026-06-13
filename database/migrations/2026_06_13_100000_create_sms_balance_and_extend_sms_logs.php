<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_balance_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedInteger('low_balance_threshold')->default(100);
            $table->unsignedInteger('critical_balance_threshold')->default(10);
            $table->timestamps();
        });

        Schema::create('sms_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_type'); // credit|debit
            $table->unsignedInteger('amount');
            $table->string('reason');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->foreignId('sms_log_id')->nullable()->constrained('sms_logs')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['adjustment_type', 'created_at']);
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('normalized_phone')->nullable()->after('phone_number');
            $table->unsignedSmallInteger('message_length')->nullable()->after('message_body');
            $table->string('skip_reason')->nullable()->after('status');
            $table->unsignedSmallInteger('http_status')->nullable()->after('provider_reference');
            $table->json('provider_response')->nullable()->after('http_status');
            $table->foreignId('balance_adjustment_id')->nullable()->after('provider_response');
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('balance_adjustment_id');

            $table->index(['status', 'created_at']);
            $table->index(['message_type', 'created_at']);
        });

        DB::table('sms_balance_accounts')->insert([
            'id' => 1,
            'balance' => 0,
            'low_balance_threshold' => 100,
            'critical_balance_threshold' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['message_type', 'created_at']);
            $table->dropColumn([
                'normalized_phone',
                'message_length',
                'skip_reason',
                'http_status',
                'provider_response',
                'balance_adjustment_id',
                'attempt_count',
            ]);
        });

        Schema::dropIfExists('sms_balance_adjustments');
        Schema::dropIfExists('sms_balance_accounts');
    }
};
