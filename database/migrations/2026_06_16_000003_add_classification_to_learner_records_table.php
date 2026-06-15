<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_records', function (Blueprint $table) {
            $table->string('classification', 150)->nullable()->after('award_date');
        });
    }

    public function down(): void
    {
        Schema::table('learner_records', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
