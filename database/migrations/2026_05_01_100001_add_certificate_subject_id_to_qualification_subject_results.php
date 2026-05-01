<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_subject_results', function (Blueprint $table) {
            $table->foreignId('certificate_subject_id')
                ->nullable()
                ->after('qualification_id')
                ->constrained('certificate_subjects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qualification_subject_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_subject_id');
        });
    }
};
