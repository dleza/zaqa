<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('consent_forms', function (Blueprint $table) {
            $table->id();
            // Consent is captured per qualification item. Foreign consent is required per foreign qualification.
            $table->foreignId('qualification_id')->constrained('qualifications')->cascadeOnDelete();

            $table->string('consent_type');
            $table->string('embedded_text_version')->nullable();
            $table->string('agreed_by_name')->nullable();
            $table->timestamp('agreed_at')->nullable();

            $table->foreignId('uploaded_document_id')->nullable()->constrained('qualification_documents')->nullOnDelete();
            $table->string('source_awarding_institution_name')->nullable();

            $table->timestamps();

            $table->index(['qualification_id', 'consent_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consent_forms');
    }
};

