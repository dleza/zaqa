<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_record_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('learner_record_submission_batches')->nullOnDelete();
            $table->string('source_type')->index();
            $table->foreignId('source_institution_id')->nullable()->constrained('awarding_institutions')->nullOnDelete();
            $table->unsignedBigInteger('source_integration_id')->nullable();
            $table->foreign('source_integration_id', 'lrs_source_integration_fk')
                ->references('id')->on('institution_integrations')->nullOnDelete();
            $table->unsignedBigInteger('institution_api_client_id')->nullable();
            $table->foreign('institution_api_client_id', 'lrs_inst_api_client_fk')
                ->references('id')->on('institution_api_clients')->nullOnDelete();
            $table->unsignedBigInteger('institution_api_batch_id')->nullable();
            $table->foreign('institution_api_batch_id', 'lrs_inst_api_batch_fk')
                ->references('id')->on('institution_api_batches')->nullOnDelete();
            $table->string('source_reference')->nullable();
            $table->string('external_record_id')->nullable();
            $table->unsignedInteger('row_number')->nullable();

            $table->string('student_id')->nullable();
            $table->string('certificate_no')->nullable();
            $table->string('nrc_number')->nullable();
            $table->string('passport_no')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('other_names')->nullable();
            $table->string('gender')->nullable();

            $table->string('program_of_study')->nullable();
            $table->unsignedInteger('year_awarded')->nullable();
            $table->date('award_date')->nullable();
            $table->string('classification', 150)->nullable();
            $table->unsignedBigInteger('qualification_title_id')->nullable();
            $table->unsignedBigInteger('qualification_type_id')->nullable();
            $table->string('examination_number')->nullable();

            $table->string('nrc_normalized', 100)->nullable();
            $table->string('passport_normalized', 100)->nullable();
            $table->string('name_normalized', 191)->nullable();
            $table->string('student_id_normalized', 100)->nullable();
            $table->string('certificate_no_normalized', 120)->nullable();
            $table->string('qualification_title_normalized', 191)->nullable();
            $table->string('dedupe_hash', 64)->nullable()->index();

            $table->json('payload_json')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('validation_errors')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('duplicate_candidates')->nullable();
            $table->string('review_decision')->nullable();
            $table->foreignId('target_learner_record_id')->nullable()->constrained('learner_records')->nullOnDelete();
            $table->foreignId('approved_learner_record_id')->nullable()->constrained('learner_records')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'source_institution_id', 'created_at'], 'lrs_status_inst_created_idx');
            $table->index(['student_id_normalized', 'source_institution_id'], 'lrs_student_inst_idx');
            $table->index(['certificate_no_normalized', 'source_institution_id'], 'lrs_cert_inst_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_record_submissions');
    }
};
