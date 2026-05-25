<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('awarding_institution_id')->nullable()->constrained('awarding_institutions')->nullOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('learner_record_imports')->nullOnDelete();

            $table->string('institution_name_raw')->nullable();

            $table->string('student_id')->nullable();
            $table->string('certificate_no')->nullable();

            $table->string('nrc_number')->nullable();
            $table->string('passport_no')->nullable();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('other_names')->nullable();
            $table->string('gender')->nullable();

            $table->string('program_of_study')->nullable();

            $table->unsignedInteger('year_awarded')->nullable()->index();
            $table->date('award_date')->nullable();

            $table->string('source_type')->index();
            $table->string('source_reference')->nullable();

            $table->json('raw_payload')->nullable();

            // Normalized fields for matching/deduping (do not lose raw fields above).
            $table->string('nrc_normalized', 100)->nullable()->index();
            $table->string('passport_normalized', 100)->nullable()->index();
            $table->string('name_normalized', 191)->nullable()->index();
            $table->string('student_id_normalized', 100)->nullable();
            $table->string('certificate_no_normalized', 120)->nullable();
            $table->string('qualification_title_normalized', 191)->nullable()->index();

            $table->string('dedupe_hash', 64)->nullable()->index();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // Explicit short names: MySQL limits identifiers (including index names) to 64 characters.
            $table->index(['awarding_institution_id', 'student_id_normalized'], 'lr_inst_student_idx');
            $table->index(['awarding_institution_id', 'certificate_no_normalized'], 'lr_inst_cert_idx');
            $table->index(['awarding_institution_id', 'year_awarded'], 'lr_inst_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_records');
    }
};
