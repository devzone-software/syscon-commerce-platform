<?php

use App\Modules\Orders\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$customer,$product,$barrier,$slot] = $argv;
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
    app(OrderService::class)->create($customer, [['productId' => $product, 'quantity' => 1]]);
    echo 'CREATED';
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'CONFLICT';
}
