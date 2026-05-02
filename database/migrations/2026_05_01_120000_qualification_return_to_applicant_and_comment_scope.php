<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->timestamp('returned_to_applicant_at')->nullable()->after('reviewed_at')->index();
        });

        Schema::table('application_comments', function (Blueprint $table) {
            $table->foreignId('qualification_id')
                ->nullable()
                ->after('application_id')
                ->constrained('qualifications')
                ->nullOnDelete();
            $table->index(['application_id', 'qualification_id']);
        });
    }

    public function down(): void
    {
        Schema::table('application_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualification_id');
        });

        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropColumn('returned_to_applicant_at');
        });
    }
};
