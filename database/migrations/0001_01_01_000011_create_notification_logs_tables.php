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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();

            $table->string('phone_number');
            $table->string('message_type');
            $table->text('message_body');
            $table->string('provider');
            $table->string('status');
            $table->string('provider_reference')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['phone_number', 'created_at']);
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();

            $table->string('email');
            $table->string('subject');
            $table->string('template_key');
            $table->string('status');
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['email', 'created_at']);
            $table->index(['template_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('sms_logs');
    }
};

