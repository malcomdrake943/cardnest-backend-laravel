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
        Schema::table('merchant_card_preferences', function (Blueprint $table) {
            $table->json('card_levels')->nullable()->after('card_networks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_card_preferences', function (Blueprint $table) {
            $table->dropColumn('card_levels');
        });
    }
};
