<?php

namespace App\Modules\Pricing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Precio de venta sugerido = precio de referencia más bajo (soles, IGV incluido) más el margen
 * de la categoría, redondeado hacia arriba a .90. Nada cambia sin que el personal lo apruebe.
 */
class PricingService
{
    public function __construct(private PriceReader $reader) {}

    /** 123.10 → 123.90 · 123.95 → 124.90 · 123.90 → 123.90 */
    public static function commercialRound(float $value): float
    {
        return round(ceil(round($value - 0.9, 2)) + 0.9, 2);
    }

    public static function suggestedPrice(float $cost, float $margin): float
    {
        return self::commercialRound($cost * (1 + $margin / 100));
    }

    /** Una fila por producto con al menos un precio de referencia. */
    public function suggestions(): array
    {
        $refs = DB::table('price_references as r')
            ->join('price_sources as s', 's.id', '=', 'r.source_id')
            ->where('s.active', true)->whereNotNull('r.price')
            ->get(['r.product_id', 'r.price', 'r.checked_at', 'r.status', 's.name as source'])
            ->groupBy('product_id');
        if ($refs->isEmpty()) {
            return [];
        }
        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('category_margins as m', 'm.category_id', '=', 'p.category_id')
            ->whereIn('p.id', $refs->keys())
            ->orderBy('p.name')
            ->get(['p.id', 'p.sku', 'p.name', 'p.price', 'p.active', 'p.price_dismissed_cost', 'c.name as category', 'm.margin']);

        return $products->map(function ($p) use ($refs) {
            $options = $refs[$p->id]->sortBy(fn ($r) => (float) $r->price)->values();
            $best = $options->first();
            $cost = (float) $best->price;
            $current = (float) $p->price;
            $margin = $p->margin === null ? null : (float) $p->margin;
            $suggested = $margin === null ? null : self::suggestedPrice($cost, $margin);
            $status = match (true) {
                $suggested === null => 'NO_MARGIN',
                abs($suggested - $current) < 0.005 => 'OK',
                // Approved with an adjusted price, or ignored, for this same cost.
                $p->price_dismissed_cost !== null && abs((float) $p->price_dismissed_cost - $cost) < 0.005 => 'REVIEWED',
                $suggested > $current => 'UP',
                default => 'DOWN',
            };

            return [
                'productId' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'category' => $p->category,
                'active' => (bool) $p->active,
                'currentPrice' => number_format($current, 2, '.', ''),
                'referencePrice' => number_format($cost, 2, '.', ''),
                'referenceSource' => $best->source,
                'checkedAt' => $best->checked_at,
                'references' => $options->map(fn ($r) => ['source' => $r->source, 'price' => number_format((float) $r->price, 2, '.', ''), 'status' => $r->status])->all(),
                'categoryMargin' => $margin === null ? null : number_format($margin, 2, '.', ''),
                // Margen que deja hoy el precio de venta sobre el costo de referencia.
                'currentMargin' => number_format(($current - $cost) / $cost * 100, 1, '.', ''),
                'suggestedPrice' => $suggested === null ? null : number_format($suggested, 2, '.', ''),
                'status' => $status,
            ];
        })->values()->all();
    }

