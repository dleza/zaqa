<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_pull_lookup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awarding_institution_id')->constrained('awarding_institutions');
            $table->foreignId('institution_integration_id')->nullable()->constrained('institution_integrations');
            $table->foreignId('qualification_id')->constrained('qualifications');
            $table->string('endpoint');
            $table->string('method', 16);
            $table->string('correlation_id')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('status')->nullable(); // found, not_found, failed, timeout, invalid_response
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['awarding_institution_id', 'created_at'], 'idx_pull_logs_inst_created');
            $table->index(['qualification_id', 'created_at'], 'idx_pull_logs_q_created');
            $table->index(['status', 'created_at'], 'idx_pull_logs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_pull_lookup_logs');
    }
};

