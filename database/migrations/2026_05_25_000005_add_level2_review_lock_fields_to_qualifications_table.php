<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('level2_review_locked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('level2_review_owner_id');

            $table->timestamp('level2_review_locked_at')
                ->nullable()
                ->after('level2_review_locked_by')
                ->index();

            $table->index(['level2_review_locked_by', 'level2_review_locked_at'], 'qual_l2_lock_idx');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropIndex('qual_l2_lock_idx');
            $table->dropIndex(['level2_review_locked_at']);
            $table->dropConstrainedForeignId('level2_review_locked_by');
            $table->dropColumn('level2_review_locked_at');
        });
    }
};

