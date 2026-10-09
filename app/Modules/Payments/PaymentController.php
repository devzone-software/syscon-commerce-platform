<?php

namespace App\Modules\Payments;

use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController
{
    public function __construct(private OrderService $orders) {}

    public function store(Request $r)
    {
        $data = $r->validate(['orderId' => 'required|uuid', 'provider' => 'required|in:MANUAL']);

        return DB::transaction(function () use ($r, $data) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless($o->customer_id === $r->user()->id, 403, 'Pedido ajeno');
            abort_unless($o->status === 'PAYMENT_PENDING', 409, 'Pedido no pendiente');
            $p = DB::table('payments')->where('order_id', $o->id)->where('provider', $data['provider'])->first();
            if (! $p) {
                $id = (string) Str::uuid();
                DB::table('payments')->insert(['id' => $id, 'order_id' => $o->id, 'customer_id' => $o->customer_id, 'provider' => 'MANUAL', 'status' => 'PENDING', 'amount' => $o->total, 'created_at' => now(), 'updated_at' => now()]);
                $p = DB::table('payments')->where('id', $id)->first();
            }

            return Api::ok('Pago creado', $this->view($p));
        }, 3);
    }

    public function show(Request $r, string $id)
    {
        $p = DB::table('payments')->where('id', $id)->first();
        abort_unless($p, 404, 'Pago no encontrado');
        abort_unless($p->customer_id === $r->user()->id || in_array('payments:confirm', $r->user()->permissions(), true), 403, 'Pago ajeno');

        return Api::ok('Pago', $this->view($p));
    }

    public function confirm(Request $r, string $id)
    {
        $data = $r->validate(['reference' => 'required|string|max:255']);

        return DB::transaction(function () use ($id, $data) {
            $p = DB::table('payments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404, 'Pago no encontrado');
            if ($p->status === 'APPROVED') {
                return Api::ok('Pago confirmado', $this->view($p));
            }
            abort_unless($p->provider === 'MANUAL' && $p->status === 'PENDING', 409, 'Pago no pendiente');
            $this->orders->transition($p->order_id, 'PAID');
            DB::table('payments')->where('id', $id)->update(['status' => 'APPROVED', 'external_reference' => $data['reference'], 'updated_at' => now()]);

            return Api::ok('Pago confirmado', $this->view(DB::table('payments')->where('id', $id)->first()));
        }, 3);
    }

    private function view(object $p): array
    {
        return ['id' => $p->id, 'orderId' => $p->order_id, 'customerId' => $p->customer_id, 'provider' => $p->provider, 'status' => $p->status, 'amount' => bcadd((string) $p->amount,'0',2), 'externalReference' => $p->external_reference, 'paymentUrl' => $p->payment_url];
    }
}
