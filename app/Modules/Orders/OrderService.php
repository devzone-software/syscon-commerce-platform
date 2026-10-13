<?php

namespace App\Modules\Orders;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
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
            $details = [];
            // Stable lock order prevents deadlocks between overlapping carts.
            usort($items, fn ($a, $b) => strcmp($a['productId'], $b['productId']));
            foreach ($items as $line) {
                $p = DB::table('products')->where('id', $line['productId'])->lockForUpdate()->first();
                abort_unless($p && $p->active && $p->stock - $p->reserved >= $line['quantity'], 409, 'Producto sin stock');
                $total = bcadd($total, bcmul((string) $p->price, (string) $line['quantity'], 2), 2);
                abort_if(bccomp($total, '999999999999.99', 2) > 0, 422, 'Total de pedido excedido');
                DB::table('products')->where('id', $p->id)->increment('reserved', $line['quantity']);
                $details[] = ['id' => (string) Str::uuid(), 'order_id' => $id, 'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'quantity' => $line['quantity'], 'unit_price' => $p->price];
            }
            $deliveryCost = $delivery ? DeliveryData::cost($delivery['method']) : '0.00';
            $total = bcadd($total, $deliveryCost, 2);
            DB::table('orders')->insert(['id' => $id, 'customer_id' => $customer, 'status' => 'PAYMENT_PENDING', 'total' => $total,
                'delivery_method' => $delivery['method'] ?? null, 'delivery_cost' => $deliveryCost,
                'contact_name' => $delivery['contactName'] ?? null, 'contact_phone' => $delivery['contactPhone'] ?? null,
                'delivery_address' => $delivery['address'] ?? null, 'delivery_district' => $delivery['district'] ?? null,
                'delivery_reference' => $delivery['reference'] ?? null,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $idempotencyKey ? $hash : null,
                'billing_type' => $billing['type'] ?? null, 'billing_document_type' => $billing['documentType'] ?? null,
                'billing_document_number' => $billing['documentNumber'] ?? null, 'billing_name' => $billing['name'] ?? null,
                'billing_address' => $billing['address'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_details')->insert($details);

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

    public function get(string $id): array
    {
        $o = DB::table('orders')->where('id', $id)->first();
        abort_unless($o, 404, 'Pedido no encontrado');

        $billing = $o->billing_type === null ? null : ['type' => $o->billing_type, 'documentType' => $o->billing_document_type, 'documentNumber' => $o->billing_document_number, 'name' => $o->billing_name, 'address' => $o->billing_address];

        $deliveryCost = bcadd((string) ($o->delivery_cost ?? '0'), '0', 2);
        $delivery = $o->delivery_method === null ? null : ['method' => $o->delivery_method, 'cost' => $deliveryCost, 'contactName' => $o->contact_name, 'contactPhone' => $o->contact_phone, 'address' => $o->delivery_address, 'district' => $o->delivery_district, 'reference' => $o->delivery_reference];

        return ['id' => $o->id, 'customerId' => $o->customer_id, 'status' => $o->status, 'subtotal' => bcsub((string) $o->total, $deliveryCost, 2), 'total' => bcadd((string) $o->total, '0', 2), 'billing' => $billing, 'delivery' => $delivery, 'createdAt' => $o->created_at ? date(DATE_ATOM, strtotime((string) $o->created_at)) : null, 'details' => DB::table('order_details')->where('order_id', $id)->orderBy('product_id')->get()->map(fn ($d) => ['productId' => $d->product_id, 'sku' => $d->sku, 'name' => $d->name, 'quantity' => $d->quantity, 'unitPrice' => bcadd((string) $d->unit_price, '0', 2)])->all()];
    }

    public function transition(string $id, string $target): array
    {
        return DB::transaction(function () use ($id, $target) {
            $o = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            if ($target === 'PAID' && $o->status === 'PAID') {
                return $this->get($id);
            }
            $next = ['PAYMENT_PENDING' => 'PAID', 'PAID' => 'PROCESSING', 'PROCESSING' => 'SHIPPED', 'SHIPPED' => 'DELIVERED'];
            abort_unless(($next[$o->status] ?? null) === $target || ($target === 'CANCELLED' && $o->status === 'PAYMENT_PENDING'), 409, 'Transición de pedido inválida');
            if (in_array($target, ['PAID', 'CANCELLED'], true)) {
                foreach (DB::table('order_details')->where('order_id', $id)->orderBy('product_id')->get() as $d) {
                    $p = DB::table('products')->where('id', $d->product_id)->lockForUpdate()->first();
                    abort_unless($p && $p->reserved >= $d->quantity && $p->stock >= $d->quantity, 409, 'Reserva de inventario inconsistente');
                    DB::table('products')->where('id', $p->id)->update(['reserved' => $p->reserved - $d->quantity, 'stock' => $target === 'PAID' ? $p->stock - $d->quantity : $p->stock, 'updated_at' => now()]);
                }
            }
            DB::table('orders')->where('id', $id)->update(['status' => $target, 'updated_at' => now()]);

            return $this->get($id);
        }, 3);
    }
}
