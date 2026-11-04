<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payment reconciliation can record PAYMENT_DUPLICATE_REFUND_REQUIRED (33 characters).
        Schema::table('order_events', fn (Blueprint $table) => $table->string('type', 64)->change());
    }

    public function down(): void
    {
        // Retain the wider column: shrinking would lose recorded reconciliation events.
    }
};
