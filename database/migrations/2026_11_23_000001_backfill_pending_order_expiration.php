<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los pedidos impagos creados antes de que existiera el vencimiento de reservas quedaron con
 * expires_at vacío: orders:expire nunca los toma y su stock sigue reservado para siempre. Se les
 * da el mismo plazo que a un pedido nuevo, contado desde su creación; el scheduler los vence en
 * su siguiente vuelta y libera las reservas. Si las reservas no vencen (reservation_hours = 0),
 * no se toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hours = (int) config('commerce.orders.reservation_hours');
        if ($hours <= 0) {
            return;
        }
        DB::table('orders')->where('status', 'PAYMENT_PENDING')->whereNull('expires_at')->orderBy('id')->get(['id', 'created_at'])
            ->each(fn ($o) => DB::table('orders')->where('id', $o->id)->update(['expires_at' => Carbon::parse($o->created_at)->addHours($hours)]));
    }

    public function down(): void
    {
        // Sin vuelta atrás: dejar la reserva sin vencimiento era el error que se corrige.
    }
};
