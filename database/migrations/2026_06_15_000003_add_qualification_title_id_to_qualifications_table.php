<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('qualification_title_id')
                ->nullable()
                ->after('title_of_qualification')
                ->constrained('qualification_titles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualification_title_id');
        });
    }
};
