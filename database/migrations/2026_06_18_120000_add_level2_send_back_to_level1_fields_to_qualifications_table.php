<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            if (! Schema::hasColumn('qualifications', 'returned_to_level1_at')) {
                $table->timestamp('returned_to_level1_at')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('qualifications', 'returned_to_level1_by_user_id')) {
                $table->foreignId('returned_to_level1_by_user_id')
                    ->nullable()
                    ->after('returned_to_level1_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('qualifications', 'returned_to_level1_to_user_id')) {
                $table->foreignId('returned_to_level1_to_user_id')
                    ->nullable()
                    ->after('returned_to_level1_by_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('qualifications', 'level2_return_target_user_id')) {
                $table->foreignId('level2_return_target_user_id')
                    ->nullable()
                    ->after('returned_to_level1_to_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('qualifications', 'level1_correction_cycle')) {
                $table->unsignedInteger('level1_correction_cycle')->default(0)->after('level2_return_target_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            if (Schema::hasColumn('qualifications', 'level2_return_target_user_id')) {
                $table->dropConstrainedForeignId('level2_return_target_user_id');
            }
            if (Schema::hasColumn('qualifications', 'returned_to_level1_to_user_id')) {
                $table->dropConstrainedForeignId('returned_to_level1_to_user_id');
            }
            if (Schema::hasColumn('qualifications', 'returned_to_level1_by_user_id')) {
                $table->dropConstrainedForeignId('returned_to_level1_by_user_id');
            }
            if (Schema::hasColumn('qualifications', 'returned_to_level1_at')) {
                $table->dropColumn('returned_to_level1_at');
            }
            if (Schema::hasColumn('qualifications', 'level1_correction_cycle')) {
                $table->dropColumn('level1_correction_cycle');
            }
        });
    }
};
