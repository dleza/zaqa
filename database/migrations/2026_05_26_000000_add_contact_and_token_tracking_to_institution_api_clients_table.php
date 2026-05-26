<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_api_clients', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('name');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->timestamp('token_last_sent_at')->nullable()->after('last_used_at');
            $table->timestamp('token_rotated_at')->nullable()->after('token_last_sent_at');
            $table->text('notes')->nullable()->after('token_rotated_at');
            $table->foreignId('revoked_by_user_id')->nullable()->after('created_by_user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('institution_api_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn([
                'contact_name',
                'contact_email',
                'token_last_sent_at',
                'token_rotated_at',
                'notes',
            ]);
        });
    }
};

