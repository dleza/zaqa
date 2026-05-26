<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_assignment_category_awarding_institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_assignment_category_id');
            $table->foreignId('awarding_institution_id');
            $table->timestamps();

            // NOTE: Use explicit FK/index names to avoid MySQL identifier length limits.
            $table->foreign('verification_assignment_category_id', 'vacai_cat_fk')
                ->references('id')->on('verification_assignment_categories')
                ->cascadeOnDelete();
            $table->foreign('awarding_institution_id', 'vacai_inst_fk')
                ->references('id')->on('awarding_institutions')
                ->cascadeOnDelete();

            $table->unique(['verification_assignment_category_id', 'awarding_institution_id'], 'vacai_unique');
            $table->index(['awarding_institution_id'], 'vacai_inst_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_assignment_category_awarding_institutions');
    }
};

