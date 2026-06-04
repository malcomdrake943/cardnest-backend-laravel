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
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->nullable();
            $table->string('service_type')->default('card_scan')->nullable();
            $table->foreignId('account_holder_id')->constrained('account_holders')->onDelete('cascade');
            $table->string('email')->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('street')->nullable();
            $table->string('street_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('country')->nullable();
            $table->string('registration_document_path')->nullable();
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
