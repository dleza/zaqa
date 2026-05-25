<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_record_match_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $table->foreignId('learner_record_id')->nullable()->constrained('learner_records')->nullOnDelete();

            $table->string('status')->index();
            $table->unsignedSmallInteger('confidence')->nullable()->index();
            $table->string('source')->index();

            $table->json('matched_fields')->nullable();
            $table->json('candidate_record_ids')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['qualification_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_record_match_attempts');
    }
};

