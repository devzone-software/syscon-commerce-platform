<?php

namespace App\Modules\Orders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function create(string $customer, array $items): array
    {
        return DB::transaction(function () use ($customer, $items) {
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
            DB::table('orders')->insert(['id' => $id, 'customer_id' => $customer, 'status' => 'PAYMENT_PENDING', 'total' => $total, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_details')->insert($details);

            return $this->get($id);
        }, 3);
    }

    public function get(string $id): array
    {
        $o = DB::table('orders')->where('id', $id)->first();
        abort_unless($o, 404, 'Pedido no encontrado');

        return ['id' => $o->id, 'customerId' => $o->customer_id, 'status' => $o->status, 'total' => bcadd((string) $o->total, '0', 2), 'details' => DB::table('order_details')->where('order_id', $id)->orderBy('product_id')->get()->map(fn ($d) => ['productId' => $d->product_id, 'sku' => $d->sku, 'name' => $d->name, 'quantity' => $d->quantity, 'unitPrice' => bcadd((string) $d->unit_price, '0', 2)])->all()];
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
