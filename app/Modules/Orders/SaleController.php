<?php

namespace App\Modules\Orders;

use App\Models\User;
use App\Modules\Payments\PaymentController;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Venta directa registrada por el personal (canal STORE): pedido, cobro y entrega en una sola
 * operación. Reutiliza la creación de pedidos (precios, stock e IGV del servidor, idempotencia) y
 * la transición a pagado (consumo de stock); el pago queda aprobado por quien vende.
 */
class SaleController
{
    /** Medios de cobro en tienda; todos salvo efectivo requieren la referencia de la operación. */
    public const METHODS = ['CASH', 'CARD', 'YAPE', 'PLIN', 'TRANSFER'];

    /** Rol de la cuenta genérica de mostrador: sin permisos ni correos, no inicia sesión. */
    public const WALK_IN_ROLE = 'WALK_IN';

    public function __construct(private OrderService $orders) {}

    public function store(Request $r)
    {
        $input = $r->all();
        $input['billing'] = is_array($input['billing'] ?? null) ? BillingData::normalize($input['billing']) : ($input['billing'] ?? null);
        $validator = Validator::make($input, [
            'items' => 'required|array|min:1|max:100', 'items.*.productId' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'customerId' => 'nullable|uuid', 'billing' => 'required|array',
            'payment' => 'required|array', 'payment.method' => 'required|in:'.implode(',', self::METHODS),
            'payment.reference' => 'nullable|required_unless:payment.method,CASH|string|max:255',
            'idempotencyKey' => 'required|uuid',
        ] + BillingData::rules('billing.'));
        $validator->after(fn ($v) => is_array($input['billing']) && BillingData::check($v, $input['billing'], 'billing.'));
        $data = $validator->validate();
        $seller = $r->user()->id;
        $customer = $this->customer($data['customerId'] ?? null);

        return DB::transaction(function () use ($data, $input, $seller, $customer) {
            $order = $this->orders->create($customer, $data['items'], $data['billing'], null, strtolower($data['idempotencyKey']), $seller);
            // Reintento de la misma venta: ya se cobró y entregó, se devuelve tal cual.
            if ($order['replayed'] ?? false) {
                unset($order['replayed']);

                return Api::ok('Venta ya registrada', $this->view($order['id']));
            }
            $method = $data['payment']['method'];
            $reference = trim((string) ($input['payment']['reference'] ?? '')) ?: null;
            $paymentId = (string) Str::uuid();
            DB::table('payments')->insert(['id' => $paymentId, 'order_id' => $order['id'], 'customer_id' => $customer, 'provider' => $method, 'status' => 'APPROVED',
                'amount' => $order['total'], 'external_reference' => $reference, 'confirmed_by' => $seller, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->orders->transition($order['id'], 'PAID', $seller, $reference ?? $method);
            // El cliente se lleva los productos: el pedido queda entregado.
            DB::table('orders')->where('id', $order['id'])->update(['status' => 'DELIVERED', 'updated_at' => now()]);
            $this->orders->record($order['id'], $seller, 'STATUS_CHANGED', 'PAID', 'DELIVERED', 'Entregado en tienda');

            return Api::ok('Venta registrada', $this->view($order['id']));
        }, 3);
    }

    /** Clientes registrados para asociar a la venta, por correo, nombre o documento. */
    public function customers(Request $r)
    {
        $q = trim((string) $r->validate(['q' => 'required|string|min:2|max:120'])['q']);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        return Api::ok('Clientes', DB::table('users')->where('role', 'CUSTOMER')
            ->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('document_number', strtoupper($q)))
            ->orderBy('email')->limit(20)->get()
            ->map(fn ($u) => ['id' => $u->id, 'email' => $u->email, 'name' => trim($u->first_name.' '.$u->last_name) ?: null, 'documentType' => $u->document_type, 'documentNumber' => $u->document_number, 'phone' => $u->phone])->all());
    }

    private function customer(?string $id): string
    {
        if ($id) {
            abort_unless(DB::table('users')->where('id', $id)->where('role', 'CUSTOMER')->exists(), 422, 'Cliente no encontrado');

            return $id;
        }
        $email = (string) config('commerce.walk_in_email');

        return DB::table('users')->where('email', $email)->value('id')
            ?? User::create(['email' => $email, 'password' => Str::random(64), 'role' => self::WALK_IN_ROLE])->id;
    }

    private function view(string $orderId): array
    {
        $order = $this->orders->get($orderId);
        $payment = DB::table('payments')->where('order_id', $orderId)->where('status', 'APPROVED')->first();

        return $order + ['payment' => $payment ? PaymentController::view($payment) : null];
    }
}
