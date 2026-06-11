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
        Schema::table('users', function (Blueprint $table) {
            $table->text('device')->nullable()->change();
            $table->text('network')->nullable()->change();
            $table->text('sims')->nullable()->change();
            $table->text('location')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('device')->nullable()->change();
            $table->string('network')->nullable()->change();
            $table->string('sims')->nullable()->change();
            $table->string('location')->nullable()->change();
        });
    }
};
