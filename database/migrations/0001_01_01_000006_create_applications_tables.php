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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('application_number')->unique();

            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('applicant_type');

            $table->string('service_type'); // verification, evaluation
            $table->string('qualification_category'); // mirrors selected qualification type/category

            $table->string('current_status')->index();

            $table->boolean('is_foreign')->default(false)->index();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('awarding_body_id')->nullable()->constrained('awarding_bodies')->nullOnDelete();

            $table->foreignId('assigned_level1_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_level2_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('service_deadline_at')->nullable()->index();

            $table->timestamp('sent_back_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['applicant_user_id', 'created_at']);
            $table->index(['application_number', 'created_at']);
        });

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->json('metadata')->nullable();

            $table->index(['application_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_status_histories');
        Schema::dropIfExists('applications');
    }
};

