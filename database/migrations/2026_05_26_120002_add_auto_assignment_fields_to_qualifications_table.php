<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('verification_assignment_category_id')
                ->nullable()
                ->after('assigned_verifier_id')
                ->constrained('verification_assignment_categories')
                ->nullOnDelete();

            $table->string('assignment_source')->nullable()->after('verification_assignment_category_id')->index();
            $table->text('assignment_failure_reason')->nullable()->after('assignment_source');
            $table->timestamp('auto_assigned_at')->nullable()->after('assignment_failure_reason')->index();

            $table->index(['verification_assignment_category_id', 'assigned_verifier_id'], 'q_assign_cat_assignee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropIndex('q_assign_cat_assignee_idx');
            $table->dropConstrainedForeignId('verification_assignment_category_id');
            $table->dropColumn(['assignment_source', 'assignment_failure_reason', 'auto_assigned_at']);
        });
    }
};

