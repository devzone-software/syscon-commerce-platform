<?php

use App\Modules\Suppliers\SupplierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\HttpKernel\Exception\HttpException;

Artisan::command('suppliers:sync', function (SupplierService $service) {
    foreach (DB::table('suppliers')->where('active', true)->pluck('id') as $id) {
        try {
            $job = $service->run($id);
            $this->info($id.': '.$job['status']);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 429) {
                $this->error($e->getMessage());
            }
        }
    }
})->purpose('Sincroniza feeds CSV de proveedores activos');
// Run in the current PHP process: shared hosting may disable proc_open.
Schedule::call(fn () => Artisan::call('suppliers:sync'))
    ->name('suppliers-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
