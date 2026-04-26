<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_forms', function (Blueprint $table) {
            $table
                ->foreignId('zaqa_uploaded_document_id')
                ->nullable()
                ->after('uploaded_document_id')
                ->constrained('qualification_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consent_forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zaqa_uploaded_document_id');
        });
    }
};

