<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->timestamp('institution_pull_lookup_dispatched_at')->nullable()->after('auto_verification_attempted_at');
            $table->timestamp('institution_pull_lookup_attempted_at')->nullable()->after('institution_pull_lookup_dispatched_at');
            $table->string('institution_pull_lookup_status')->nullable()->after('institution_pull_lookup_attempted_at');
            $table->text('institution_pull_lookup_last_error')->nullable()->after('institution_pull_lookup_status');

            $table->index('institution_pull_lookup_dispatched_at', 'idx_q_pull_dispatched');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropIndex('idx_q_pull_dispatched');
            $table->dropColumn([
                'institution_pull_lookup_dispatched_at',
                'institution_pull_lookup_attempted_at',
                'institution_pull_lookup_status',
                'institution_pull_lookup_last_error',
            ]);
        });
    }
};

