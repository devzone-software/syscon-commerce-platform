<?php

use App\Models\User;
use App\Modules\Invoices\InvoiceController;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\PaymentController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * One side of a race for InventoryConcurrencyTest: waits at a file barrier and then runs
 * `action` in its own process (and its own database connection).
 *   order   <customer> <product>
 *   confirm <payment> <admin>
 *   cancel  <order> <admin>
 *   expire
 *   invoice <order>   (boleta; imprime la serie-correlativo asignada)
 * Prints CREATED/OK, CONFLICT (HTTP 409) or, for expire, the number of expired orders.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $barrier, $slot, $action] = $argv;
$args = array_slice($argv, 4);
touch($barrier.'.ready'.$slot);
$deadline = microtime(true) + 10;
while (! file_exists($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Barrier timeout');
        exit(1);
    }
    usleep(10000);
}
try {
    switch ($action) {
        case 'order':
            app(OrderService::class)->create($args[0], [['productId' => $args[1], 'quantity' => 1]]);
            echo 'CREATED';
            break;
        case 'confirm':
            $request = Request::create('/', 'POST', ['reference' => 'RACE-'.$slot]);
            $request->setUserResolver(fn () => User::findOrFail($args[1]));
            app(PaymentController::class)->confirm($request, $args[0]);
            echo 'OK';
            break;
        case 'cancel':
            app(OrderService::class)->transition($args[0], 'CANCELLED', $args[1]);
            echo 'OK';
            break;
        case 'expire':
            echo app(OrderService::class)->expireDue();
            break;
        case 'invoice':
            $request = Request::create('/', 'POST', ['orderId' => $args[0], 'type' => '03']);
            echo app(InvoiceController::class)->store($request)->getData(true)['data']['serialNumber'];
            break;
        default:
            fwrite(STDERR, "Acción desconocida: $action");
            exit(1);
    }
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'CONFLICT';
}
