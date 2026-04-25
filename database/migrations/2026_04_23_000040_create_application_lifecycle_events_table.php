<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();

            $table->string('event_type')->index(); // e.g. wizard, payment, submission, finance, review
            $table->string('event_code')->index(); // e.g. wizard.applicant_saved
            $table->string('stage')->index(); // e.g. draft, payment, submitted, review, decision, certificate, closed
            $table->string('status_snapshot')->nullable()->index(); // snapshot of application.current_status

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('actor_role')->nullable();

            $table->string('visibility')->default('both')->index(); // applicant|internal|both
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // Prevent noisy duplication for milestone-style codes (use service to append suffix for repeatable events).
            $table->unique(['application_id', 'event_code']);
            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_lifecycle_events');
    }
};

