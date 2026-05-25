<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();

            $table->string('gateway')->default('cgrate')->index();
            $table->string('method')->default('mobile_money')->index();

            $table->string('payment_reference')->unique();
            $table->string('provider_transaction_id')->nullable()->index();

            $table->string('mobile_number')->index();

            $table->string('currency', 3)->default('ZMW');
            $table->unsignedBigInteger('amount_cents')->default(0);

            $table->string('status')->index();
            $table->string('gateway_status')->nullable()->index();

            $table->unsignedInteger('response_code')->nullable()->index();
            $table->text('response_message')->nullable();

            $table->timestamp('initiated_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->timestamp('last_queried_at')->nullable()->index();
            $table->unsignedInteger('query_attempts')->default(0);
            $table->timestamp('next_query_at')->nullable()->index();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index(['gateway', 'status'], 'payment_attempts_gateway_and_status_index');
            $table->index(['application_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
