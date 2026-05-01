<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('verification_state')->nullable()->index()->after('current_status');
        });

        // Assignment history is tracked per qualification item (verification task).
        Schema::create('qualification_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('assigned_at')->useCurrent()->index();
            $table->timestamp('unassigned_at')->nullable()->index();
            $table->timestamps();

            $table->index(['qualification_id', 'unassigned_at']);
            $table->index(['assigned_to_user_id', 'unassigned_at'], 'qa_assignee_active_idx');
        });

        Schema::create('application_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index(); // send_back, review_note, decision_reason
            $table->string('visibility')->default('internal')->index(); // internal|applicant_visible
            $table->text('body');
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_comments');
        Schema::dropIfExists('qualification_assignments');

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('verification_state');
        });
    }
};

