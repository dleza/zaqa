<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_normalized', 191);
            $table->foreignId('qualification_type_id')->nullable()->constrained('qualification_types')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('name_normalized');
            $table->index(['is_active', 'sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_titles');
    }
};
