<?php

use App\Modules\Invoices\InvoiceSubmission;
use App\Modules\Invoices\InvoiceVoiding;
use App\Modules\Notifications\Notifier;
use App\Modules\Orders\OrderService;
use App\Modules\Suppliers\SupplierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
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
Artisan::command('orders:expire', function (OrderService $orders) {
    $this->info('Pedidos vencidos: '.$orders->expireDue());
})->purpose('Vence los pedidos impagos cuya reserva terminó y libera su stock');
Artisan::command('sunat:test-certificate {path=certificates/sunat-prueba.p12}', function (string $path) {
    // Solo para el servicio beta: acepta certificados autofirmados. Producción usa el certificado digital real.
    $file = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) ? $path : base_path($path);
    @mkdir(dirname($file), 0700, true);
    $password = Str::random(24);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['countryName' => 'PE', 'organizationName' => 'SYSCON PRUEBA', 'commonName' => '20000000001 SYSCON PRUEBA'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 730, ['digest_alg' => 'sha256']);
    if (! $cert || ! openssl_pkcs12_export_to_file($cert, $file, $key, $password)) {
        $this->error('No se pudo generar el certificado: '.openssl_error_string());

        return 1;
    }
    $this->info('Certificado de prueba creado. Agrega al .env:');
    $this->line("SUNAT_RUC=20000000001\nSUNAT_SOL_USER=MODDATOS\nSUNAT_SOL_PASSWORD=moddatos\nSUNAT_CERTIFICATE_PATH=\"".str_replace('\\', '/', $file)."\"\nSUNAT_CERTIFICATE_PASSWORD=$password");
})->purpose('Genera un certificado autofirmado para enviar comprobantes al servicio beta de SUNAT');
Artisan::command('invoices:reconcile', function (InvoiceSubmission $submission) {
    $this->info('Comprobantes conciliados: '.$submission->reconcilePending());
    $this->info('Anulaciones consultadas: '.app(InvoiceVoiding::class)->checkPending());
})->purpose('Concilia con SUNAT los comprobantes que quedaron sin respuesta');
Schedule::call(fn () => Artisan::call('invoices:reconcile'))
    ->name('invoices-reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping();
Artisan::command('notifications:send', function (Notifier $notifier) {
    $this->info('Avisos enviados: '.$notifier->sendDue());
})->purpose('Envía los correos pendientes y reintenta los fallidos cuyo plazo venció');
Schedule::call(fn () => Artisan::call('notifications:send'))
    ->name('notifications-send')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::call(fn () => Artisan::call('orders:expire'))
    ->name('orders-expire')
    ->everyFiveMinutes()
    ->withoutOverlapping();
// Run in the current PHP process: shared hosting may disable proc_open.
Schedule::call(fn () => Artisan::call('suppliers:sync'))
    ->name('suppliers-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
