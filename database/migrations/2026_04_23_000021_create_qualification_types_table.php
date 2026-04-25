<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_types', function (Blueprint $table) {
            $table->id();
            $table->string('zqf_level_code'); // e.g. L10, L2A, L2B
            $table->string('level_label'); // human label e.g. "Level 10"
            $table->string('name'); // full name
            $table->string('short_name')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('billing_category_id')->constrained('billing_categories')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(['zqf_level_code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_types');
    }
};

