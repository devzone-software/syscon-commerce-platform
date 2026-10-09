<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_cannot_reserve_the_same_last_unit(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Requiere MySQL/MariaDB o PostgreSQL para probar bloqueos entre procesos');
        }
        $user = User::create(['email' => 'concurrency@example.com', 'password' => 'test-password-123', 'role' => 'CUSTOMER']);
        $p = app(CatalogService::class)->upsert(['sku' => 'LAST', 'name' => 'Última unidad', 'price' => '10.00', 'stock' => 1, 'active' => true]);
        $barrier = tempnam(sys_get_temp_dir(), 'syscon-barrier-');
        $connectionName = config('database.default');
        $connection = config('database.connections.'.$connectionName);
        $env = ['DB_CONNECTION' => $connectionName, 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'] ?? '', 'DB_URL' => ''];
        $processes = [];
        try {
            foreach ([1, 2] as $slot) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/create-order.php'), $user->id, $p['id'], $barrier, (string) $slot], base_path(), $env);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! file_exists($barrier.'.ready1') || ! file_exists($barrier.'.ready2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Los procesos no llegaron a la barrera');
                }
                usleep(10000);
            }
            touch($barrier.'.go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            $this->assertSame(['CONFLICT', 'CREATED'], $results);
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 1, 'reserved' => 1]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (['', '.ready1', '.ready2', '.go'] as $suffix) {
                if (file_exists($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }
}
