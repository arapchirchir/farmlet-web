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
        Schema::table('shops', function (Blueprint $table) {
            $table->string('seller_type')->default('vendor')->after('user_id');
            $table->boolean('processing_supported')->default(false)->after('seller_type');
            $table->string('approval_status')->default('pending_approval')->after('processing_supported');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->string('status')->default('available')->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['seller_type', 'processing_supported', 'approval_status']);
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
