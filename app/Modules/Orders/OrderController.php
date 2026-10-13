<?php

namespace App\Modules\Orders;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function store(Request $r)
    {
        $input = $r->all();
        $input['billing'] = is_array($input['billing'] ?? null) ? BillingData::normalize($input['billing']) : ($input['billing'] ?? null);
        $validator = Validator::make($input, ['items' => 'required|array|min:1|max:100', 'items.*.productId' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000', 'billing' => 'required|array'] + BillingData::rules('billing.'));
        $validator->after(fn ($v) => is_array($input['billing']) && BillingData::check($v, $input['billing'], 'billing.'));
        $data = $validator->validate();

        return Api::ok('Pedido creado', $this->orders->create($r->user()->id, $data['items'], $data['billing']));
    }

    public function index(Request $r)
    {
        return Api::ok('Pedidos', DB::table('orders')->where('customer_id', $r->user()->id)->orderByDesc('created_at')->pluck('id')->map(fn ($id) => $this->orders->get($id)));
    }

    public function show(Request $r, string $id)
    {
        $o = $this->orders->get($id);
        abort_unless($o['customerId'] === $r->user()->id || in_array('orders:manage', $r->user()->permissions(), true), 403, 'Acceso denegado');

        return Api::ok('Pedido', $o);
    }

    public function status(string $id, string $status)
    {
        abort_if($status === 'PAID', 403, 'Confirmar pago mediante el módulo de pagos');

        return Api::ok('Estado actualizado', $this->orders->transition($id, $status));
    }

    public function internalShow(string $id)
    {
        return Api::ok('Pedido', $this->orders->get($id));
    }

    public function paid(string $id)
    {
        return Api::ok('Pago confirmado', $this->orders->transition($id,'PAID'));
    }
}
