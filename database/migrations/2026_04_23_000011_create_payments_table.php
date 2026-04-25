<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('method')->index(); // PaymentMethod
            $table->string('status')->index(); // PaymentStatus

            $table->string('currency', 3)->default('ZMW');
            $table->unsignedBigInteger('amount_cents')->default(0);

            $table->string('provider')->default('test')->index();
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_transaction_id')->nullable()->index();

            $table->string('mobile_number')->nullable();

            $table->foreignId('proof_document_id')->nullable()->constrained('qualification_documents')->nullOnDelete();

            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('awaiting_finance_review_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_status_at')->nullable();

            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

