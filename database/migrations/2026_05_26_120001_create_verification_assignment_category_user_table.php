<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_assignment_category_user', function (Blueprint $table) {
            $table->id();
            // NOTE: Use explicit FK names to avoid MySQL identifier length limits.
            $table->foreignId('verification_assignment_category_id');
            $table->foreignId('user_id');

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_available')->default(true)->index();
            $table->string('unavailable_reason')->nullable();
            $table->timestamp('unavailable_until')->nullable()->index();
            $table->integer('priority')->nullable()->index();
            $table->timestamp('last_assigned_at')->nullable()->index();

            $table->timestamps();

            $table->foreign('verification_assignment_category_id', 'vac_user_cat_fk')
                ->references('id')->on('verification_assignment_categories')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'vac_user_user_fk')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['verification_assignment_category_id', 'user_id'], 'vac_user_unique');
            $table->index(['user_id', 'is_active', 'is_available'], 'vac_user_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_assignment_category_user');
    }
};
