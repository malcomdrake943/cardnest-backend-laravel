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
        Schema::create('account_holders', function (Blueprint $table) {
            $table->id();
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('date_of_birth');
            $table->string('street');
            $table->string('street_line2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('zip_code');
            $table->string('country');
            $table->string('id_document_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_holders');
    }
};
