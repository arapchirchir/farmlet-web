<?php

use App\Models\Driver;
use App\Models\User;
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
            $table->string('order_type', 20)->default('raw')->after('shop_id');
            $table->foreignIdFor(User::class, 'vendor_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->foreignIdFor(Driver::class, 'driver_id')->nullable()->after('vendor_id')->constrained('drivers')->nullOnDelete();
            $table->unsignedBigInteger('processing_room_id')->nullable()->after('driver_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['order_type', 'processing_room_id']);
        });
    }
};
