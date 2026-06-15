<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signature_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('display_name')->nullable();
            $table->string('file_path');
            $table->string('disk', 32)->default('local');
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signature_settings');
    }
};
