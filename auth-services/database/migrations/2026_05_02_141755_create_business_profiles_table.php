<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('service_type')->default('card_scan');
            $table->foreignId('account_holder_id')->constrained('account_holders')->onDelete('cascade');
            $table->string('email');
            $table->string('business_name');
            $table->string('business_registration_number');
            $table->string('street');
            $table->string('street_line2');
            $table->string('city');
            $table->string('state');
            $table->string('zip_code');
            $table->string('country');
            $table->string('registration_document_path');
            $table->string('display_name')->nullable();
            $table->string('display_logo')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
