<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de salida de correos al cliente. Cada aviso se registra en la misma transacción que la
 * operación comercial y se envía después del commit; la clave única evita avisos duplicados y un
 * fallo de envío solo cambia esta fila, nunca el pedido, el pago ni el comprobante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            // ORDER_CREATED, PAYMENT_CONFIRMED o INVOICE_AVAILABLE.
            $t->string('type', 30);
            // Comprobante avisado; '' para los avisos que ocurren una sola vez por pedido.
            $t->string('reference', 64)->default('');
            $t->string('recipient');
            // PENDING, SENDING, SENT o FAILED.
            $t->string('status', 10)->default('PENDING');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            // Próximo reintento automático; null en un fallo definitivo (queda el reenvío manual).
            $t->timestamp('next_attempt_at')->nullable();
            // Mientras se envía: otro proceso no la toma hasta este momento.
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'type', 'reference']);
            $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
