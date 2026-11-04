<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null]);
    }

    private function headers(string $role): array
    {
        $user = User::create(['email' => strtolower($role).'@catalog.test', 'password' => 'catalog-password-123', 'role' => $role]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'catalog-password-123'])->assertOk()->json('data.accessToken');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function product(): array
    {
        return app(CatalogService::class)->upsert(['sku' => 'STOCK-1', 'name' => 'Router', 'price' => '90.00', 'stock' => 10, 'active' => true]);
    }

    public function test_administration_is_private_and_preserves_physical_stock_when_renaming(): void
    {
        $product = $this->product();
        DB::table('products')->where('id', $product['id'])->update(['reserved' => 3]);
        $this->getJson('/api/catalog/admin/products')->assertUnauthorized();
        $this->getJson('/api/catalog/admin/products', $this->headers('CUSTOMER'))->assertForbidden();
        $admin = $this->headers('ADMIN');
        $row = $this->getJson('/api/catalog/admin/products', $admin)->assertOk()
            ->assertJsonPath('data.0.stock', 7)->assertJsonPath('data.0.physicalStock', 10)->assertJsonPath('data.0.reservedStock', 3)->json('data.0');
        $row['stock'] = $row['physicalStock'];
        $row['name'] = 'Router actualizado';
        $this->postJson('/api/catalog/products', $row, $admin)->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 10, 'reserved' => 3]);
        $this->getJson('/api/catalog/products')->assertOk()->assertJsonPath('data.0.stock', 7)->assertJsonMissingPath('data.0.physicalStock');
    }

    public function test_inactive_products_are_hidden_publicly_but_can_be_reactivated_in_administration(): void
    {
        $product = $this->product();
        DB::table('products')->where('id', $product['id'])->update(['active' => false]);
        $this->getJson('/api/catalog/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/catalog/products/'.$product['id'])->assertNotFound();
        $admin = $this->headers('ADMIN');
        $row = $this->getJson('/api/catalog/admin/products', $admin)->assertOk()->assertJsonPath('data.0.active', false)->json('data.0');
        $this->postJson('/api/catalog/products', array_replace($row, ['active' => true, 'stock' => $row['physicalStock']]), $admin)->assertOk();
        $this->getJson('/api/catalog/products/'.$product['id'])->assertOk();
    }

    public function test_description_and_specs_survive_an_import_that_omits_them(): void
    {
        $product = $this->product();
        $service = app(CatalogService::class);
        $input = array_replace($product, ['description' => 'Router para oficina', 'specifications' => "Puertos: 8\nVelocidad: 1 Gbps"]);
        $service->upsert($input);
        $feed = array_diff_key($product, array_flip(['description', 'specifications']));
        $service->upsert(array_replace($feed, ['name' => 'Nombre del proveedor']));
        $this->getJson('/api/catalog/products/'.$product['id'])->assertOk()
            ->assertJsonPath('data.description', 'Router para oficina')->assertJsonPath('data.specifications', "Puertos: 8\nVelocidad: 1 Gbps");
    }
}
