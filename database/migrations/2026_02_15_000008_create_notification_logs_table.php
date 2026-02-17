<?php

use App\Models\Order;
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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_role')->nullable();
            $table->string('event_key');
            $table->string('channel');
            $table->string('destination')->nullable();
            $table->string('status')->default('skipped');
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'channel']);
            $table->index(['order_id', 'recipient_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