    /**
     * Aplica el precio aprobado (el sugerido u otro que el personal ajustó). Se recuerda el costo
     * revisado para no volver a proponer el cambio hasta que ese costo cambie.
     */
    public function approve(string $productId, array $input): array
    {
        $data = Validator::make($input, ['price' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2'])->validate();
        $updated = DB::table('products')->where('id', $productId)
            ->update(['price' => $data['price'], 'price_dismissed_cost' => $this->bestCost($productId), 'updated_at' => now()]);
        abort_unless($updated, 404, 'Producto no encontrado');

        return ['productId' => $productId, 'price' => number_format((float) $data['price'], 2, '.', '')];
    }

    /** Oculta la sugerencia hasta que cambie el costo de referencia. */
    public function dismiss(string $productId): void
    {
        $cost = $this->bestCost($productId);
        abort_if($cost === null, 404, 'El producto no tiene precio de referencia');
        DB::table('products')->where('id', $productId)->update(['price_dismissed_cost' => $cost]);
    }

    private function bestCost(string $productId): ?string
    {
        return DB::table('price_references as r')->join('price_sources as s', 's.id', '=', 'r.source_id')
            ->where('r.product_id', $productId)->where('s.active', true)->whereNotNull('r.price')->min('r.price');
    }

    // ---- Empresas de referencia ----

    public function sources(): array
    {
        return DB::table('price_sources as s')
            ->leftJoin('price_references as r', 'r.source_id', '=', 's.id')
            ->groupBy('s.id', 's.name', 's.host', 's.active')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.host', 's.active', DB::raw('count(r.id) as links')])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'host' => $s->host, 'active' => (bool) $s->active, 'links' => (int) $s->links])
            ->all();
    }

    public function saveSource(array $input, ?string $id = null): array
    {
        $data = Validator::make($input, ['name' => 'required|string|max:120', 'website' => 'required|string|max:255', 'active' => 'sometimes|boolean'])->validate();
        $host = PriceReader::normalizeHost($data['website']);
        abort_if(DB::table('price_sources')->where('host', $host)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'Esa web ya está registrada.');
        $row = ['name' => trim($data['name']), 'host' => $host, 'active' => $data['active'] ?? true, 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('price_sources')->where('id', $id)->update($row), 404, 'Empresa no encontrada');
        } else {
            $id = (string) Str::uuid();
            DB::table('price_sources')->insert($row + ['id' => $id, 'created_at' => now()]);
        }

        return collect($this->sources())->firstWhere('id', $id);
    }

    // ---- Enlaces producto ↔ empresa ----

    public function references(): array
    {
        return DB::table('price_references as r')
            ->join('price_sources as s', 's.id', '=', 'r.source_id')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->orderBy('p.name')->orderBy('s.name')
            ->get(['r.id', 'r.product_id', 'p.name as product', 'p.sku', 'r.source_id', 's.name as source', 's.active as source_active', 'r.url', 'r.price', 'r.status', 'r.error', 'r.checked_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'productId' => $r->product_id, 'product' => $r->product, 'sku' => $r->sku,
                'sourceId' => $r->source_id, 'source' => $r->source, 'sourceActive' => (bool) $r->source_active,
                'url' => $r->url, 'price' => $r->price === null ? null : number_format((float) $r->price, 2, '.', ''),
                'status' => $r->status, 'error' => $r->error, 'checkedAt' => $r->checked_at,
            ])->all();
    }

    /** Crea o reemplaza el enlace de un producto en una empresa; el precio a mano es opcional. */
    public function saveReference(array $input): array
    {
        $data = Validator::make($input, [
            'productId' => 'required|uuid|exists:products,id',
            'sourceId' => 'required|uuid|exists:price_sources,id',
            'url' => 'required|string|max:2048',
            'price' => 'nullable|numeric|min:0.01|max:999999999999.99|decimal:0,2',
        ])->validate();
        $host = DB::table('price_sources')->where('id', $data['sourceId'])->value('host');
        $url = trim($data['url']);
        PriceReader::validateUrl($url, $host);
        $existing = DB::table('price_references')->where('product_id', $data['productId'])->where('source_id', $data['sourceId'])->first();
        $id = $existing->id ?? (string) Str::uuid();
        $row = ['url' => $url, 'updated_at' => now()];
        if ($existing) {
            DB::table('price_references')->where('id', $id)->update($row);
        } else {
            DB::table('price_references')->insert($row + ['id' => $id, 'product_id' => $data['productId'], 'source_id' => $data['sourceId'], 'created_at' => now()]);
        }
        if (isset($data['price'])) {
            $this->record($id, (float) $data['price'], 'MANUAL');
        }

        return collect($this->references())->firstWhere('id', $id);
    }

    public function manualPrice(string $id, array $input): array
    {
        $data = Validator::make($input, ['price' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2'])->validate();
        abort_unless(DB::table('price_references')->where('id', $id)->exists(), 404, 'Enlace no encontrado');
        $this->record($id, (float) $data['price'], 'MANUAL');

        return collect($this->references())->firstWhere('id', $id);
    }

    public function deleteReference(string $id): void
    {
        abort_unless(DB::table('price_references')->where('id', $id)->delete(), 404, 'Enlace no encontrado');
    }

    /** Lee el precio de la web; si falla, conserva el último precio y anota el motivo. */
    public function refresh(string $id): array
    {
        $ref = DB::table('price_references')->where('id', $id)->first();
        abort_unless($ref, 404, 'Enlace no encontrado');
        try {
            $this->record($id, $this->reader->read($ref->url), 'OK');
        } catch (RuntimeException $e) {
            DB::table('price_references')->where('id', $id)->update(['status' => 'ERROR', 'error' => mb_substr($e->getMessage(), 0, 255), 'checked_at' => now(), 'updated_at' => now()]);
        }

        return collect($this->references())->firstWhere('id', $id);
    }

    /** Para la tarea programada: todos los enlaces de empresas activas. */
    public function refreshAll(): array
    {
        $counts = ['OK' => 0, 'ERROR' => 0];
        $ids = DB::table('price_references as r')->join('price_sources as s', 's.id', '=', 'r.source_id')
            ->where('s.active', true)->orderBy('r.checked_at')->pluck('r.id');
        foreach ($ids as $id) {
            $counts[$this->refresh($id)['status'] === 'ERROR' ? 'ERROR' : 'OK']++;
        }

        return $counts;
    }

    private function record(string $id, float $price, string $origin): void
    {
        $previous = DB::table('price_references')->where('id', $id)->value('price');
        DB::table('price_references')->where('id', $id)->update(['price' => $price, 'status' => $origin, 'error' => null, 'checked_at' => now(), 'updated_at' => now()]);
        if ($previous === null || abs((float) $previous - $price) >= 0.005) {
            DB::table('price_reference_history')->insert(['reference_id' => $id, 'price' => $price, 'origin' => $origin, 'captured_at' => now()]);
        }
    }

    // ---- Márgenes por categoría ----

    public function margins(): array
    {
        return DB::table('categories as c')->leftJoin('category_margins as m', 'm.category_id', '=', 'c.id')
            ->orderBy('c.name')->get(['c.id', 'c.name', 'm.margin'])
            ->map(fn ($c) => ['categoryId' => $c->id, 'category' => $c->name, 'margin' => $c->margin === null ? null : number_format((float) $c->margin, 2, '.', '')])
            ->all();
    }

    public function saveMargin(string $categoryId, array $input): array
    {
        $data = Validator::make($input, ['margin' => 'nullable|numeric|min:0|max:500|decimal:0,2'])->validate();
        abort_unless(DB::table('categories')->where('id', $categoryId)->exists(), 404, 'Categoría no encontrada');
        if (($data['margin'] ?? null) === null) {
            DB::table('category_margins')->where('category_id', $categoryId)->delete();
        } else {
            DB::table('category_margins')->updateOrInsert(['category_id' => $categoryId], ['margin' => $data['margin'], 'updated_at' => now(), 'created_at' => now()]);
        }

        return collect($this->margins())->firstWhere('categoryId', $categoryId);
    }
}
