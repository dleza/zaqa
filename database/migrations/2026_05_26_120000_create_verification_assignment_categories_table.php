<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_assignment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // foreign_country | local_institution
            $table->string('type')->index();

            // NOTE: Use explicit FK names to avoid MySQL identifier length limits.
            $table->foreignId('country_id')->nullable();
            $table->foreignId('awarding_institution_id')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('last_assigned_user_id')->nullable();
            $table->timestamp('last_assigned_at')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('country_id', 'vac_country_fk')
                ->references('id')->on('countries')
                ->nullOnDelete();
            $table->foreign('awarding_institution_id', 'vac_awarding_inst_fk')
                ->references('id')->on('awarding_institutions')
                ->nullOnDelete();
            $table->foreign('last_assigned_user_id', 'vac_last_assigned_user_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['type', 'country_id'], 'vac_unique_country_type');
            $table->unique(['type', 'awarding_institution_id'], 'vac_unique_inst_type');
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_assignment_categories');
    }
};
