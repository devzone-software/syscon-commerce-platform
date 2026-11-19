<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de salida de eventos para automatizaciones externas (n8n): cada evento se guarda en la
 * misma transacción que la operación y se envía después; si n8n no responde, el scheduler reintenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // order.created, order.paid, invoice.accepted, complaint.created o test.ping.
            $t->string('event', 40);
            // Evita duplicados: un mismo evento por recurso (pedido, comprobante, hoja).
            $t->string('subject', 64);
            $t->json('payload');
            // PENDING, SENDING, SENT o FAILED.
            $t->string('status', 10)->default('PENDING');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['event', 'subject']);
            $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
