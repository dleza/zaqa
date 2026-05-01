<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('identity_document_disk')->nullable()->after('country');
            $table->string('identity_document_path')->nullable();
            $table->string('identity_document_original_name')->nullable();
            $table->unsignedBigInteger('identity_document_size_bytes')->nullable();
            $table->timestamp('identity_document_uploaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'identity_document_disk',
                'identity_document_path',
                'identity_document_original_name',
                'identity_document_size_bytes',
                'identity_document_uploaded_at',
            ]);
        });
    }
};
