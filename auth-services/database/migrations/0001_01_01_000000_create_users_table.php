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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('parent_id')->nullable();
            $table->string('merchant_id')->nullable();
            $table->string('aes_key')->nullable();
            $table->string('service_type')->default('card_scan')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('country_name')->nullable();
            $table->boolean('otp_verified')->default(false)->nullable();
            $table->string('business_verified')->nullable();
            $table->string('verification_reason')->nullable();
            $table->boolean('on_trial')->default(true)->nullable();
            $table->integer('trial_calls_remaining')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->string('role')->nullable();
            $table->string('device_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('device_timestamp')->nullable();
            $table->text('device')->nullable();
            $table->text('network')->nullable();
            $table->text('sims')->nullable();
            $table->text('location')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
