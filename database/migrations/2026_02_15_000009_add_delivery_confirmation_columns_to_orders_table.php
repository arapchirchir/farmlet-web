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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('driver_delivery_confirmed_at')->nullable()->after('delivered_at');
            $table->timestamp('customer_delivery_confirmed_at')->nullable()->after('driver_delivery_confirmed_at');
            $table->timestamp('settlement_released_at')->nullable()->after('customer_delivery_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'driver_delivery_confirmed_at',
                'customer_delivery_confirmed_at',
                'settlement_released_at',
            ]);
        });
    }
};
