<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_api_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_api_client_id')->constrained('institution_api_clients');
            $table->foreignId('awarding_institution_id')->constrained('awarding_institutions');
            $table->string('status')->default('pending');
            $table->longText('records_json')->nullable();
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('inserted_records')->default(0);
            $table->unsignedInteger('updated_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['awarding_institution_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_api_batches');
    }
};

