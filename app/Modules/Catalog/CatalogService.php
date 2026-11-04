<?php

namespace App\Modules\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CatalogService
{
    public function upsert(array $data): array
    {
        $data = Validator::make($data, [
            'sku' => 'required|string|max:255', 'name' => 'required|string|max:255', 'price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2',
            'stock' => 'required|integer|min:0|max:2147483647', 'imageUrl' => 'nullable|url|max:2048', 'category' => 'nullable|string|max:255', 'brand' => 'nullable|string|max:255', 'active' => 'required|boolean',
            'description' => 'nullable|string|max:5000', 'specifications' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($data) {
            $p = DB::table('products')->where('sku', $data['sku'])->lockForUpdate()->first();
            $id = $p?->id ?? (string) Str::uuid();
            abort_if($p && $data['stock'] < $p->reserved, 409, 'Stock inferior a las reservas pendientes');
            $values = ['name' => $data['name'], 'price' => bcadd((string) $data['price'], '0', 2), 'stock' => $data['stock'], 'image_url' => $data['imageUrl'] ?? null, 'active' => $data['active'], 'updated_at' => now()];
            foreach (['description', 'specifications'] as $field) {
                if (array_key_exists($field, $data)) {
                    $values[$field] = $data[$field];
                }
            }
            foreach (['category' => 'categories', 'brand' => 'brands'] as $field => $table) {
                if (! empty($data[$field])) {
                    $name = trim($data[$field]);
                    $row = DB::table($table)->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
                    $ref = $row?->id ?? (string) Str::uuid();
                    if (! $row) {
                        DB::table($table)->insert(['id' => $ref, 'name' => $name]);
                    }
                    $values[$field.'_id'] = $ref;
                }
            }
            if ($p) {
                DB::table('products')->where('id', $id)->update($values);
            } else {
                DB::table('products')->insert(array_merge($values, ['id' => $id, 'sku' => $data['sku'], 'reserved' => 0, 'created_at' => now()]));
            }

            return $this->get($id);
        }, 3);
    }

    private function query(): Builder
    {
        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->select('products.*', 'categories.name as category_name', 'brands.name as brand_name');
    }

    private function view(object $p, bool $administrative = false): array
    {
        $product = ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'price' => bcadd((string) $p->price, '0', 2),
            'stock' => $p->stock - $p->reserved, 'imageUrl' => $p->image_url, 'category' => $p->category_name, 'brand' => $p->brand_name,
            'active' => (bool) $p->active, 'description' => $p->description, 'specifications' => $p->specifications];

        return $administrative ? $product + ['physicalStock' => (int) $p->stock, 'reservedStock' => (int) $p->reserved] : $product;
    }

    public function get(string $id): array
    {
        $p = $this->query()->where('products.id', $id)->first();
        abort_unless($p, 404, 'Producto no encontrado');

        return $this->view($p);
    }

    public function list(bool $administrative = false): array
    {
        return $this->query()->when(! $administrative, fn ($q) => $q->where('products.active', true))
            ->orderBy('products.sku')->get()->map(fn ($p) => $this->view($p, $administrative))->all();
    }
}
