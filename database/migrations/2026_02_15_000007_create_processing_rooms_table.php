<?php

use App\Models\County;
use App\Models\Subcounty;
use App\Models\Ward;
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
        Schema::create('processing_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignIdFor(County::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Subcounty::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Ward::class)->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_rooms');
    }
};
