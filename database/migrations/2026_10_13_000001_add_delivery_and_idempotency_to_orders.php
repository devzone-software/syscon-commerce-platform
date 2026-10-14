<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega elegida al comprar (retiro en tienda o envío) y clave de idempotencia de la creación.
 * Los pedidos anteriores quedan sin modalidad registrada y con costo de entrega 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('delivery_method', 10)->nullable();
            $t->decimal('delivery_cost', 14, 2)->default(0);
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_phone', 9)->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('delivery_district', 80)->nullable();
            $t->string('delivery_reference')->nullable();
            $t->uuid('idempotency_key')->nullable();
            $t->string('request_hash', 64)->nullable();
            $t->unique(['customer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        // MySQL usa el índice único como índice de la clave foránea customer_id (descartó el suyo al
        // crearlo): antes de quitarlo hay que darle a la clave foránea un índice propio.
        Schema::table('orders', function (Blueprint $t) {
            $t->index('customer_id');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropUnique(['customer_id', 'idempotency_key']);
            $t->dropColumn(['delivery_method', 'delivery_cost', 'contact_name', 'contact_phone', 'delivery_address', 'delivery_district', 'delivery_reference', 'idempotency_key', 'request_hash']);
        });
    }
};
