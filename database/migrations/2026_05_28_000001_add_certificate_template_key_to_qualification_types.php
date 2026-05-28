<?php

use App\Models\QualificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_types', function (Blueprint $table) {
            $table->string('certificate_template_key', 50)
                ->nullable()
                ->after('billing_category_id')
                ->index();
        });

        DB::table('qualification_types')
            ->whereIn('zqf_level_code', ['L1', 'L2A', 'L2B'])
            ->update(['certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS]);

        DB::table('qualification_types')
            ->whereNull('certificate_template_key')
            ->update(['certificate_template_key' => QualificationType::CERTIFICATE_TEMPLATE_DEFAULT]);
    }

    public function down(): void
    {
        Schema::table('qualification_types', function (Blueprint $table) {
            $table->dropIndex(['certificate_template_key']);
            $table->dropColumn('certificate_template_key');
        });
    }
};
