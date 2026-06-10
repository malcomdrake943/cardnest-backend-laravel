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
        Schema::create('scan_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('scan_id')->nullable();
            $table->string('merchant_id')->nullable();
            $table->string('device_type')->nullable();
            $table->integer('tries')->nullable();
            $table->string('encryption_key')->nullable();
            $table->text('encrypted_data')->nullable();
            $table->date('scanned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_sessions');
    }
};
