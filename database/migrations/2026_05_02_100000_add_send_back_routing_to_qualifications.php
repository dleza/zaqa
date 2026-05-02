<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('send_back_by_user_id')
                ->nullable()
                ->after('returned_to_applicant_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('send_back_reopen_level', 16)->nullable()->after('send_back_by_user_id');
            $table->foreignId('level2_review_owner_id')
                ->nullable()
                ->after('send_back_reopen_level')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level2_review_owner_id');
            $table->dropColumn('send_back_reopen_level');
            $table->dropConstrainedForeignId('send_back_by_user_id');
        });
    }
};
