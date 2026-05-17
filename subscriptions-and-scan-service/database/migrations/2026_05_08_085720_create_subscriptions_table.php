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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('merchant_id')->nullable();
            $table->boolean('is_custom_renewal')->default(true);
            $table->foreignId('package_id')->constrained('packages')->onDelete('cascade');
            $table->string('api_call_limit')->nullable();
            $table->string('api_calls_used')->nullable();
            $table->string('overage_calls')->nullable();
            $table->string('status')->nullable();   //active, not-active
            $table->string('subscription_date')->nullable();
            $table->string('renewal_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
