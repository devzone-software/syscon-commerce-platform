<?php

namespace App\Modules\Payments;

use App\Modules\Orders\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * Pagos en línea por pasarela. La pasarela confirma con un webhook firmado (HMAC-SHA256 del cuerpo
 * con el secreto compartido); antes de aplicar se valida firma, pago, pedido, importe y moneda. La
 * confirmación reutiliza la transición a PAID del pago manual (mismo orden de bloqueo pedido → pago),
 * así un webhook repetido no cobra ni descuenta stock dos veces. Reglas:
 * - approved con el pedido pendiente: pago APPROVED y pedido PAID.
 * - approved con el pedido ya cancelado o vencido: pago REFUND_REQUIRED (hay que devolver el dinero).
 * - rejected: pago REJECTED; el pedido sigue pendiente y el cliente puede intentar de nuevo.
 * Hoy la única pasarela es SANDBOX (simulada, para pruebas); una real se agrega con su propio
 * secreto y su página de pago, sin cambiar este procesamiento.
 */
class GatewayPayments
{
    public const PROVIDERS = ['SANDBOX'];

    public function __construct(private OrderService $orders) {}

    public static function enabled(string $provider): bool
    {
        return $provider === 'SANDBOX' && (bool) config('commerce.payments.sandbox.enabled') && self::secret($provider) !== '';
    }

    public static function secret(string $provider): string
    {
        return $provider === 'SANDBOX' ? (string) config('commerce.payments.sandbox.secret') : '';
    }

    public static function sign(string $provider, string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret($provider));
    }

    /** URL de la página de pago de la pasarela para este pago (firmada para que no se pueda adivinar). */
    public static function checkoutUrl(object $payment): string
    {
        return rtrim((string) config('app.url'), '/').'/sandbox/checkout/'.$payment->id.'?sig='.self::sign('SANDBOX', 'checkout:'.$payment->id);
    }

    /**
     * Aplica un webhook ya autenticado.
     *
     * @param  array{paymentId: string, orderId: string, amount: string, currency: string, status: string, transactionId: string}  $event
     */
    public function apply(string $provider, array $event): object
    {
        $orderId = DB::table('payments')->where('id', $event['paymentId'])->value('order_id');
        abort_unless($orderId, 404, 'Pago no encontrado');

        return DB::transaction(function () use ($provider, $event, $orderId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $p = DB::table('payments')->where('id', $event['paymentId'])->lockForUpdate()->first();
            abort_unless($p->provider === $provider && $order->id === $event['orderId'], 422, 'El pago no corresponde al pedido');
            abort_unless($event['currency'] === 'PEN' && bccomp(bcadd($event['amount'], '0', 2), (string) $p->amount, 2) === 0, 422, 'Importe o moneda distintos al pago');
            // Reintento de la pasarela: el resultado ya se aplicó.
            if ($p->status !== 'PENDING') {
                return $p;
            }
            if ($event['status'] === 'rejected') {
                DB::table('payments')->where('id', $p->id)->update(['status' => 'REJECTED', 'external_reference' => $event['transactionId'], 'updated_at' => now()]);
                $this->orders->record($order->id, null, 'PAYMENT_REJECTED', null, null, $event['transactionId']);
            } elseif ($order->status !== 'PAYMENT_PENDING') {
                DB::table('payments')->where('id', $p->id)->update(['status' => 'REFUND_REQUIRED', 'external_reference' => $event['transactionId'], 'confirmed_at' => now(), 'updated_at' => now()]);
                $this->orders->record($order->id, null, 'PAYMENT_REFUND_REQUIRED', null, null, $event['transactionId']);
            } else {
                $this->orders->transition($order->id, 'PAID', null, $event['transactionId']);
                DB::table('payments')->where('id', $p->id)->update(['status' => 'APPROVED', 'external_reference' => $event['transactionId'], 'confirmed_at' => now(), 'updated_at' => now()]);
            }

            return DB::table('payments')->where('id', $p->id)->first();
        }, 3);
    }
}
