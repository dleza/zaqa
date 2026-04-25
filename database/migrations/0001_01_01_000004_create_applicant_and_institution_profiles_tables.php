<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('surname');

            $table->string('nrc_number')->nullable();
            $table->string('passport_number')->nullable();

            $table->string('email');
            $table->string('phone_primary');
            $table->string('phone_secondary')->nullable();

            $table->timestamps();
        });

        Schema::create('institution_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('institution_name');
            $table->string('email');
            $table->string('phone_primary');
            $table->string('phone_secondary')->nullable();

            $table->string('tpin');
            $table->string('contact_person_name')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_profiles');
        Schema::dropIfExists('applicant_profiles');
    }
};

