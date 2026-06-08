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
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('bank_logo')->default(false)->nullable();
            $table->boolean('chip')->default(false)->nullable();
            $table->boolean('mag_strip')->default(false)->nullable();
            $table->boolean('sig_strip')->default(false)->nullable();
            $table->boolean('hologram')->default(false)->nullable();
            $table->boolean('customer_service')->default(false)->nullable();
            $table->boolean('symmetry')->default(false)->nullable();
            $table->timestamps();

            // Foreign key relation to users table
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
