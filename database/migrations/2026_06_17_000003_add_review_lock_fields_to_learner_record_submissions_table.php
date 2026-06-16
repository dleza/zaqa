<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_record_submissions', function (Blueprint $table) {
            $table->foreignId('review_locked_by_user_id')
                ->nullable()
                ->after('review_notes')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('review_locked_at')
                ->nullable()
                ->after('review_locked_by_user_id');

            $table->index(['review_locked_by_user_id', 'review_locked_at'], 'lrs_review_lock_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learner_record_submissions', function (Blueprint $table) {
            $table->dropIndex('lrs_review_lock_idx');
            $table->dropConstrainedForeignId('review_locked_by_user_id');
            $table->dropColumn('review_locked_at');
        });
    }
};
