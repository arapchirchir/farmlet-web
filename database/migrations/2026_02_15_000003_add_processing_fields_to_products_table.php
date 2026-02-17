<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('processing_available')->default(false)->after('discount_price');
            $table->float('raw_price')->nullable()->after('processing_available');
            $table->float('processed_price')->nullable()->after('raw_price');
        });

        DB::table('products')
            ->whereNull('raw_price')
            ->update(['raw_price' => DB::raw('price')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['processing_available', 'raw_price', 'processed_price']);
        });
    }
};
