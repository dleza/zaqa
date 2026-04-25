<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('awarding_institution_id')
                ->nullable()
                ->after('application_id')
                ->constrained('awarding_institutions')
                ->nullOnDelete();

            $table->string('awarding_institution_name_other')->nullable()->after('awarding_institution_id');

            $table->index(['awarding_institution_id']);
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awarding_institution_id');
            $table->dropColumn('awarding_institution_name_other');
        });
    }
};

