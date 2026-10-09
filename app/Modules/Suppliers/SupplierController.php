<?php

namespace App\Modules\Suppliers;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierController
{
    public function __construct(private SupplierService $suppliers) {}

    public function index()
    {
        return Api::ok('Proveedores', DB::table('suppliers')->get()->map(fn ($s) => $this->view($s)));
    }

    public function store(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'feedUrl' => 'required|url|max:2048', 'minIntervalMinutes' => 'required|integer|min:15|max:525600']);
        $this->suppliers->validateUrl($data['feedUrl']);
        $id = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $id, 'name' => $data['name'], 'feed_url' => $data['feedUrl'], 'min_interval_minutes' => $data['minIntervalMinutes'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return Api::ok('Proveedor creado', $this->view(DB::table('suppliers')->where('id', $id)->first()));
    }

    public function run(string $id)
    {
        return Api::ok('Sincronización terminada', $this->suppliers->run($id));
    }

    public function jobs()
    {
        return Api::ok('Ejecuciones', DB::table('supplier_jobs')->orderByDesc('started_at')->pluck('id')->map(fn ($id) => $this->suppliers->job($id)));
    }

    public function history(string $id)
    {
        $this->suppliers->job($id);

        return Api::ok('Historial', DB::table('supplier_history')->where('job_id', $id)->orderBy('occurred_at')->get()->map(fn ($h) => ['id' => $h->id, 'jobId' => $h->job_id, 'level' => $h->level, 'message' => $h->message, 'occurredAt' => $h->occurred_at]));
    }

    private function view(object $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'feedUrl' => $s->feed_url, 'minIntervalMinutes' => $s->min_interval_minutes, 'active' => (bool) $s->active];
    }
}
