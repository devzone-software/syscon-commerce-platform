<?php

namespace App\Modules\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Webhook y retorno de Mercado Pago. Ambos solo dicen "mira el pago N": el estado, importe y
 * referencia se consultan en la API de Mercado Pago antes de aplicar nada.
 */
class MercadoPagoController
{
    public function __construct(private MercadoPago $mercadoPago, private GatewayPayments $payments) {}

    /** Notificación de Mercado Pago (?type=payment&data.id=N, firmada en x-signature). */
    public function webhook(Request $r)
    {
        abort_unless(MercadoPago::enabled(), 404);
        // PHP convierte "data.id" de la query en "data_id".
        $dataId = (string) ($r->query('data_id') ?? $r->input('data.id', ''));
        abort_unless(MercadoPago::validSignature($r, $dataId), 401, 'Firma inválida');
        if (($r->query('type') ?? $r->input('type')) !== 'payment' || $dataId === '') {
            return response()->json(['message' => 'Evento ignorado']);
        }
        try {
            $this->sync($dataId);
        } catch (PaymentGatewayUnavailable $e) {
            // Mercado Pago reintenta la notificación si no recibe 200/201.
            Log::warning('Webhook de Mercado Pago sin procesar', ['payment' => $dataId, 'error' => $e->getMessage()]);
            abort(503, 'Mercado Pago no respondió; se reintentará.');
        } catch (HttpException $e) {
            // Importe, moneda o pedido distintos: reintentar no lo arregla; queda en el log para revisarlo.
            Log::error('Pago de Mercado Pago no aplicado', ['payment' => $dataId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Evento rechazado: '.$e->getMessage()]);
        }

        return response()->json(['message' => 'Evento procesado']);
    }

    /** El cliente vuelve de Mercado Pago: se concilia el pago y se lo lleva a su pedido en la tienda. */
    public function back(Request $r)
    {
        abort_unless(MercadoPago::enabled(), 404);
        $mpId = (string) $r->query('payment_id', '');
        if (preg_match('/^[0-9]{1,20}$/', $mpId)) {
            try {
                $this->sync($mpId);
            } catch (PaymentGatewayUnavailable|HttpException $e) {
                // El webhook lo aplicará después; la tienda muestra "aún sin confirmación".
                Log::warning('Retorno de Mercado Pago sin conciliar', ['payment' => $mpId, 'error' => $e->getMessage()]);
            }
        }
        $orderId = DB::table('payments')->where('id', (string) $r->query('external_reference', ''))->where('provider', MercadoPago::PROVIDER)->value('order_id');
        $web = rtrim((string) config('commerce.web_url'), '/');

        return redirect()->away($orderId ? $web.'/pedido/'.$orderId.'?pago=retorno' : $web.'/cuenta/pedidos');
    }

    /** Consulta el pago en Mercado Pago y, si está aprobado y es nuestro, lo aplica. */
    private function sync(string $mercadoPagoId): void
    {
        $event = $this->mercadoPago->fetch($mercadoPagoId);
        if ($event === null) {
            return;
        }
        $orderId = DB::table('payments')->where('id', $event['paymentId'])->where('provider', MercadoPago::PROVIDER)->value('order_id');
        if (! $orderId) {
            Log::warning('Pago de Mercado Pago sin pago local', ['payment' => $mercadoPagoId, 'external_reference' => $event['paymentId']]);

            return;
        }
        $this->payments->apply(MercadoPago::PROVIDER, ['orderId' => $orderId] + $event);
    }
}
