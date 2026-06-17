<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->boolean('level1_recommended_for_award')->nullable()->after('reviewer_notes');
            $table->text('level1_accreditation_statement')->nullable()->after('level1_recommended_for_award');
            $table->foreignId('level1_review_completed_by_user_id')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level1_review_completed_by_user_id');
            $table->dropColumn([
                'level1_recommended_for_award',
                'level1_accreditation_statement',
            ]);
        });
    }
};
