<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caja de la venta directa: cada usuario abre su caja con un fondo, registra ingresos y egresos
 * y la cierra con un arqueo. Los pagos de sus ventas directas quedan asociados a la caja abierta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users');
            // OPEN o CLOSED.
            $t->string('status', 6)->default('OPEN');
            $t->decimal('opening_amount', 14, 2);
            $t->timestamp('opened_at');
            // Calculados al cerrar: efectivo esperado, contado y diferencia (contado − esperado).
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('counted_cash', 14, 2)->nullable();
            $t->decimal('difference', 14, 2)->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('cash_session_id')->constrained('cash_sessions');
            // IN (ingreso) u OUT (egreso) de efectivo ajeno a las ventas.
            $t->string('type', 3);
            $t->decimal('amount', 14, 2);
            $t->string('reason', 255);
            $t->timestamp('created_at');
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignUuid('cash_session_id')->nullable()->constrained('cash_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('cash_session_id'));
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
