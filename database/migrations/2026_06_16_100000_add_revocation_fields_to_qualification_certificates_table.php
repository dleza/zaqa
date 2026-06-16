<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualification_certificates', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('issued_at');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable()->after('revoked_by_user_id');
            $table->text('revocation_public_note')->nullable()->after('revocation_reason');
            $table->string('certificate_type', 32)->default('verification')->after('status');
            $table->foreignId('replaces_certificate_id')
                ->nullable()
                ->after('metadata')
                ->constrained('qualification_certificates')
                ->nullOnDelete();
            $table->foreignId('superseded_by_certificate_id')
                ->nullable()
                ->after('replaces_certificate_id')
                ->constrained('qualification_certificates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qualification_certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_certificate_id');
            $table->dropConstrainedForeignId('replaces_certificate_id');
            $table->dropColumn([
                'certificate_type',
                'revocation_public_note',
                'revocation_reason',
                'revoked_by_user_id',
                'revoked_at',
            ]);
        });
    }
};
