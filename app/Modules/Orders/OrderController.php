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
        $input['delivery'] = is_array($input['delivery'] ?? null) ? DeliveryData::normalize($input['delivery']) : ($input['delivery'] ?? null);
        $validator = Validator::make($input, ['items' => 'required|array|min:1|max:100', 'items.*.productId' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000', 'billing' => 'required|array', 'delivery' => 'required|array', 'idempotencyKey' => 'nullable|uuid']
            + BillingData::rules('billing.') + DeliveryData::rules('delivery.'));
        $validator->after(function ($v) use ($input) {
            is_array($input['billing']) && BillingData::check($v, $input['billing'], 'billing.');
            is_array($input['delivery']) && DeliveryData::check($v, $input['delivery'], 'delivery.');
        });
        $data = $validator->validate();
        $key = isset($data['idempotencyKey']) ? strtolower($data['idempotencyKey']) : null;
        $delivery = array_intersect_key($input['delivery'], array_flip(['method', 'contactName', 'contactPhone', 'address', 'district', 'reference'])) + ['address' => null, 'district' => null, 'reference' => null];
        $order = $this->orders->create($r->user()->id, $data['items'], $data['billing'], $delivery, $key);
        $replayed = (bool) ($order['replayed'] ?? false);
        unset($order['replayed']);

        return Api::ok($replayed ? 'Pedido ya registrado' : 'Pedido creado', $order);
    }

    /** Modalidades de entrega y su costo, tal como se cobrarán al crear el pedido. */
    public function deliveryOptions()
    {
        return Api::ok('Opciones de entrega', DeliveryData::options());
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

    public function status(Request $r, string $id, string $status)
    {
        abort_if($status === 'PAID', 403, 'Confirmar pago mediante el módulo de pagos');
        abort_if($status === 'EXPIRED', 403, 'El vencimiento lo aplica el sistema');

        return Api::ok('Estado actualizado', $this->orders->transition($id, $status, $r->user()->id));
    }

    public function internalShow(string $id)
    {
        return Api::ok('Pedido', $this->orders->get($id));
    }

    public function paid(string $id)
    {
        return Api::ok('Pago confirmado', $this->orders->transition($id, 'PAID'));
    }
}
