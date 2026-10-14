<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Historial de envíos y conciliaciones de cada comprobante con SUNAT (evidencia de cada intento). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('invoice_id')->constrained();
            // Personal que lo pidió; null cuando lo hizo el scheduler.
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            // SUBMIT o RECONCILE.
            $t->string('action', 10);
            // Estado resultante: ACCEPTED, REJECTED, FAILED o PENDING_CHECK.
            $t->string('result', 15);
            $t->string('sunat_code', 10)->nullable();
            $t->text('message')->nullable();
            $t->timestamp('created_at');
            $t->index(['invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_attempts');
    }
};
