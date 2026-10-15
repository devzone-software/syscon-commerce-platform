<?php

namespace App\Modules\Payments;

use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController
{
    public function __construct(private OrderService $orders, private MercadoPago $mercadoPago) {}

    public function store(Request $r)
    {
        $data = $r->validate(['orderId' => 'required|uuid', 'provider' => 'required|in:MANUAL,'.implode(',', GatewayPayments::PROVIDERS)]);
        abort_if($data['provider'] !== 'MANUAL' && ! GatewayPayments::enabled($data['provider']), 422, 'Medio de pago no disponible');

        $p = DB::transaction(function () use ($r, $data) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless($o->customer_id === $r->user()->id, 403, 'Pedido ajeno');
            abort_unless($o->status === 'PAYMENT_PENDING', 409, 'Pedido no pendiente');
            // Pasarela: un intento rechazado no se reutiliza, se abre otro. Manual: una solicitud por pedido.
            $existing = DB::table('payments')->where('order_id', $o->id)->where('provider', $data['provider']);
            $p = $data['provider'] === 'MANUAL' ? $existing->first() : $existing->where('status', 'PENDING')->first();
            if (! $p) {
                $id = (string) Str::uuid();
                DB::table('payments')->insert(['id' => $id, 'order_id' => $o->id, 'customer_id' => $o->customer_id, 'provider' => $data['provider'], 'status' => 'PENDING',
                    'attempt' => (int) DB::table('payments')->where('order_id', $o->id)->where('provider', $data['provider'])->max('attempt') + 1, 'amount' => $o->total, 'created_at' => now(), 'updated_at' => now()]);
                $p = DB::table('payments')->where('id', $id)->first();
                $this->orders->record($o->id, $r->user()->id, 'PAYMENT_REQUESTED', null, null, $id);
            }

            return $p;
        }, 3);
        // La preferencia se crea fuera de la transacción para no bloquear el pedido mientras responde
        // Mercado Pago; si falla, el pago queda pendiente sin enlace y el siguiente intento la vuelve a pedir.
        if ($p->provider === MercadoPago::PROVIDER && $p->status === 'PENDING' && ! $p->payment_url) {
            try {
                $url = $this->mercadoPago->createPreference($p, DB::table('orders')->where('id', $p->order_id)->first());
            } catch (PaymentGatewayUnavailable $e) {
                Log::warning('No se pudo crear la preferencia de Mercado Pago', ['payment' => $p->id, 'error' => $e->getMessage()]);
                abort(502, 'Mercado Pago no respondió; intenta de nuevo en unos minutos.');
            }
            DB::table('payments')->where('id', $p->id)->update(['payment_url' => $url, 'updated_at' => now()]);
            $p = DB::table('payments')->where('id', $p->id)->first();
        }

        return Api::ok('Pago creado', self::view($p));
    }

    public function show(Request $r, string $id)
    {
        $p = DB::table('payments')->where('id', $id)->first();
        abort_unless($p, 404, 'Pago no encontrado');
        abort_unless($p->customer_id === $r->user()->id || in_array('payments:confirm', $r->user()->permissions(), true), 403, 'Pago ajeno');

        return Api::ok('Pago', self::view($p));
    }

    /** Medios de pago que el checkout puede ofrecer. */
    public function methods()
    {
        return Api::ok('Medios de pago', ['manual' => true, 'gateways' => array_values(array_filter(GatewayPayments::PROVIDERS, fn ($p) => GatewayPayments::enabled($p)))]);
    }

    public function confirm(Request $r, string $id)
    {
        $data = $r->validate(['reference' => 'required|string|max:255']);

        return DB::transaction(function () use ($r, $id, $data) {
            // Mismo orden de bloqueo que cancelar o vencer (pedido y luego pago) para no cruzarse.
            $orderId = DB::table('payments')->where('id', $id)->value('order_id');
            abort_unless($orderId, 404, 'Pago no encontrado');
            DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $p = DB::table('payments')->where('id', $id)->lockForUpdate()->first();
            if ($p->status === 'APPROVED') {
                return Api::ok('Pago confirmado', self::view($p));
            }
            abort_unless($p->provider === 'MANUAL' && $p->status === 'PENDING', 409, 'Pago no pendiente');
            $this->orders->transition($p->order_id, 'PAID', $r->user()->id, $data['reference']);
            DB::table('payments')->where('id', $id)->update(['status' => 'APPROVED', 'external_reference' => $data['reference'], 'confirmed_by' => $r->user()->id, 'confirmed_at' => now(), 'updated_at' => now()]);

            return Api::ok('Pago confirmado', self::view(DB::table('payments')->where('id', $id)->first()));
        }, 3);
    }

    public static function view(object $p): array
    {
        $instructions = trim((string) config('commerce.payments.manual_instructions'));

        return ['id' => $p->id, 'orderId' => $p->order_id, 'customerId' => $p->customer_id, 'provider' => $p->provider, 'status' => $p->status, 'amount' => bcadd((string) $p->amount, '0', 2), 'externalReference' => $p->external_reference,
            'paymentUrl' => match (true) {
                $p->status !== 'PENDING' => null,
                $p->provider === 'SANDBOX' => GatewayPayments::checkoutUrl($p),
                default => $p->payment_url,
            },
            'instructions' => $p->provider === 'MANUAL' && $p->status === 'PENDING' && $instructions !== '' ? $instructions : null,
            'confirmedAt' => OrderService::iso($p->confirmed_at ?? null), 'createdAt' => OrderService::iso($p->created_at ?? null)];
    }
}
