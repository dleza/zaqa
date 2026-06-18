<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_documents', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('is_current_version');
            $table->timestamp('deleted_at')->nullable()->after('superseded_at');
            $table->foreignId('replaced_by_document_id')
                ->nullable()
                ->after('deleted_at')
                ->constrained('qualification_documents')
                ->nullOnDelete();

            $table->index(
                ['application_id', 'qualification_id', 'document_type', 'is_current_version', 'deleted_at'],
                'idx_document_active_evidence',
            );
        });
    }

    public function down(): void
    {
        Schema::table('qualification_documents', function (Blueprint $table) {
            $table->dropIndex('idx_document_active_evidence');
            $table->dropConstrainedForeignId('replaced_by_document_id');
            $table->dropColumn(['superseded_at', 'deleted_at']);
        });
    }
};
