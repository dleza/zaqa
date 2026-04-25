<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();

            $table->string('awarding_institution_name');
            $table->string('qualification_holder_name');

            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('country_name_other')->nullable();

            $table->foreignId('awarding_body_id')->nullable()->constrained('awarding_bodies')->nullOnDelete();
            $table->string('awarding_body_name_other')->nullable();

            $table->string('nrc_passport_number');
            $table->string('certificate_number')->nullable();
            $table->string('student_number')->nullable();
            $table->string('examination_number')->nullable();

            $table->string('title_of_qualification');
            $table->date('award_date');

            $table->string('qualification_type');

            $table->boolean('transcript_required')->default(false);
            $table->text('transcript_reason')->nullable();

            $table->text('notes')->nullable();
            $table->json('raw_subject_results')->nullable();

            $table->timestamps();
        });

        Schema::create('qualification_subject_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $table->string('subject_name');
            $table->string('grade');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['qualification_id', 'display_order'],
                            'qsr_qualification_display_idx'); 
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qualification_subject_results');
        Schema::dropIfExists('qualifications');
    }
};

