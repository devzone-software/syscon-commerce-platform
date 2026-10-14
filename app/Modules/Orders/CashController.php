<?php

namespace App\Modules\Orders;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caja del personal que vende en tienda. Reglas:
 * - Cada usuario tiene a lo sumo una caja abierta; sus ventas directas se cobran en ella.
 * - Efectivo esperado = fondo inicial + cobros en efectivo + ingresos − egresos.
 * - Al cerrar se registra lo contado y la diferencia; una caja cerrada no cambia.
 */
class CashController
{
    private const MONEY = 'required|decimal:0,2|min:0|max:999999999.99';

    /** Caja abierta del usuario con su resumen, o null. */
    public function current(Request $r)
    {
        $session = self::openFor($r->user()->id);

        return Api::ok('Caja', $session ? self::view($session) : null);
    }

    public function open(Request $r)
    {
        $data = $r->validate(['openingAmount' => self::MONEY]);
        $user = $r->user()->id;

        return DB::transaction(function () use ($data, $user) {
            // Bloquear al usuario serializa dos aperturas simultáneas de la misma persona.
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            abort_if(self::openFor($user), 409, 'Ya tienes una caja abierta');
            $id = (string) Str::uuid();
            DB::table('cash_sessions')->insert(['id' => $id, 'user_id' => $user, 'status' => 'OPEN', 'opening_amount' => bcadd((string) $data['openingAmount'], '0', 2),
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return Api::ok('Caja abierta', self::view(DB::table('cash_sessions')->where('id', $id)->first()));
        });
    }

    public function movement(Request $r)
    {
        $data = $r->validate(['type' => 'required|in:IN,OUT', 'amount' => 'required|decimal:0,2|gt:0|max:999999999.99', 'reason' => 'required|string|min:3|max:255']);

        return DB::transaction(function () use ($r, $data) {
            $session = self::openFor($r->user()->id, true);
            abort_unless($session, 409, 'Abre la caja primero');
            $amount = bcadd((string) $data['amount'], '0', 2);
            abort_if($data['type'] === 'OUT' && bccomp($amount, self::expectedCash($session), 2) > 0, 422, 'El egreso supera el efectivo en caja');
            DB::table('cash_movements')->insert(['cash_session_id' => $session->id, 'type' => $data['type'], 'amount' => $amount, 'reason' => trim($data['reason']), 'created_at' => now()]);

            return Api::ok('Movimiento registrado', self::view($session));
        });
    }

    public function close(Request $r)
    {
        $data = $r->validate(['countedCash' => self::MONEY, 'notes' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($r, $data) {
            $session = self::openFor($r->user()->id, true);
            abort_unless($session, 409, 'No tienes una caja abierta');
            $expected = self::expectedCash($session);
            $counted = bcadd((string) $data['countedCash'], '0', 2);
            DB::table('cash_sessions')->where('id', $session->id)->update(['status' => 'CLOSED', 'expected_cash' => $expected, 'counted_cash' => $counted,
                'difference' => bcsub($counted, $expected, 2), 'notes' => isset($data['notes']) ? trim($data['notes']) : null, 'closed_at' => now(), 'updated_at' => now()]);

            return Api::ok('Caja cerrada', self::view(DB::table('cash_sessions')->where('id', $session->id)->first()));
        });
    }

    /** Historial de cajas (la propia o, con reports:view, la de todo el personal). */
    public function sessions(Request $r)
    {
        $data = $r->validate(['page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('cash_sessions');
        if (! in_array('reports:view', $r->user()->permissions(), true)) {
            $query->where('user_id', $r->user()->id);
        }
        $perPage = (int) ($data['perPage'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count();

        return Api::ok('Cajas', ['items' => $query->orderByDesc('opened_at')->forPage($page, $perPage)->get()->map(fn ($s) => self::view($s, false))->all(),
            'page' => $page, 'perPage' => $perPage, 'total' => $total]);
    }

    public static function openFor(string $user, bool $lock = false): ?object
    {
        $query = DB::table('cash_sessions')->where('user_id', $user)->where('status', 'OPEN');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public static function expectedCash(object $session): string
    {
        $cash = (string) (DB::table('payments')->where('cash_session_id', $session->id)->where('provider', 'CASH')->where('status', 'APPROVED')->sum('amount') ?: '0');
        $in = (string) (DB::table('cash_movements')->where('cash_session_id', $session->id)->where('type', 'IN')->sum('amount') ?: '0');
        $out = (string) (DB::table('cash_movements')->where('cash_session_id', $session->id)->where('type', 'OUT')->sum('amount') ?: '0');

        return bcsub(bcadd(bcadd((string) $session->opening_amount, $cash, 2), $in, 2), $out, 2);
    }

    public static function view(object $s, bool $detail = true): array
    {
        $byMethod = DB::table('payments')->where('cash_session_id', $s->id)->where('status', 'APPROVED')
            ->select('provider', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount) as total'))->groupBy('provider')->get()
            ->map(fn ($p) => ['method' => $p->provider, 'payments' => (int) $p->n, 'total' => bcadd((string) $p->total, '0', 2)])->values()->all();
        $view = ['id' => $s->id, 'status' => $s->status, 'cashier' => DB::table('users')->where('id', $s->user_id)->value('email'),
            'openingAmount' => bcadd((string) $s->opening_amount, '0', 2), 'openedAt' => OrderService::iso($s->opened_at), 'closedAt' => OrderService::iso($s->closed_at),
            'byMethod' => $byMethod,
            'expectedCash' => $s->status === 'OPEN' ? self::expectedCash($s) : bcadd((string) $s->expected_cash, '0', 2),
            'countedCash' => $s->counted_cash === null ? null : bcadd((string) $s->counted_cash, '0', 2),
            'difference' => $s->difference === null ? null : bcadd((string) $s->difference, '0', 2), 'notes' => $s->notes];
        if ($detail) {
            $view['movements'] = DB::table('cash_movements')->where('cash_session_id', $s->id)->orderBy('id')->get()
                ->map(fn ($m) => ['type' => $m->type, 'amount' => bcadd((string) $m->amount, '0', 2), 'reason' => $m->reason, 'at' => OrderService::iso($m->created_at)])->all();
            $view['sales'] = DB::table('payments')->where('cash_session_id', $s->id)->where('status', 'APPROVED')->orderBy('created_at')->get()
                ->map(fn ($p) => ['orderId' => $p->order_id, 'method' => $p->provider, 'amount' => bcadd((string) $p->amount, '0', 2), 'reference' => $p->external_reference, 'at' => OrderService::iso($p->created_at)])->all();
        }

        return $view;
    }
}
