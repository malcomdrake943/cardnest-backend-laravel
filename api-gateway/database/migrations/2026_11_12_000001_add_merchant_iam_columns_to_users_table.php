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
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'parent_merchant_id')) {
                $table->unsignedBigInteger('parent_merchant_id')->nullable()->after('id')->index();
                $table->foreign('parent_merchant_id')->references('id')->on('users')->onDelete('cascade');
            }
            if (!Schema::hasColumn('users', 'is_additional_admin')) {
                $table->boolean('is_additional_admin')->default(false)->after('parent_merchant_id');
            }
            if (!Schema::hasColumn('users', 'access_type')) {
                $table->enum('access_type', ['full', 'custom'])->default('custom')->after('is_additional_admin');
            }
            if (!Schema::hasColumn('users', 'permissions')) {
                $table->json('permissions')->nullable()->after('access_type');
            }
            if (!Schema::hasColumn('users', 'admin_status')) {
                $table->enum('admin_status', ['active', 'inactive'])->default('active')->after('permissions');
            }
            if (!Schema::hasColumn('users', 'tokens_revoked_at')) {
                $table->timestamp('tokens_revoked_at')->nullable()->after('admin_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'parent_merchant_id')) {
                $table->dropForeign(['parent_merchant_id']);
                $table->dropColumn('parent_merchant_id');
            }

            foreach (['is_additional_admin', 'access_type', 'permissions', 'admin_status', 'tokens_revoked_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
