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
        Schema::create('user_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type'); // email_activation
            $table->string('token_hash');
            $table->string('sent_to');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('resent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['type', 'expires_at']);
        });

        Schema::create('user_phone_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('phone_number');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('resent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'phone_number']);
            $table->index(['expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_phone_otps');
        Schema::dropIfExists('user_verification_tokens');
    }
};

