<?php

namespace App\Modules\Orders;

use App\Modules\Notifications\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderService
{
    /** Estados que liberan la reserva de stock sin consumirla. */
    public const RELEASED = ['CANCELLED', 'EXPIRED'];

    /**
     * @param  array|null  $billing  Datos ya validados con BillingData; se guardan como copia del momento de compra.
     * @param  array|null  $delivery  Datos ya validados con DeliveryData; el costo se calcula aquí, no lo envía el cliente.
     * @param  string|null  $idempotencyKey  Repetir la misma clave devuelve el pedido ya creado en vez de crear otro.
     */
    public function create(string $customer, array $items, ?array $billing = null, ?array $delivery = null, ?string $idempotencyKey = null): array
    {
        $hash = hash('sha256', json_encode([$this->canonicalItems($items), $billing, $delivery]));
        if ($idempotencyKey && ($existing = $this->findByKey($customer, $idempotencyKey))) {
            return $this->replay($existing, $hash);
        }
        try {
            return $this->insert($customer, $items, $billing, $delivery, $idempotencyKey, $hash);
        } catch (UniqueConstraintViolationException $e) {
            // Otra petición con la misma clave ganó la carrera: su transacción ya reservó el stock.
            $existing = $idempotencyKey ? $this->findByKey($customer, $idempotencyKey) : null;
            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $hash);
        }
    }

    private function insert(string $customer, array $items, ?array $billing, ?array $delivery, ?string $idempotencyKey, string $hash): array
    {
        return DB::transaction(function () use ($customer, $items, $billing, $delivery, $idempotencyKey, $hash) {
            $id = (string) Str::uuid();
            $total = '0.00';
            $rate = Tax::rate();
            $taxable = '0.00';
            $tax = '0.00';
            $details = [];
            // Stable lock order prevents deadlocks between overlapping carts.
            usort($items, fn ($a, $b) => strcmp($a['productId'], $b['productId']));
            foreach ($items as $line) {
                $p = DB::table('products')->where('id', $line['productId'])->lockForUpdate()->first();
                abort_unless($p && $p->active && $p->stock - $p->reserved >= $line['quantity'], 409, 'Producto sin stock');
                $gross = bcmul((string) $p->price, (string) $line['quantity'], 2);
                $total = bcadd($total, $gross, 2);
                abort_if(bccomp($total, '999999999999.99', 2) > 0, 422, 'Total de pedido excedido');
                DB::table('products')->where('id', $p->id)->increment('reserved', $line['quantity']);
                [$base, $igv] = Tax::split($gross, $rate);
                $taxable = bcadd($taxable, $base, 2);
                $tax = bcadd($tax, $igv, 2);
                $details[] = ['id' => (string) Str::uuid(), 'order_id' => $id, 'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'quantity' => $line['quantity'], 'unit_price' => $p->price,
                    'line_total' => $gross, 'taxable_amount' => $base, 'tax_amount' => $igv];
            }
            $deliveryCost = $delivery ? DeliveryData::cost($delivery['method']) : '0.00';
            $total = bcadd($total, $deliveryCost, 2);
            [$base, $igv] = Tax::split($deliveryCost, $rate);
            DB::table('orders')->insert(['id' => $id, 'customer_id' => $customer, 'status' => 'PAYMENT_PENDING', 'total' => $total,
                'tax_rate' => $rate, 'taxable_amount' => bcadd($taxable, $base, 2), 'tax_amount' => bcadd($tax, $igv, 2),
                'delivery_method' => $delivery['method'] ?? null, 'delivery_cost' => $deliveryCost,
                'contact_name' => $delivery['contactName'] ?? null, 'contact_phone' => $delivery['contactPhone'] ?? null,
                'delivery_address' => $delivery['address'] ?? null, 'delivery_district' => $delivery['district'] ?? null,
                'delivery_reference' => $delivery['reference'] ?? null,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $idempotencyKey ? $hash : null,
                'billing_type' => $billing['type'] ?? null, 'billing_document_type' => $billing['documentType'] ?? null,
                'billing_document_number' => $billing['documentNumber'] ?? null, 'billing_name' => $billing['name'] ?? null,
                'billing_address' => $billing['address'] ?? null, 'expires_at' => self::reservationDeadline(),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_details')->insert($details);
            $this->record($id, $customer, 'CREATED', null, 'PAYMENT_PENDING');
            // Se resuelve aquí: Notifier también depende de este servicio.
            app(Notifier::class)->queue($id, 'ORDER_CREATED');

            return $this->get($id);
        }, 3);
    }

    private function findByKey(string $customer, string $key): ?object
    {
        return DB::table('orders')->where('customer_id', $customer)->where('idempotency_key', $key)->first();
    }

    /** Un reintento idéntico recupera el pedido; reutilizar la clave con otro contenido es un error del cliente. */
    private function replay(object $order, string $hash): array
    {
        abort_unless(hash_equals((string) $order->request_hash, $hash), 422, 'La clave de idempotencia ya se usó con otro pedido');

        return $this->get($order->id) + ['replayed' => true];
    }

    private function canonicalItems(array $items): array
    {
        $lines = array_map(fn ($line) => [(string) $line['productId'], (int) $line['quantity']], $items);
        sort($lines);

        return $lines;
    }

    /** Fin de la reserva de un pedido impago; null si ORDER_RESERVATION_HOURS es 0 (sin vencimiento). */
    public static function reservationDeadline(): ?\DateTimeInterface
    {
        $hours = (int) config('commerce.orders.reservation_hours');

        return $hours > 0 ? now()->addHours($hours) : null;
    }

    /** Historial del pedido: quién hizo qué y cuándo (actor null = sistema). */
    public function record(string $order, ?string $actor, string $type, ?string $from = null, ?string $to = null, ?string $reference = null): void
    {
        DB::table('order_events')->insert(['order_id' => $order, 'actor_id' => $actor, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'reference' => $reference, 'created_at' => now()]);
    }

    /**
     * Vence los pedidos impagos cuya reserva terminó. Cada pedido se procesa con su propio bloqueo,
     * así que un pago confirmado o una cancelación simultánea gana o pierde sin liberar dos veces.
     */
    public function expireDue(): int
    {
        $expired = 0;
        $due = DB::table('orders')->where('status', 'PAYMENT_PENDING')->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('expires_at')->limit(500)->pluck('id');
        foreach ($due as $id) {
            try {
                $this->transition($id, 'EXPIRED');
                $expired++;
            } catch (HttpException $e) {
                // Otro proceso ya lo pagó, canceló o venció.
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
            }
        }

        return $expired;
    }

    public function get(string $id): array
    {
        $o = DB::table('orders')->where('id', $id)->first();
        abort_unless($o, 404, 'Pedido no encontrado');

        $billing = $o->billing_type === null ? null : ['type' => $o->billing_type, 'documentType' => $o->billing_document_type, 'documentNumber' => $o->billing_document_number, 'name' => $o->billing_name, 'address' => $o->billing_address];

        $deliveryCost = bcadd((string) ($o->delivery_cost ?? '0'), '0', 2);
        $delivery = $o->delivery_method === null ? null : ['method' => $o->delivery_method, 'cost' => $deliveryCost, 'contactName' => $o->contact_name, 'contactPhone' => $o->contact_phone, 'address' => $o->delivery_address, 'district' => $o->delivery_district, 'reference' => $o->delivery_reference];

        return ['id' => $o->id, 'customerId' => $o->customer_id, 'status' => $o->status, 'subtotal' => bcsub((string) $o->total, $deliveryCost, 2), 'total' => bcadd((string) $o->total, '0', 2),
            'taxes' => ['rate' => bcadd((string) $o->tax_rate, '0', 4), 'taxableAmount' => bcadd((string) $o->taxable_amount, '0', 2), 'taxAmount' => bcadd((string) $o->tax_amount, '0', 2)], 'billing' => $billing, 'delivery' => $delivery, 'createdAt' => self::iso($o->created_at), 'expiresAt' => $o->status === 'PAYMENT_PENDING' ? self::iso($o->expires_at) : null, 'details' => DB::table('order_details')->where('order_id', $id)->orderBy('product_id')->get()->map(fn ($d) => ['productId' => $d->product_id, 'sku' => $d->sku, 'name' => $d->name, 'quantity' => $d->quantity, 'unitPrice' => bcadd((string) $d->unit_price, '0', 2),
                'lineTotal' => bcadd((string) $d->line_total, '0', 2), 'taxableAmount' => bcadd((string) $d->taxable_amount, '0', 2), 'taxAmount' => bcadd((string) $d->tax_amount, '0', 2)])->all()];
    }

    public static function iso(mixed $value): ?string
    {
        return $value ? date(DATE_ATOM, strtotime((string) $value)) : null;
    }

    /** @param string|null $actor Usuario que pide el cambio; null cuando lo hace el sistema. */
    public function transition(string $id, string $target, ?string $actor = null, ?string $reference = null): array
    {
        return DB::transaction(function () use ($id, $target, $actor, $reference) {
            $o = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            if ($target === 'PAID' && $o->status === 'PAID') {
                return $this->get($id);
            }
            $next = ['PAYMENT_PENDING' => 'PAID', 'PAID' => 'PROCESSING', 'PROCESSING' => 'SHIPPED', 'SHIPPED' => 'DELIVERED'];
            abort_unless(($next[$o->status] ?? null) === $target || (in_array($target, self::RELEASED, true) && $o->status === 'PAYMENT_PENDING'), 409, 'Transición de pedido inválida');
            if ($target === 'PAID' || in_array($target, self::RELEASED, true)) {
                foreach (DB::table('order_details')->where('order_id', $id)->orderBy('product_id')->get() as $d) {
                    $p = DB::table('products')->where('id', $d->product_id)->lockForUpdate()->first();
                    abort_unless($p && $p->reserved >= $d->quantity && $p->stock >= $d->quantity, 409, 'Reserva de inventario inconsistente');
                    DB::table('products')->where('id', $p->id)->update(['reserved' => $p->reserved - $d->quantity, 'stock' => $target === 'PAID' ? $p->stock - $d->quantity : $p->stock, 'updated_at' => now()]);
                }
            }
            DB::table('orders')->where('id', $id)->update(['status' => $target, 'updated_at' => now()]);
            if (in_array($target, self::RELEASED, true)) {
                // Una solicitud de pago pendiente ya no puede confirmarse.
                DB::table('payments')->where('order_id', $id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            }
            $this->record($id, $actor, $target === 'EXPIRED' ? 'EXPIRED' : ($target === 'PAID' ? 'PAYMENT_CONFIRMED' : 'STATUS_CHANGED'), $o->status, $target, $reference);
            if ($target === 'PAID') {
                app(Notifier::class)->queue($id, 'PAYMENT_CONFIRMED');
            }

            return $this->get($id);
        }, 3);
    }
}
