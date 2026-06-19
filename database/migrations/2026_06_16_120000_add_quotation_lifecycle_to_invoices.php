<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('document_type', 20)->default('invoice')->after('supplementary_of_invoice_id');
            $table->string('quotation_number')->nullable()->unique()->after('invoice_number');
            $table->timestamp('expires_at')->nullable()->after('due_at');
            $table->timestamp('converted_to_invoice_at')->nullable()->after('paid_at');
            $table->index(['document_type', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::table('invoices')->where('status', 'paid')->update(['document_type' => 'invoice']);

        DB::table('invoices')
            ->whereIn('status', ['issued', 'draft'])
            ->whereNull('supplementary_of_invoice_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($row) {
                $issuedAt = $row->issued_at ?? $row->created_at;
                $expiresAt = $issuedAt
                    ? Carbon::parse($issuedAt)->addDays(60)
                    : now()->addDays(60);

                DB::table('invoices')->where('id', $row->id)->update([
                    'document_type' => 'quotation',
                    'quotation_number' => $row->invoice_number,
                    'expires_at' => $expiresAt,
                ]);
            });

        DB::table('invoices')
            ->whereIn('status', ['issued', 'draft'])
            ->whereNotNull('supplementary_of_invoice_id')
            ->update(['document_type' => 'invoice']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'status']);
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn([
                'document_type',
                'quotation_number',
                'expires_at',
                'converted_to_invoice_at',
            ]);
        });
    }
};
