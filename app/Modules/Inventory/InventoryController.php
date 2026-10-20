<?php

namespace App\Modules\Inventory;

use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Movimientos de inventario del superadmin (inventory:manage). Reglas:
 * - Compra al por mayor: suma unidades al stock físico y guarda el costo unitario como último costo del
 *   producto. El precio de venta no cambia: se sigue vendiendo al precio del catálogo.
 * - Uso interno: resta unidades del stock físico; ya no se pueden vender ni facturar. Solo se retira lo
 *   disponible (stock − reservas de pedidos pendientes), así nunca se quita mercadería ya comprometida.
 * - Los productos se bloquean en orden de id, igual que al crear pedidos, para no cruzarse con ellos.
 */
class InventoryController
{
    private const PAGE = ['page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100'];

    public function purchases(Request $r)
    {
        $data = $r->validate(self::PAGE);
        [$page, $perPage] = [(int) ($data['page'] ?? 1), (int) ($data['perPage'] ?? 20)];
        $total = DB::table('purchases')->count();
        $items = DB::table('purchases')->orderByDesc('created_at')->forPage($page, $perPage)->get()->map(fn ($p) => self::purchaseView($p))->all();

        return Api::ok('Compras', ['items' => $items, 'page' => $page, 'perPage' => $perPage, 'total' => $total]);
    }

    public function storePurchase(Request $r)
    {
        $data = $r->validate([
            'supplierName' => 'required|string|min:2|max:255', 'documentRef' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1|max:100', 'items.*.productId' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unitCost' => 'required|decimal:0,2|gt:0|max:999999999.99',
        ]);

        return DB::transaction(function () use ($r, $data) {
            $items = collect($data['items']);
            $products = DB::table('products')->whereIn('id', $items->pluck('productId'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($products->count() === $items->count(), 404, 'Producto no encontrado');
            $id = (string) Str::uuid();
            $total = '0.00';
            foreach ($items as $item) {
                $total = bcadd($total, bcmul((string) $item['quantity'], (string) $item['unitCost'], 2), 2);
            }
            DB::table('purchases')->insert(['id' => $id, 'supplier_name' => trim($data['supplierName']), 'document_ref' => isset($data['documentRef']) ? trim($data['documentRef']) : null,
                'notes' => isset($data['notes']) ? trim($data['notes']) : null, 'total' => $total, 'created_by' => $r->user()->id, 'created_at' => now()]);
            foreach ($items as $item) {
                $cost = bcadd((string) $item['unitCost'], '0', 2);
                DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $item['productId'], 'quantity' => $item['quantity'], 'unit_cost' => $cost]);
                DB::table('products')->where('id', $item['productId'])->update(['stock' => $products[$item['productId']]->stock + $item['quantity'], 'cost' => $cost, 'updated_at' => now()]);
            }

            return Api::ok('Compra registrada: stock actualizado', self::purchaseView(DB::table('purchases')->where('id', $id)->first()), 201);
        }, 3);
    }

    public function internalUses(Request $r)
    {
        $data = $r->validate(self::PAGE);
        [$page, $perPage] = [(int) ($data['page'] ?? 1), (int) ($data['perPage'] ?? 20)];
        $total = DB::table('internal_uses')->count();
        $items = DB::table('internal_uses')->join('products', 'products.id', '=', 'internal_uses.product_id')->join('users', 'users.id', '=', 'internal_uses.created_by')
            ->orderByDesc('internal_uses.created_at')->forPage($page, $perPage)
            ->get(['internal_uses.*', 'products.sku', 'products.name', 'products.cost', 'users.email'])
            ->map(fn ($u) => self::internalUseView($u))->all();

        return Api::ok('Uso interno', ['items' => $items, 'page' => $page, 'perPage' => $perPage, 'total' => $total]);
    }

    public function storeInternalUse(Request $r)
    {
        $data = $r->validate(['productId' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:100000', 'reason' => 'required|string|min:3|max:255']);

        return DB::transaction(function () use ($r, $data) {
            $p = DB::table('products')->where('id', $data['productId'])->lockForUpdate()->first();
            abort_unless($p, 404, 'Producto no encontrado');
            abort_if($data['quantity'] > $p->stock - $p->reserved, 409, 'Solo hay '.max(0, $p->stock - $p->reserved).' unidades disponibles (las reservadas por pedidos no se pueden retirar)');
            $id = (string) Str::uuid();
            DB::table('internal_uses')->insert(['id' => $id, 'product_id' => $p->id, 'quantity' => $data['quantity'], 'reason' => trim($data['reason']), 'created_by' => $r->user()->id, 'created_at' => now()]);
            DB::table('products')->where('id', $p->id)->update(['stock' => $p->stock - $data['quantity'], 'updated_at' => now()]);
            $row = DB::table('internal_uses')->join('products', 'products.id', '=', 'internal_uses.product_id')->join('users', 'users.id', '=', 'internal_uses.created_by')
                ->where('internal_uses.id', $id)->first(['internal_uses.*', 'products.sku', 'products.name', 'products.cost', 'users.email']);

            return Api::ok('Retiro registrado: ya no está disponible para la venta', self::internalUseView($row), 201);
        }, 3);
    }

    private static function purchaseView(object $p): array
    {
        return ['id' => $p->id, 'supplierName' => $p->supplier_name, 'documentRef' => $p->document_ref, 'notes' => $p->notes, 'total' => bcadd((string) $p->total, '0', 2),
            'createdBy' => DB::table('users')->where('id', $p->created_by)->value('email'), 'createdAt' => OrderService::iso($p->created_at),
            'items' => DB::table('purchase_items')->join('products', 'products.id', '=', 'purchase_items.product_id')->where('purchase_id', $p->id)->orderBy('purchase_items.id')
                ->get(['purchase_items.*', 'products.sku', 'products.name'])
                ->map(fn ($i) => ['productId' => $i->product_id, 'sku' => $i->sku, 'name' => $i->name, 'quantity' => $i->quantity, 'unitCost' => bcadd((string) $i->unit_cost, '0', 2),
                    'subtotal' => bcmul((string) $i->quantity, (string) $i->unit_cost, 2)])->all()];
    }

    private static function internalUseView(object $u): array
    {
        return ['id' => $u->id, 'productId' => $u->product_id, 'sku' => $u->sku, 'name' => $u->name, 'quantity' => $u->quantity, 'reason' => $u->reason,
            // Valor a costo de lo retirado, si el producto tiene una compra registrada.
            'costValue' => $u->cost === null ? null : bcmul((string) $u->quantity, (string) $u->cost, 2),
            'createdBy' => $u->email, 'createdAt' => OrderService::iso($u->created_at)];
    }
}
