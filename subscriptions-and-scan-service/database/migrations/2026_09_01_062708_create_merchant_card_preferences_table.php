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
        Schema::create('merchant_card_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_id')->unique();
            $table->json('card_types')->nullable();
            $table->json('card_networks')->nullable();
            $table->json('blocked_countries')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_card_preferences');
    }
};
