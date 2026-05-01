<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('qualifications', 'qualification_type_id')) {
            Schema::table('qualifications', function (Blueprint $table) {
                $table->foreign('qualification_type_id')
                    ->references('id')
                    ->on('qualification_types')
                    ->nullOnDelete();
            });
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('billing_category_id')->nullable()->after('application_id')->constrained('billing_categories')->nullOnDelete();
            $table->foreignId('qualification_type_id')->nullable()->after('billing_category_id')->constrained('qualification_types')->nullOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->after('qualification_type_id')->constrained('fee_structures')->nullOnDelete();
            $table->boolean('is_foreign_snapshot')->nullable()->after('fee_structure_id');
            $table->unsignedInteger('processing_days_snapshot')->nullable()->after('is_foreign_snapshot');
            $table->string('fee_label_snapshot')->nullable()->after('processing_days_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_structure_id');
            $table->dropConstrainedForeignId('qualification_type_id');
            $table->dropConstrainedForeignId('billing_category_id');
            $table->dropColumn(['is_foreign_snapshot', 'processing_days_snapshot', 'fee_label_snapshot']);
        });

        if (Schema::hasColumn('qualifications', 'qualification_type_id')) {
            Schema::table('qualifications', function (Blueprint $table) {
                $table->dropForeign(['qualification_type_id']);
            });
        }
    }
};

