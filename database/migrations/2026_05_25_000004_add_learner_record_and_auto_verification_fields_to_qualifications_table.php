<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('learner_record_id')
                ->nullable()
                ->after('application_id')
                ->constrained('learner_records')
                ->nullOnDelete();

            $table->string('auto_verification_status')->nullable()->after('returned_to_applicant_at')->index();
            $table->timestamp('auto_verified_at')->nullable()->after('auto_verification_status')->index();
            $table->unsignedSmallInteger('auto_verification_confidence')->nullable()->after('auto_verified_at')->index();
            $table->string('auto_verification_failure_reason')->nullable()->after('auto_verification_confidence');
            $table->json('auto_verification_match_summary')->nullable()->after('auto_verification_failure_reason');
            $table->string('verification_source')->nullable()->after('auto_verification_match_summary')->index();

            $table->string('applicant_entered_qualification_title')->nullable()->after('title_of_qualification');
            $table->string('verified_qualification_title')->nullable()->after('applicant_entered_qualification_title');
            $table->string('qualification_title_source')->nullable()->after('verified_qualification_title')->index();

            $table->index(['learner_record_id']);
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropIndex(['learner_record_id']);

            $table->dropIndex(['auto_verification_status']);
            $table->dropIndex(['auto_verified_at']);
            $table->dropIndex(['auto_verification_confidence']);
            $table->dropIndex(['verification_source']);
            $table->dropIndex(['qualification_title_source']);

            $table->dropConstrainedForeignId('learner_record_id');

            $table->dropColumn([
                'auto_verification_status',
                'auto_verified_at',
                'auto_verification_confidence',
                'auto_verification_failure_reason',
                'auto_verification_match_summary',
                'verification_source',
                'applicant_entered_qualification_title',
                'verified_qualification_title',
                'qualification_title_source',
            ]);
        });
    }
};

