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
        Schema::create('escrow_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable(); // ID of the user (vendor, driver, admin)
            $table->string('actor_type'); // 'vendor', 'driver', 'admin'
            $table->decimal('amount', 10, 2);
            $table->string('type'); // 'item_price', 'delivery_fee', 'commission', 'vat'
            $table->string('status')->default('held'); // 'held', 'released', 'cancelled', 'refunded'
            $table->string('description')->nullable();
            $table->string('transaction_ref')->nullable(); // Reference to the actual transaction when released
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escrow_ledgers');
    }
};
