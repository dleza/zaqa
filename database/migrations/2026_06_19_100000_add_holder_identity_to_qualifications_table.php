<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            if (! Schema::hasColumn('qualifications', 'holder_identity')) {
                $table->json('holder_identity')->nullable()->after('nrc_passport_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            if (Schema::hasColumn('qualifications', 'holder_identity')) {
                $table->dropColumn('holder_identity');
            }
        });
    }
};
