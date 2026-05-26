<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_assignment_category_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_assignment_category_id');
            $table->foreignId('country_id');
            $table->timestamps();

            // NOTE: Use explicit FK/index names to avoid MySQL identifier length limits.
            $table->foreign('verification_assignment_category_id', 'vacc_cat_fk')
                ->references('id')->on('verification_assignment_categories')
                ->cascadeOnDelete();
            $table->foreign('country_id', 'vacc_country_fk')
                ->references('id')->on('countries')
                ->cascadeOnDelete();

            $table->unique(['verification_assignment_category_id', 'country_id'], 'vacc_unique');
            $table->index(['country_id'], 'vacc_country_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_assignment_category_countries');
    }
};

