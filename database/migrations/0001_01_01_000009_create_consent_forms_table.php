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
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();

            $table->string('consent_type');
            $table->string('embedded_text_version')->nullable();
            $table->string('agreed_by_name')->nullable();
            $table->timestamp('agreed_at')->nullable();

            $table->foreignId('uploaded_document_id')->nullable()->constrained('qualification_documents')->nullOnDelete();
            $table->string('source_awarding_body_name')->nullable();

            $table->timestamps();
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

