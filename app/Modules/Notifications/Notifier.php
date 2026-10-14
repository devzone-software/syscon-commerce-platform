<?php

namespace App\Modules\Notifications;

use App\Modules\Invoices\InvoiceFiles;
use App\Modules\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Correos al cliente con bandeja de salida: `queue` se llama dentro de la transacción de la
 * operación y el envío ocurre después del commit (o en el cron si falla). Enviar nunca lanza
 * excepciones hacia la operación comercial.
 */
class Notifier
{
    public const TYPES = ['ORDER_CREATED', 'PAYMENT_CONFIRMED', 'INVOICE_AVAILABLE'];

    /** Intentos automáticos antes de dejar el aviso para reenvío manual. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private OrderService $orders, private InvoiceFiles $files) {}

    /** Registra el aviso (una sola vez por pedido, tipo y comprobante) y lo envía tras el commit. */
    public function queue(string $orderId, string $type, string $reference = ''): void
    {
        $recipient = DB::table('orders')->join('users', 'users.id', '=', 'orders.customer_id')->where('orders.id', $orderId)->value('users.email');
        if (! $recipient) {
            return;
        }
        $id = (string) Str::uuid();
        $inserted = DB::table('notifications')->insertOrIgnore(['id' => $id, 'order_id' => $orderId, 'type' => $type, 'reference' => $reference, 'recipient' => $recipient,
            'status' => 'PENDING', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            DB::afterCommit(fn () => $this->deliver($id));
        }
    }

    /** Envía los avisos pendientes o con reintento vencido; lo ejecuta el scheduler. */
    public function sendDue(): int
    {
        $sent = 0;
        $due = DB::table('notifications')->where(fn ($q) => $q->whereIn('status', ['PENDING', 'FAILED'])->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', 'SENDING')->where('locked_until', '<', now()))
            ->orderBy('created_at')->limit(50)->pluck('id');
        foreach ($due as $id) {
            $sent += $this->deliver($id) ? 1 : 0;
        }

        return $sent;
    }

    /** Reenvío manual: vuelve a intentar sin tocar el pedido, incluso si ya se había enviado. */
    public function resend(string $id, ?string $actor): array
    {
        $n = DB::table('notifications')->where('id', $id)->first();
        abort_unless($n, 404, 'Aviso no encontrado');
        abort_if($n->status === 'SENDING' && $n->locked_until && now()->lt($n->locked_until), 409, 'El aviso se está enviando');
        DB::table('notifications')->where('id', $id)->update(['status' => 'PENDING', 'next_attempt_at' => now(), 'locked_until' => null, 'updated_at' => now()]);
        $this->orders->record($n->order_id, $actor, 'NOTIFICATION_RESENT', null, null, $n->type);
        $this->deliver($id);

        return self::view(DB::table('notifications')->where('id', $id)->first());
    }

    /** Toma el aviso con una actualización condicional (un solo proceso lo envía) y lo manda. */
    public function deliver(string $id): bool
    {
        $claimed = DB::table('notifications')->where('id', $id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['PENDING', 'FAILED'])->where('next_attempt_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'SENDING')->where('locked_until', '<', now())))
            ->update(['status' => 'SENDING', 'locked_until' => now()->addMinutes(10), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return false;
        }
        $n = DB::table('notifications')->where('id', $id)->first();
        try {
            Mail::to($n->recipient)->send($this->mail($n));
            DB::table('notifications')->where('id', $id)->update(['status' => 'SENT', 'sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null, 'locked_until' => null, 'updated_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            // Espera creciente: 5, 20, 45 y 80 minutos; luego solo reenvío manual.
            $retry = $n->attempts < self::MAX_ATTEMPTS ? now()->addMinutes(5 * $n->attempts ** 2) : null;
            DB::table('notifications')->where('id', $id)->update(['status' => 'FAILED', 'last_error' => mb_substr($e->getMessage(), 0, 1000), 'next_attempt_at' => $retry, 'locked_until' => null, 'updated_at' => now()]);
            Log::warning('Aviso por correo no enviado', ['notification' => $id, 'type' => $n->type, 'attempts' => $n->attempts, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function mail(object $n): OrderMail
    {
        $order = $this->orders->get($n->order_id);
        $invoice = $n->type === 'INVOICE_AVAILABLE' ? DB::table('invoices')->where('id', $n->reference)->first() : null;
        $pdf = $invoice && $invoice->status === 'ACCEPTED' ? $this->files->pdf($invoice)->getContent() : null;

        return new OrderMail($n->type, $order, $invoice, $pdf);
    }

    public static function view(object $n): array
    {
        return ['id' => $n->id, 'type' => $n->type, 'reference' => $n->reference ?: null, 'recipient' => $n->recipient, 'status' => $n->status, 'attempts' => (int) $n->attempts,
            'lastError' => $n->last_error, 'nextAttemptAt' => OrderService::iso($n->next_attempt_at), 'sentAt' => OrderService::iso($n->sent_at), 'createdAt' => OrderService::iso($n->created_at)];
    }
}
