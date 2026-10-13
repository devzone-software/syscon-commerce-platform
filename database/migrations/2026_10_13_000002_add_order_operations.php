<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operación diaria de pedidos: vencimiento de reservas impagas, quién confirmó cada pago y un
 * historial de eventos por pedido (creación, solicitud y confirmación de pago, cambios de estado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable();
            $t->index(['status', 'expires_at']);
            $t->index('created_at');
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users');
            $t->timestamp('confirmed_at')->nullable();
            $t->index(['status', 'created_at']);
        });
        Schema::create('order_events', function (Blueprint $t) {
            // Autoincremental: ordena eventos del mismo segundo en el orden en que ocurrieron.
            $t->id();
            $t->foreignUuid('order_id')->constrained();
            // Null when the system acts (p. ej. vencimiento automático).
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->string('type', 30);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->string('reference')->nullable();
            $t->timestamp('created_at');
            $t->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['status', 'created_at']);
            $t->dropConstrainedForeignId('confirmed_by');
            $t->dropColumn('confirmed_at');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['status', 'expires_at']);
            $t->dropIndex(['created_at']);
            $t->dropColumn('expires_at');
        });
    }
};
