<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('address_line_1')->nullable()->after('phone_secondary');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('province');
            $table->string('country')->nullable()->after('postal_code');
        });

        Schema::table('institution_profiles', function (Blueprint $table) {
            $table->string('address_line_1')->nullable()->after('contact_person_name');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('province');
            $table->string('country')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('institution_profiles', function (Blueprint $table) {
            $table->dropColumn(['address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country']);
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn(['address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country']);
        });
    }
};

