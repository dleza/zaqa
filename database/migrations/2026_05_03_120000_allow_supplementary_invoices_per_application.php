<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL cannot drop the unique index while it backs the application_id FK; drop FK first.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['application_id']);
            $table->index('application_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('application_id')
                ->references('id')
                ->on('applications')
                ->cascadeOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('supplementary_of_invoice_id')
                ->nullable()
                ->after('application_id')
                ->constrained('invoices')
                ->nullOnDelete();
            $table->index(['application_id', 'supplementary_of_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['supplementary_of_invoice_id']);
            $table->dropIndex(['application_id', 'supplementary_of_invoice_id']);
            $table->dropColumn('supplementary_of_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('application_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('application_id')
                ->references('id')
                ->on('applications')
                ->cascadeOnDelete();
        });
    }
};
