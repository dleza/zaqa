<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_record_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('awarding_institution_id')->nullable()->constrained('awarding_institutions')->nullOnDelete();

            $table->string('file_path');
            $table->string('original_filename');

            $table->string('status')->index();

            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            $table->json('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['awarding_institution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_record_imports');
    }
};

