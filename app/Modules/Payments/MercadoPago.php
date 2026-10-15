<?php

namespace App\Modules\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago Checkout Pro. Por cada pago se crea una preferencia (external_reference = id del pago)
 * y el cliente paga en la página de Mercado Pago. El resultado nunca se toma de la notificación ni de
 * la URL de retorno: siempre se consulta el pago en la API con el access token y recién entonces se
 * aplica con GatewayPayments, igual que la pasarela simulada.
 */
class MercadoPago
{
    public const PROVIDER = 'MERCADOPAGO';

    public static function enabled(): bool
    {
        return self::token() !== '';
    }

    private static function token(): string
    {
        return (string) config('commerce.payments.mercadopago.access_token');
    }

    private static function api(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('commerce.payments.mercadopago.api_url'), '/'))
            ->acceptJson()->withToken(self::token())->connectTimeout(5)->timeout(15);
    }

    /** URL de retorno en esta API; desde ahí se consulta el pago y se devuelve al cliente a la tienda. */
    public static function returnUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/payments/mercadopago/return';
    }

    /**
     * Crea la preferencia y devuelve su init_point. La preferencia vence con la reserva del pedido:
     * después Mercado Pago no deja pagar un pedido cuyo stock ya se liberó.
     *
     * @throws PaymentGatewayUnavailable
     */
    public function createPreference(object $payment, object $order): string
    {
        $return = self::returnUrl();
        // Mercado Pago solo notifica y redirige automáticamente a URLs públicas con HTTPS.
        $public = str_starts_with($return, 'https://');
        $body = array_filter([
            'items' => [['id' => $order->id, 'title' => 'Pedido SYSCON '.strtoupper(substr($order->id, 0, 8)), 'quantity' => 1,
                'unit_price' => (float) $payment->amount, 'currency_id' => 'PEN']],
            'external_reference' => $payment->id,
            'back_urls' => ['success' => $return, 'failure' => $return, 'pending' => $return],
            'auto_return' => $public ? 'approved' : null,
            'notification_url' => $public ? rtrim((string) config('app.url'), '/').'/api/payments/webhooks/mercadopago' : null,
            // Solo aprobado o rechazado: sin pagos "pendientes" que lleguen cuando la reserva ya venció.
            'binary_mode' => true,
            'statement_descriptor' => 'SYSCON',
            'expires' => $order->expires_at !== null,
            'expiration_date_to' => $order->expires_at !== null ? Carbon::parse($order->expires_at, 'UTC')->format('Y-m-d\TH:i:s.vP') : null,
        ], fn ($v) => $v !== null);

        try {
            $response = self::api()->withHeaders(['X-Idempotency-Key' => 'preference-'.$payment->id])->post('/checkout/preferences', $body);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayUnavailable('Mercado Pago no respondió: '.$e->getMessage());
        }
        $url = $response->json('init_point');
        if (! $response->successful() || ! is_string($url) || $url === '') {
            throw new PaymentGatewayUnavailable('Mercado Pago respondió '.$response->status().': '.$response->json('message', 'sin init_point'));
        }

        return $url;
    }

    /**
     * Consulta un pago de Mercado Pago y lo traduce al evento de GatewayPayments. Devuelve null si
     * todavía no hay un resultado final que aplicar (en proceso, o rechazado: el cliente puede
     * reintentar dentro del mismo checkout de Mercado Pago).
     *
     * @return array{paymentId: string, amount: string, currency: string, status: string, transactionId: string}|null
     *
     * @throws PaymentGatewayUnavailable
     */
    public function fetch(string $mercadoPagoId): ?array
    {
        try {
            $response = self::api()->get('/v1/payments/'.rawurlencode($mercadoPagoId));
        } catch (ConnectionException $e) {
            throw new PaymentGatewayUnavailable('Mercado Pago no respondió: '.$e->getMessage());
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new PaymentGatewayUnavailable('Mercado Pago respondió '.$response->status().' al consultar el pago.');
        }
        if ($response->json('status') !== 'approved' || ! is_string($response->json('external_reference'))) {
            return null;
        }

        return [
            'paymentId' => $response->json('external_reference'),
            'amount' => (string) $response->json('transaction_amount'),
            'currency' => (string) $response->json('currency_id'),
            'status' => 'approved',
            'transactionId' => 'MP-'.$response->json('id'),
        ];
    }

    /**
     * Firma del webhook: x-signature "ts=…,v1=…" con HMAC-SHA256 (hex) de
     * "id:{data.id};request-id:{x-request-id};ts:{ts};" y la clave secreta de la aplicación.
     */
    public static function validSignature(Request $r, string $dataId): bool
    {
        $secret = (string) config('commerce.payments.mercadopago.webhook_secret');
        $parts = [];
        foreach (explode(',', (string) $r->header('x-signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }
        if ($secret === '' || ($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') {
            return false;
        }
        $manifest = 'id:'.$dataId.';'.(($requestId = (string) $r->header('x-request-id')) !== '' ? 'request-id:'.$requestId.';' : '').'ts:'.$parts['ts'].';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
    }
}
