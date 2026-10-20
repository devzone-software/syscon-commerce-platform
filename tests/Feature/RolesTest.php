<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PROFILE = ['email' => 'Rosa@Syscon.pe', 'firstName' => 'Rosa', 'lastName' => 'Mamani', 'documentType' => 'DNI', 'documentNumber' => '45678912', 'phone' => '987654321'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.service_token' => str_repeat('s', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(string $email, string $password): array
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk()->json('data');
    }

    private function token(string $role): array
    {
        $user = User::create(['email' => Str::uuid().'@example.com', 'password' => 'staff-password-1234', 'role' => $role]);

        return ['Authorization' => 'Bearer '.$this->login($user->email, 'staff-password-1234')['accessToken']];
    }

    private function product(int $stock = 5): array
    {
        return app(CatalogService::class)->upsert(['sku' => 'SKU-'.Str::random(6), 'name' => 'Switch 8 puertos', 'price' => '120.00', 'stock' => $stock, 'active' => true]);
    }

    private function claims(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_admin_operates_the_store_but_sunat_suppliers_reports_and_superadmin_tools_are_reserved(): void
    {
        $admin = $this->token('ADMIN');
        $this->postJson('/api/catalog/products', ['sku' => 'A-1', 'name' => 'Cable', 'price' => '5.00', 'stock' => 3, 'active' => true], $admin)->assertOk();
        $this->getJson('/api/admin/orders', $admin)->assertOk();
        $this->getJson('/api/admin/payments', $admin)->assertOk();
        // Como vendedor, el admin emite los comprobantes de lo que vende.
        $this->getJson('/api/invoices/series', $admin)->assertOk();
        $this->getJson('/api/admin/cash/current', $admin)->assertOk();
        // La venta directa es del superadmin.
        $this->postJson('/api/admin/sales', [], $admin)->assertForbidden();
        foreach (['/api/admin/customers?q=ana', '/api/suppliers', '/api/admin/reports/summary', '/api/admin/users', '/api/admin/audit', '/api/admin/purchases', '/api/admin/internal-uses'] as $path) {
            $this->getJson($path, $admin)->assertForbidden();
        }
        $super = $this->token('SUPERADMIN');
        foreach (['/api/invoices/series', '/api/suppliers', '/api/admin/reports/summary', '/api/admin/users', '/api/admin/audit', '/api/admin/purchases', '/api/admin/internal-uses', '/api/admin/orders'] as $path) {
            $this->getJson($path, $super)->assertOk();
        }
        $this->getJson('/api/admin/orders', $this->token('CUSTOMER'))->assertForbidden();
    }

    public function test_superadmin_creates_admin_that_needs_validation_and_a_new_password(): void
    {
        $super = $this->token('SUPERADMIN');
        $created = $this->postJson('/api/admin/users', self::ADMIN_PROFILE, $super)->assertCreated()->json('data');
        $this->assertSame(['rosa@syscon.pe', 'ADMIN', 'PENDING', true], [$created['user']['email'], $created['user']['role'], $created['user']['status'], $created['user']['mustChangePassword']]);
        $temporary = $created['temporaryPassword'];
        $this->assertSame(16, strlen($temporary));
        $this->postJson('/api/admin/users', self::ADMIN_PROFILE, $super)->assertConflict();

        // Pendiente: no entra hasta que el superadmin la valida.
        $this->postJson('/api/auth/login', ['email' => 'rosa@syscon.pe', 'password' => $temporary])->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta aún no ha sido validada por el superadministrador');
        $this->postJson('/api/admin/users/'.$created['user']['id'].'/validate', [], $super)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->postJson('/api/admin/users/'.$created['user']['id'].'/validate', [], $super)->assertConflict();

        // Primer ingreso: sin permisos hasta cambiar la contraseña temporal.
        $first = $this->login('rosa@syscon.pe', $temporary);
        $claims = $this->claims($first['accessToken']);
        $this->assertTrue($claims['pwd_change']);
        $this->assertSame(['ROLE_ADMIN'], $claims['authorities']);
        $h = ['Authorization' => 'Bearer '.$first['accessToken']];
        $this->getJson('/api/admin/orders', $h)->assertForbidden()->assertJsonPath('message', 'Debes cambiar tu contraseña temporal antes de continuar');
        $this->postJson('/api/auth/password', ['currentPassword' => 'wrong-password-123', 'newPassword' => 'Rosa-nueva-clave-2026'], $h)->assertUnprocessable();
        $this->postJson('/api/auth/password', ['currentPassword' => $temporary, 'newPassword' => $temporary], $h)->assertUnprocessable();
        $changed = $this->postJson('/api/auth/password', ['currentPassword' => $temporary, 'newPassword' => 'Rosa-nueva-clave-2026'], $h)->assertOk()->json('data');
        $this->assertFalse($this->claims($changed['accessToken'])['pwd_change']);
        $this->postJson('/api/auth/refresh', ['refreshToken' => $first['refreshToken']])->assertUnauthorized();
        $this->getJson('/api/admin/orders', ['Authorization' => 'Bearer '.$changed['accessToken']])->assertOk();
        $this->assertNotNull(DB::table('users')->where('email', 'rosa@syscon.pe')->value('last_login_at'));
    }

    public function test_suspension_cuts_access_immediately_and_reset_password_revokes_sessions(): void
    {
        $super = $this->token('SUPERADMIN');
        $admin = User::create(['email' => 'luis@syscon.pe', 'password' => 'staff-password-1234', 'role' => 'ADMIN', 'status' => 'ACTIVE', 'validated_at' => now()]);
        $session = $this->login('luis@syscon.pe', 'staff-password-1234');
        $h = ['Authorization' => 'Bearer '.$session['accessToken']];
        $this->getJson('/api/admin/orders', $h)->assertOk();

        $this->postJson("/api/admin/users/{$admin->id}/suspend", [], $super)->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->getJson('/api/admin/orders', $h)->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refreshToken' => $session['refreshToken']])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'luis@syscon.pe', 'password' => 'staff-password-1234'])->assertForbidden();

        $this->postJson("/api/admin/users/{$admin->id}/reactivate", [], $super)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $again = $this->login('luis@syscon.pe', 'staff-password-1234');
        $reset = $this->postJson("/api/admin/users/{$admin->id}/reset-password", [], $super)->assertOk()->json('data');
        $this->assertTrue($reset['user']['mustChangePassword']);
        $this->postJson('/api/auth/refresh', ['refreshToken' => $again['refreshToken']])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'luis@syscon.pe', 'password' => 'staff-password-1234'])->assertUnauthorized();
        $this->login('luis@syscon.pe', $reset['temporaryPassword']);
    }

    public function test_superadmin_cannot_manage_itself_other_superadmins_or_customers(): void
    {
        $super = $this->token('SUPERADMIN');
        $self = DB::table('users')->where('role', 'SUPERADMIN')->value('id');
        $other = User::create(['email' => 'otro@syscon.pe', 'password' => 'staff-password-1234', 'role' => 'SUPERADMIN']);
        $customer = User::create(['email' => 'cliente@syscon.pe', 'password' => 'staff-password-1234', 'role' => 'CUSTOMER']);
        $this->postJson("/api/admin/users/$self/suspend", [], $super)->assertConflict();
        $this->postJson("/api/admin/users/{$other->id}/suspend", [], $super)->assertForbidden();
        $this->postJson("/api/admin/users/{$customer->id}/reset-password", [], $super)->assertForbidden();
        $this->getJson('/api/admin/users?role=CUSTOMER', $super)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_audit_log_records_staff_changes_without_reads_or_customers(): void
    {
        $admin = $this->token('ADMIN');
        $this->getJson('/api/admin/orders', $admin)->assertOk();
        $this->postJson('/api/catalog/products', ['sku' => 'A-1', 'name' => 'Cable', 'price' => '5.00', 'stock' => 3, 'active' => true], $admin)->assertOk();
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '100.00'], $admin)->assertOk();
        $this->postJson('/api/admin/sales', [], $admin)->assertForbidden();
        $this->postJson('/api/payments', ['orderId' => (string) Str::uuid(), 'provider' => 'MANUAL'], $this->token('CUSTOMER'));
        $log = $this->getJson('/api/admin/audit?role=ADMIN', $this->token('SUPERADMIN'))->assertOk()->json('data');
        $this->assertSame(3, $log['total']);
        // Los intentos sin permiso también quedan, marcados como rechazados.
        $this->assertSame(['Registró una venta directa', 'Abrió caja', 'Guardó un producto'], array_column($log['items'], 'action'));
        $this->assertSame([false, true, true], array_column($log['items'], 'ok'));
        $this->assertSame(403, $log['items'][0]['status']);
    }

    public function test_wholesale_purchase_adds_stock_and_cost_without_changing_the_price(): void
    {
        $super = $this->token('SUPERADMIN');
        $a = $this->product(2);
        $b = $this->product(0);
        $purchase = $this->postJson('/api/admin/purchases', ['supplierName' => 'Mayorista Lima', 'documentRef' => 'F001-123', 'items' => [
            ['productId' => $a['id'], 'quantity' => 10, 'unitCost' => '80.50'], ['productId' => $b['id'], 'quantity' => 4, 'unitCost' => '95'],
        ]], $super)->assertCreated()->json('data');
        $this->assertSame('1185.00', $purchase['total']);
        $this->assertSame([12, 4], [app(CatalogService::class)->get($a['id'])['stock'], app(CatalogService::class)->get($b['id'])['stock']]);
        $this->assertSame(['120.00', '80.50'], [app(CatalogService::class)->get($a['id'])['price'], bcadd((string) DB::table('products')->where('id', $a['id'])->value('cost'), '0', 2)]);
        $this->postJson('/api/admin/purchases', ['supplierName' => 'X', 'items' => [['productId' => (string) Str::uuid(), 'quantity' => 1, 'unitCost' => '1']]], $super)->assertUnprocessable();
        $this->postJson('/api/admin/purchases', ['supplierName' => 'Mayorista', 'items' => [['productId' => (string) Str::uuid(), 'quantity' => 1, 'unitCost' => '1']]], $super)->assertNotFound();
        $this->getJson('/api/admin/purchases', $super)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.items.0.subtotal', '805.00');
    }

    public function test_internal_use_removes_only_available_stock_from_sale(): void
    {
        $super = $this->token('SUPERADMIN');
        $p = $this->product(5);
        DB::table('products')->where('id', $p['id'])->update(['reserved' => 2]);
        $this->postJson('/api/admin/internal-uses', ['productId' => $p['id'], 'quantity' => 4, 'reason' => 'Equipo para la oficina'], $super)->assertConflict();
        $use = $this->postJson('/api/admin/internal-uses', ['productId' => $p['id'], 'quantity' => 3, 'reason' => 'Equipo para la oficina'], $super)->assertCreated()->json('data');
        $this->assertSame([3, 'Equipo para la oficina'], [$use['quantity'], $use['reason']]);
        $this->assertSame(['stock' => 2, 'reserved' => 2], (array) DB::table('products')->where('id', $p['id'])->first(['stock', 'reserved']));
        $this->assertSame(0, app(CatalogService::class)->get($p['id'])['stock']);
        $this->getJson('/api/admin/internal-uses', $super)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_superadmin_command_promotes_or_creates_with_temporary_password(): void
    {
        User::create(['email' => 'jefe@syscon.pe', 'password' => 'staff-password-1234', 'role' => 'ADMIN']);
        $this->artisan('users:superadmin', ['email' => 'Jefe@Syscon.pe'])->assertSuccessful();
        $this->assertSame('SUPERADMIN', DB::table('users')->where('email', 'jefe@syscon.pe')->value('role'));
        $this->artisan('users:superadmin', ['email' => 'nuevo@syscon.pe'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'nuevo@syscon.pe', 'role' => 'SUPERADMIN', 'must_change_password' => true]);
    }
}
