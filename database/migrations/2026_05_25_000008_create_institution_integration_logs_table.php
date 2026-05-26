<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awarding_institution_id')->constrained('awarding_institutions');
            $table->foreignId('institution_api_client_id')->nullable()->constrained('institution_api_clients');
            $table->string('endpoint');
            $table->string('method', 16);
            $table->string('correlation_id')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('status')->nullable(); // success, validation_failed, unauthorized, throttled, failed
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();

            // MySQL index names are limited (64 chars); use explicit short names.
            $table->index(['awarding_institution_id', 'created_at'], 'idx_inst_logs_inst_created');
            $table->index(['status', 'created_at'], 'idx_inst_logs_status_created');
            $table->index('endpoint', 'idx_inst_logs_endpoint');
            $table->index('correlation_id', 'idx_inst_logs_correlation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_integration_logs');
    }
};
