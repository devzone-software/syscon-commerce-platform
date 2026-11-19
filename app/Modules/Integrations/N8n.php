<?php

namespace App\Modules\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Envía eventos del negocio a un Webhook de n8n (POST JSON). Con N8N_WEBHOOK_URL vacío no hace nada.
 *
 * Igual que los correos: `emit` se llama dentro de la transacción, el envío ocurre tras el commit y
 * los fallos se reintentan desde el scheduler (`integrations:send`). Nunca lanza excepciones hacia
 * la operación comercial: si n8n está caído, la venta sigue.
 */
class N8n
{
    public const EVENTS = ['order.created', 'order.paid', 'invoice.accepted', 'complaint.created', 'test.ping'];

    public const MAX_ATTEMPTS = 6;

    public static function enabled(): bool
    {
        return (string) config('commerce.n8n.url') !== '';
    }

    /** Registra el evento (una vez por recurso) y lo envía después del commit. */
    public function emit(string $event, string $subject, array $data): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $id = (string) Str::uuid();
            $payload = ['id' => $id, 'event' => $event, 'occurredAt' => now()->toIso8601String(), 'source' => config('app.name'), 'data' => $data];
            $inserted = DB::table('integration_events')->insertOrIgnore(['id' => $id, 'event' => $event, 'subject' => $subject,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'PENDING',
                'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                DB::afterCommit(fn () => $this->deliver($id));
            }
        } catch (\Throwable $e) {
            Log::warning('Evento n8n no registrado', ['event' => $event, 'subject' => $subject, 'error' => $e->getMessage()]);
        }
    }

    /** Envía los pendientes y reintenta los fallidos cuyo plazo venció; lo ejecuta el scheduler. */
    public function sendDue(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $sent = 0;
        $due = DB::table('integration_events')->where(fn ($q) => $q->whereIn('status', ['PENDING', 'FAILED'])->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', 'SENDING')->where('locked_until', '<', now()))
            ->orderBy('created_at')->limit(50)->pluck('id');
        foreach ($due as $id) {
            $sent += $this->deliver($id) ? 1 : 0;
        }

        return $sent;
    }

    /** Toma el evento con una actualización condicional (un solo proceso lo envía) y hace el POST. */
    public function deliver(string $id): bool
    {
        $claimed = DB::table('integration_events')->where('id', $id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['PENDING', 'FAILED'])->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'SENDING')->where('locked_until', '<', now())))
            ->update(['status' => 'SENDING', 'locked_until' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return false;
        }
        $e = DB::table('integration_events')->where('id', $id)->first();
        try {
            $response = Http::timeout((int) config('commerce.n8n.timeout', 5))->acceptJson()
                ->withHeaders(['X-Syscon-Token' => (string) config('commerce.n8n.token'), 'X-Syscon-Event' => $e->event, 'X-Syscon-Delivery' => $e->id])
                ->withBody($e->payload, 'application/json')
                ->post((string) config('commerce.n8n.url'));
            if (! $response->successful()) {
                throw new \RuntimeException('n8n respondió HTTP '.$response->status());
            }
            DB::table('integration_events')->where('id', $id)->update(['status' => 'SENT', 'sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null, 'locked_until' => null, 'updated_at' => now()]);

            return true;
        } catch (\Throwable $ex) {
            // Espera creciente: 1, 4, 9, 16 y 25 minutos; luego queda como fallido definitivo.
            $retry = $e->attempts < self::MAX_ATTEMPTS ? now()->addMinutes($e->attempts ** 2) : null;
            DB::table('integration_events')->where('id', $id)->update(['status' => 'FAILED', 'last_error' => mb_substr($ex->getMessage(), 0, 1000), 'next_attempt_at' => $retry, 'locked_until' => null, 'updated_at' => now()]);
            Log::warning('Evento n8n no enviado', ['event' => $e->event, 'id' => $id, 'attempts' => $e->attempts, 'error' => $ex->getMessage()]);

            return false;
        }
    }

    /** Resumen del pedido para n8n: lo justo para avisar o registrar, sin datos de pago. */
    public static function order(string $orderId): array
    {
        $o = DB::table('orders')->leftJoin('users', 'users.id', '=', 'orders.customer_id')->where('orders.id', $orderId)
            ->first(['orders.*', 'users.email as customer_email']);
        if (! $o) {
            return ['orderId' => $orderId];
        }
        $web = rtrim((string) config('commerce.web_url'), '/');

        return ['orderId' => $o->id, 'status' => $o->status, 'channel' => $o->channel ?? 'WEB', 'total' => bcadd((string) $o->total, '0', 2), 'currency' => 'PEN',
            'customerEmail' => $o->customer_email, 'contactName' => $o->contact_name ?? $o->billing_name, 'contactPhone' => $o->contact_phone,
            'deliveryMethod' => $o->delivery_method, 'billingType' => $o->billing_type,
            'items' => DB::table('order_details')->where('order_id', $o->id)->orderBy('sku')->get()
                ->map(fn ($d) => ['sku' => $d->sku, 'name' => $d->name, 'quantity' => (int) $d->quantity, 'lineTotal' => bcadd((string) $d->line_total, '0', 2)])->all(),
            'url' => $web === '' ? null : $web.'/pedido/'.$o->id];
    }
}
