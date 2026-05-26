<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awarding_institution_id')->constrained('awarding_institutions');
            $table->string('name');
            $table->json('scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['awarding_institution_id', 'is_active']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_api_clients');
    }
};

