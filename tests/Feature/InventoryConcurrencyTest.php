<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use App\Modules\Orders\OrderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real races between processes (each with its own connection) against MySQL/MariaDB or
 * PostgreSQL; SQLite serializes writers, so these tests are skipped there.
 */
class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Requiere MySQL/MariaDB o PostgreSQL para probar bloqueos entre procesos');
        }
    }

    public function test_two_processes_cannot_reserve_the_same_last_unit(): void
    {
        $user = $this->user('CUSTOMER');
        $p = $this->product(1);
        $results = $this->race([['order', $user, $p], ['order', $user, $p]]);
        sort($results);
        $this->assertSame(['CONFLICT', 'CREATED'], $results);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', ['id' => $p, 'stock' => 1, 'reserved' => 1]);
    }

    public function test_confirming_twice_at_once_consumes_stock_once(): void
    {
        [$order, $payment, $product] = $this->pendingOrder();
        $admin = $this->user('ADMIN');
        $this->assertSame(['OK', 'OK'], $this->race([['confirm', $payment, $admin], ['confirm', $payment, $admin]]));
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 4, 'reserved' => 0]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'PAID']);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order)->where('type', 'PAYMENT_CONFIRMED')->count());
    }

    public function test_payment_confirmation_and_expiration_race_has_one_winner(): void
    {
        $admin = $this->user('ADMIN');
        foreach (range(1, 3) as $round) {
            [$order, $payment, $product] = $this->pendingOrder(expired: true);
            $results = $this->race([['confirm', $payment, $admin], ['expire']]);
            $this->assertOneWinner($order, $payment, $product, $results, 'EXPIRED', ['OK', '0'], ['CONFLICT', '1']);
        }
    }

    public function test_payment_confirmation_and_cancellation_race_has_one_winner(): void
    {
        $admin = $this->user('ADMIN');
        foreach (range(1, 3) as $round) {
            [$order, $payment, $product] = $this->pendingOrder();
            $results = $this->race([['confirm', $payment, $admin], ['cancel', $order, $admin]]);
            $this->assertOneWinner($order, $payment, $product, $results, 'CANCELLED', ['OK', 'CONFLICT'], ['CONFLICT', 'OK']);
        }
    }

    public function test_cancellation_and_expiration_race_releases_stock_once(): void
    {
        $admin = $this->user('ADMIN');
        foreach (range(1, 3) as $round) {
            [$order, , $product] = $this->pendingOrder(expired: true);
            $results = $this->race([['cancel', $order, $admin], ['expire']]);
            $this->assertContains($results, [['OK', '0'], ['CONFLICT', '1']]);
            $this->assertDatabaseHas('orders', ['id' => $order, 'status' => $results[0] === 'OK' ? 'CANCELLED' : 'EXPIRED']);
            $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5, 'reserved' => 0]);
            $this->assertSame(1, DB::table('order_events')->where('order_id', $order)->whereIn('type', ['STATUS_CHANGED', 'EXPIRED'])->count());
        }
    }

    /** Either the payment won (order paid, stock consumed) or the release won (stock back, payment void). */
    private function assertOneWinner(string $order, string $payment, string $product, array $results, string $released, array $paidWins, array $releaseWins): void
    {
        $this->assertContains($results, [$paidWins, $releaseWins]);
        if ($results === $paidWins) {
            $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'PAID']);
            $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => 'APPROVED']);
            $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 4, 'reserved' => 0]);
        } else {
            $this->assertDatabaseHas('orders', ['id' => $order, 'status' => $released]);
            $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => 'CANCELLED']);
            $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5, 'reserved' => 0]);
        }
    }

    private function user(string $role): string
    {
        return User::create(['email' => Str::uuid().'@example.com', 'password' => 'test-password-123', 'role' => $role])->id;
    }

    private function product(int $stock): string
    {
        return app(CatalogService::class)->upsert(['sku' => 'SKU-'.Str::random(8), 'name' => 'Producto', 'price' => '10.00', 'stock' => $stock, 'active' => true])['id'];
    }

    /** Unpaid order for one unit of a product with stock 5, with its pending manual payment. */
    private function pendingOrder(bool $expired = false): array
    {
        $product = $this->product(5);
        $order = app(OrderService::class)->create($this->user('CUSTOMER'), [['productId' => $product, 'quantity' => 1]]);
        if ($expired) {
            DB::table('orders')->where('id', $order['id'])->update(['expires_at' => now()->subMinute()]);
        }
        $payment = (string) Str::uuid();
        DB::table('payments')->insert(['id' => $payment, 'order_id' => $order['id'], 'customer_id' => $order['customerId'], 'provider' => 'MANUAL', 'status' => 'PENDING', 'amount' => $order['total'], 'created_at' => now(), 'updated_at' => now()]);

        return [$order['id'], $payment, $product];
    }

    /**
     * Runs each command of tests/Support/race.php in its own process, releases them together
     * and returns their outputs in the same order.
     *
     * @param  list<list<string>>  $commands
     * @return list<string>
     */
    private function race(array $commands): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'syscon-barrier-');
        $name = config('database.default');
        $connection = config('database.connections.'.$name);
        $env = ['DB_CONNECTION' => $name, 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_SOCKET' => $connection['unix_socket'] ?? '', 'DB_URL' => ''];
        $processes = [];
        try {
            foreach ($commands as $index => $command) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/race.php'), $barrier, (string) ($index + 1), ...$command], base_path(), $env);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            foreach (array_keys($commands) as $index) {
                while (! file_exists($barrier.'.ready'.($index + 1))) {
                    if (microtime(true) > $deadline) {
                        $this->fail('Los procesos no llegaron a la barrera: '.implode(' ', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
                    }
                    usleep(10000);
                }
            }
            touch($barrier.'.go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim($process->getOutput());
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge([''], array_map(fn ($i) => '.ready'.($i + 1), array_keys($commands)), ['.go']) as $suffix) {
                if (file_exists($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }
}
