<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
            $table->text('description')->nullable()->after('code');
            $table->boolean('is_active')->default(true)->index()->after('description');
            $table->unsignedInteger('sort_order')->default(0)->index()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['code', 'description', 'is_active', 'sort_order']);
        });
    }
};

