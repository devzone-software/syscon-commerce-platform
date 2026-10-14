<?php

namespace App\Modules\Payments;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Pasarela simulada (PAYMENT_SANDBOX=true): su página de pago y su webhook. La página hace lo que
 * haría una pasarela real: arma el evento, lo firma con el secreto y lo entrega al webhook, luego
 * devuelve al cliente a la tienda. Nunca mueve dinero.
 */
class SandboxController
{
    public function __construct(private GatewayPayments $payments) {}

    /** Webhook: el cuerpo debe venir firmado en X-Signature. */
    public function webhook(Request $r)
    {
        abort_unless(GatewayPayments::enabled('SANDBOX'), 404);

        return Api::ok('Evento procesado', PaymentController::view($this->process($r->getContent(), (string) $r->header('X-Signature'))));
    }

    /** Verifica la firma y el contenido del evento y lo aplica. */
    private function process(string $body, string $signature): object
    {
        abort_unless(hash_equals(GatewayPayments::sign('SANDBOX', $body), $signature), 401, 'Firma inválida');
        $event = Validator::make((array) json_decode($body, true), ['paymentId' => 'required|uuid', 'orderId' => 'required|uuid', 'amount' => 'required|decimal:0,2', 'currency' => 'required|string|size:3',
            'status' => 'required|in:approved,rejected', 'transactionId' => 'required|string|max:100'])->validate();

        return $this->payments->apply('SANDBOX', $event);
    }

    public function checkout(Request $r, string $id)
    {
        $payment = $this->authorized($r, $id);
        $order = DB::table('orders')->where('id', $payment->order_id)->first();
        $link = fn (string $decision) => url('/sandbox/checkout/'.$id.'/'.$decision).'?sig='.$r->query('sig');
        $done = $payment->status !== 'PENDING';
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return response('<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pasarela de prueba</title>'
            .'<style>body{font-family:Arial,sans-serif;background:#f4f6fa;margin:0;padding:40px 16px;color:#111c2d}main{max-width:420px;margin:auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 10px #0001}'
            .'.tag{display:inline-block;background:#fff4d6;color:#7a5300;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:bold}a.b{display:block;text-align:center;padding:14px;border-radius:8px;margin-top:12px;text-decoration:none;font-weight:bold}'
            .'.ok{background:#0d7a3f;color:#fff}.no{background:#fff;color:#b00020;border:1px solid #b00020}</style></head><body><main>'
            .'<span class="tag">PASARELA DE PRUEBA · sin dinero real</span><h1 style="font-size:20px">Pagar pedido '.$h(strtoupper(substr($order->id, 0, 8))).'</h1>'
            .'<p style="font-size:28px;font-weight:bold;margin:8px 0">S/ '.$h(number_format((float) $payment->amount, 2)).'</p>'
            .($done ? '<p>Este pago ya fue procesado: <strong>'.$h($payment->status).'</strong>.</p><a class="b ok" href="'.$h($this->returnUrl($order->id)).'">Volver a la tienda</a>'
                : '<p>Elige el resultado que devolverá la pasarela simulada.</p><a class="b ok" href="'.$h($link('approve')).'">Aprobar pago</a><a class="b no" href="'.$h($link('reject')).'">Rechazar pago</a>')
            .'</main></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Simula la decisión de la pasarela: firma el evento, lo entrega al webhook y vuelve a la tienda. */
    public function decide(Request $r, string $id, string $decision)
    {
        $payment = $this->authorized($r, $id);
        $event = json_encode(['paymentId' => $payment->id, 'orderId' => $payment->order_id, 'amount' => bcadd((string) $payment->amount, '0', 2), 'currency' => 'PEN',
            'status' => $decision === 'approve' ? 'approved' : 'rejected', 'transactionId' => 'SBX-'.strtoupper(Str::random(10))]);
        // Mismo camino que una notificación externa: cuerpo firmado, verificado y aplicado.
        $this->process($event, GatewayPayments::sign('SANDBOX', $event));

        return redirect()->away($this->returnUrl($payment->order_id));
    }

    private function authorized(Request $r, string $id): object
    {
        abort_unless(GatewayPayments::enabled('SANDBOX'), 404);
        abort_unless(hash_equals(GatewayPayments::sign('SANDBOX', 'checkout:'.$id), (string) $r->query('sig')), 403, 'Enlace de pago inválido');
        $payment = DB::table('payments')->where('id', $id)->where('provider', 'SANDBOX')->first();
        abort_unless($payment, 404);

        return $payment;
    }

    private function returnUrl(string $orderId): string
    {
        return rtrim((string) config('commerce.web_url'), '/').'/pedido/'.$orderId.'?pago=retorno';
    }
}
