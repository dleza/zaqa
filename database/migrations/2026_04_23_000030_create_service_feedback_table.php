<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating_value'); // 1..5
            $table->string('rating_label')->nullable();
            $table->text('feedback_text')->nullable();

            $table->string('source')->default('applicant_submission_flow')->index();
            $table->string('source_step')->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('application_id');
            $table->index(['applicant_user_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_feedback');
    }
};

