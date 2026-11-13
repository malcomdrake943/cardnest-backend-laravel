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
            $table->unsignedBigInteger('parent_merchant_id')->nullable()->after('id')->index();
            $table->foreign('parent_merchant_id')->references('id')->on('users')->onDelete('cascade');

            $table->boolean('is_additional_admin')->default(false)->after('parent_merchant_id');
            $table->enum('access_type', ['full', 'custom'])->default('custom')->after('is_additional_admin');
            $table->json('permissions')->nullable()->after('access_type');
            $table->enum('admin_status', ['active', 'inactive'])->default('active')->after('permissions');
            $table->timestamp('tokens_revoked_at')->nullable()->after('admin_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['parent_merchant_id']);
            $table->dropColumn([
                'parent_merchant_id',
                'is_additional_admin',
                'access_type',
                'permissions',
                'admin_status',
                'tokens_revoked_at',
            ]);
        });
    }
};

