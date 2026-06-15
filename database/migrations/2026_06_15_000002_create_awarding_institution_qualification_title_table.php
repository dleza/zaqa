<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awarding_institution_qualification_title', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('awarding_institution_id');
            $table->unsignedBigInteger('qualification_title_id');
            $table->timestamps();

            $table->foreign('awarding_institution_id', 'ai_qt_institution_fk')
                ->references('id')
                ->on('awarding_institutions')
                ->cascadeOnDelete();
            $table->foreign('qualification_title_id', 'ai_qt_title_fk')
                ->references('id')
                ->on('qualification_titles')
                ->cascadeOnDelete();

            $table->unique(
                ['awarding_institution_id', 'qualification_title_id'],
                'ai_qt_institution_title_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awarding_institution_qualification_title');
    }
};
