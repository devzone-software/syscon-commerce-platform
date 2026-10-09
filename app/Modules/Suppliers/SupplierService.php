<?php

namespace App\Modules\Suppliers;

use App\Modules\Catalog\CatalogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SupplierService
{
    public function __construct(private CatalogService $catalog) {}

    public function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && in_array(strtolower($parts['host'] ?? ''), config('commerce.supplier_hosts'), true) && (! isset($parts['port']) || $parts['port'] === 443), 422, 'Host HTTPS no permitido para proveedor');
    }

    public function run(string $id): array
    {
        $job = DB::transaction(function () use ($id) {
            $s = DB::table('suppliers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($s, 404, 'Proveedor no encontrado');
            abort_unless($s->active, 409, 'Proveedor inactivo');
            $last = DB::table('supplier_jobs')->where('supplier_id', $id)->orderByDesc('started_at')->first();
            abort_if($last && now()->lt(Carbon::parse($last->started_at)->addMinutes($s->min_interval_minutes)), 429, 'Frecuencia excedida');
            // A crashed worker can be recovered after five minutes; downloads time out after 20 seconds.
            DB::table('supplier_jobs')->where('supplier_id', $id)->where('status', 'RUNNING')->where('started_at', '<', now()->subMinutes(5))->update(['status' => 'FAILED', 'finished_at' => now()]);
            abort_if(DB::table('supplier_jobs')->where('supplier_id', $id)->where('status', 'RUNNING')->exists(), 409, 'Sincronización en curso');
            $job = (string) Str::uuid();
            DB::table('supplier_jobs')->insert(['id' => $job, 'supplier_id' => $id, 'status' => 'RUNNING', 'imported' => 0, 'started_at' => now()]);

            return ['id' => $job, 'supplier' => $s];
        }, 3);
        $temp = tmpfile();
        try {
            $this->validateUrl($job['supplier']->feed_url);
            $path = stream_get_meta_data($temp)['uri'];
            $response = Http::accept('text/csv')->connectTimeout(10)->timeout(20)->retry(3, 1000)->withOptions([
                'allow_redirects' => false, 'sink' => $path,
                'progress' => function ($total, $received) {
                    if ($total > 5000000 || $received > 5000000) {
                        throw new \RuntimeException('Feed demasiado grande');
                    }
                },
            ])->get($job['supplier']->feed_url);
            if ($response->status() !== 200) {
                throw new \RuntimeException('Feed no disponible');
            }
            // Fake responses in tests do not write a sink.
            if (filesize($path) === 0) {
                $body = $response->body();
                if (strlen($body) > 5000000) {
                    throw new \RuntimeException('Feed demasiado grande');
                } fwrite($temp, $body);
            }
            rewind($temp);
            $header = fgetcsv($temp, 0, ';', '"', '');
            if ($header !== ['sku', 'name', 'price', 'stock', 'imageUrl', 'brand', 'category']) {
                throw new \RuntimeException('Cabecera CSV inválida');
            }
            $rows = [];
            while (($row = fgetcsv($temp, 0, ';', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 7) {
                    throw new \RuntimeException('Fila CSV inválida');
                }
                [$sku,$name,$price,$stock,$image,$brand,$category] = array_map('trim', $row);
                $data = ['sku' => $sku, 'name' => $name, 'price' => $price, 'stock' => $stock, 'imageUrl' => $image ?: null, 'brand' => $brand ?: null, 'category' => $category ?: null, 'active' => true];
                Validator::make($data, ['sku' => 'required|string|max:255', 'name' => 'required|string|max:255', 'price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'stock' => 'required|integer|min:0|max:2147483647'])->validate();
                if (isset($rows[$sku])) {
                    throw new \RuntimeException('SKU duplicado en feed');
                }
                $rows[$sku] = $data;
            }
            DB::transaction(function () use ($rows, $id, $job) {
                DB::table('suppliers')->where('id', $id)->lockForUpdate()->first();
                abort_unless(DB::table('supplier_jobs')->where('id', $job['id'])->value('status') === 'RUNNING', 409, 'Ejecución reemplazada');
                ksort($rows);
                foreach ($rows as $data) {
                    $this->catalog->upsert($data);
                    $existing = DB::table('supplier_products')->where('supplier_id', $id)->where('sku', $data['sku'])->first();
                    DB::table('supplier_products')->updateOrInsert(['supplier_id' => $id, 'sku' => $data['sku']], ['id' => $existing?->id ?? (string) Str::uuid(), 'name' => $data['name'], 'price' => $data['price'], 'stock' => $data['stock'], 'image_url' => $data['imageUrl'], 'brand' => $data['brand'], 'category' => $data['category']]);
                }
                DB::table('supplier_jobs')->where('id', $job['id'])->update(['status' => 'SUCCEEDED', 'imported' => count($rows), 'finished_at' => now()]);
                $this->history($job['id'], 'INFO', 'Importados: '.count($rows));
            }, 3);
        } catch (\Throwable $e) {
            DB::table('supplier_jobs')->where('id', $job['id'])->update(['status' => 'FAILED', 'finished_at' => now()]);
            $this->history($job['id'], 'ERROR', mb_substr($e->getMessage(), 0, 1000));
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }

        return $this->job($job['id']);
    }

    public function job(string $id): array
    {
        $j = DB::table('supplier_jobs')->where('id', $id)->first();
        abort_unless($j, 404, 'Ejecución no encontrada');

        return ['id' => $j->id, 'supplierId' => $j->supplier_id, 'status' => $j->status, 'imported' => $j->imported, 'startedAt' => $j->started_at, 'finishedAt' => $j->finished_at];
    }

    private function history(string $job, string $level, string $message): void
    {
        DB::table('supplier_history')->insert(['id' => (string) Str::uuid(), 'job_id' => $job, 'level' => $level, 'message' => $message, 'occurred_at' => now()]);
    }
}
