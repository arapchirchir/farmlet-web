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
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete()->after('customer_id');
            $table->foreignId('subcounty_id')->nullable()->constrained()->nullOnDelete()->after('county_id');
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete()->after('subcounty_id');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
            $table->foreignId('subcounty_id')->nullable()->constrained()->nullOnDelete()->after('county_id');
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete()->after('subcounty_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete()->after('shop_id');
            $table->foreignId('subcounty_id')->nullable()->constrained()->nullOnDelete()->after('county_id');
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete()->after('subcounty_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('county_id')->nullable()->constrained()->nullOnDelete()->after('id');
            $table->foreignId('subcounty_id')->nullable()->constrained()->nullOnDelete()->after('county_id');
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete()->after('subcounty_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('subcounty_id');
            $table->dropConstrainedForeignId('county_id');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('subcounty_id');
            $table->dropConstrainedForeignId('county_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('subcounty_id');
            $table->dropConstrainedForeignId('county_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ward_id');
            $table->dropConstrainedForeignId('subcounty_id');
            $table->dropConstrainedForeignId('county_id');
        });
    }
};
