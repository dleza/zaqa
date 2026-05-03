<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_certificates', function (Blueprint $table) {
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('qualification_certificates', function (Blueprint $table) {
            $table->dropIndex(['issued_at']);
        });
    }
};
