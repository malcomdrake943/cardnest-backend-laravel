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
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('merchant_id')->nullable();
            $table->string('merchant_key')->nullable();
            $table->string('card_number_masked')->nullable();
            $table->string('status')->nullable();
            $table->string('encrypted_data')->nullable();
            $table->string('scan_id')->nullable();
            $table->foreignId('session_id')->constrained('scan_sessions')->onDelete('cascade')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('failure_stage')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
