<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_types', function (Blueprint $table) {
            $table->boolean('requires_subject_results')->default(false)->after('billing_category_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('qualification_types', function (Blueprint $table) {
            $table->dropColumn('requires_subject_results');
        });
    }
};

