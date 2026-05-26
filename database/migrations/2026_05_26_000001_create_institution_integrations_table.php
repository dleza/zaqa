<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awarding_institution_id')->constrained('awarding_institutions');
            $table->boolean('is_active')->default(true);
            $table->boolean('supports_push')->default(true);
            $table->boolean('supports_pull')->default(false);
            $table->string('lookup_url')->nullable();
            $table->string('auth_type')->nullable(); // bearer_token, basic, none
            // Stored encrypted (string). Must be text, not JSON, since encrypted payload is not valid JSON.
            $table->longText('credentials')->nullable();
            $table->string('request_method', 10)->default('POST');
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedSmallInteger('retry_attempts')->default(2);
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable();
            $table->string('driver')->nullable(); // generic_rest
            $table->json('config')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            $table->unique('awarding_institution_id');
            $table->index(['supports_pull', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_integrations');
    }
};
