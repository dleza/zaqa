<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_category_id')->constrained('billing_categories')->restrictOnDelete();
            $table->unsignedBigInteger('local_fee_cents')->nullable();
            $table->unsignedBigInteger('foreign_fee_cents')->nullable();
            $table->string('currency', 3)->default('ZMW');
            $table->timestamp('effective_from')->index();
            $table->timestamp('effective_to')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->index(['billing_category_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};

