<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('certificate_number')->unique();
            $table->string('zaqa_reference_number', 64)->nullable();
            $table->string('verification_token', 64)->unique();
            $table->string('file_path', 1024);
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->string('recipient_email')->nullable();
            $table->string('status', 32)->default('issued')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['qualification_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_certificates');
    }
};
