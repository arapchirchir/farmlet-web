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
            $table->string('actor_unique_id')->nullable()->unique()->after('ward_id');
            $table->string('actor_unique_role', 20)->nullable()->after('actor_unique_id');
            $table->unsignedInteger('actor_county_sequence')->nullable()->after('actor_unique_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_actor_unique_id_unique');
            $table->dropColumn(['actor_unique_id', 'actor_unique_role', 'actor_county_sequence']);
        });
    }
};
