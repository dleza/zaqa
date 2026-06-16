<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_record_submission_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('source_type')->index();
            $table->foreignId('source_institution_id')->nullable()->constrained('awarding_institutions')->nullOnDelete();
            $table->unsignedBigInteger('institution_api_client_id')->nullable();
            $table->foreign('institution_api_client_id', 'lrsb_inst_api_client_fk')
                ->references('id')->on('institution_api_clients')->nullOnDelete();
            $table->unsignedBigInteger('institution_api_batch_id')->nullable();
            $table->foreign('institution_api_batch_id', 'lrsb_inst_api_batch_fk')
                ->references('id')->on('institution_api_batches')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('received')->index();
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('approved_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_validation_count')->default(0);
            $table->text('summary_message')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['source_institution_id', 'created_at'], 'lrsb_inst_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_record_submission_batches');
    }
};
