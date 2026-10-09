<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use App\Modules\Invoices\SunatGateway;
use App\Modules\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.service_token' => str_repeat('s', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function token(string $role = 'CUSTOMER'): array
    {
        $user = User::create(['email' => Str::uuid().'@example.com', 'password' => 'customer-password-123', 'role' => $role]);
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password-123'])->assertOk()->json('data');

        return ['Authorization' => 'Bearer '.$login['accessToken']];
    }

    private function product(int $stock = 5, string $sku = 'SKU-1'): array
    {
        return app(CatalogService::class)->upsert(['sku' => $sku, 'name' => 'Producto & prueba', 'price' => '10.15', 'stock' => $stock, 'active' => true, 'category' => 'Computación', 'brand' => 'SYSCON']);
    }

    private function order(array $headers, array $product, int $quantity = 2): array
    {
        return $this->postJson('/api/orders', ['items' => [['productId' => $product['id'], 'quantity' => $quantity]]], $headers)->assertOk()->json('data');
    }

    private function paidOrder(): array
    {
        $customer = $this->token();
        $admin = $this->token('ADMIN');
        $order = $this->order($customer, $this->product());
        $payment = $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'MANUAL'], $customer)->assertOk()->json('data');
        $this->postJson('/api/payments/'.$payment['id'].'/confirm', ['reference' => 'BANK-001'], $admin)->assertOk();

        return [$order, $admin];
    }

    private function invoiceInput(string $order): array
    {
        return ['orderId' => $order, 'type' => '01', 'serialNumber' => 'F001-1', 'customerDocumentType' => '6', 'customerDocumentNumber' => '20123456789', 'customerName' => 'Cliente & Cía'];
    }

    public function test_authentication_rotation_logout_and_role_protection(): void
    {
        $id = $this->postJson('/api/auth/register', ['email' => 'CUSTOMER@example.com', 'password' => 'secure-password-123'])->assertCreated()->json('data');
        $this->postJson('/api/auth/register', ['email' => 'customer@example.com', 'password' => 'secure-password-123'])->assertConflict();
        $this->postJson('/api/auth/login', ['email' => 'customer@example.com', 'password' => 'bad'])->assertUnauthorized();
        $tokens = $this->postJson('/api/auth/login', ['email' => 'customer@example.com', 'password' => 'secure-password-123'])->assertOk()->json('data');
        $h = ['Authorization' => 'Bearer '.$tokens['accessToken']];
        $this->getJson('/api/auth/me', $h)->assertOk()->assertJsonPath('data', $id);
        $this->postJson('/api/catalog/products', [], $h)->assertForbidden();
        $new = $this->postJson('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']])->assertOk()->json('data');
        $this->postJson('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']])->assertUnauthorized();
        $this->postJson('/api/auth/logout', ['refreshToken' => $new['refreshToken']])->assertOk();
        $this->postJson('/api/auth/refresh', ['refreshToken' => $new['refreshToken']])->assertUnauthorized();
    }

    public function test_invalid_jwt_internal_token_and_validation_use_api_envelope(): void
    {
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer invalid'])->assertUnauthorized()->assertJsonPath('success', false);
        $this->getJson('/api/orders/internal/'.Str::uuid())->assertUnauthorized();
        $this->postJson('/api/auth/register', ['email' => 'wrong', 'password' => 'short'])->assertUnprocessable()->assertJsonPath('success', false);
    }

    public function test_catalog_upsert_and_internal_access(): void
    {
        $admin = $this->token('ADMIN');
        $data = ['sku' => 'SKU', 'name' => 'Laptop', 'price' => '123.45', 'stock' => 7, 'active' => true, 'category' => 'Laptops', 'brand' => 'Brand'];
        $p = $this->postJson('/api/catalog/products', $data, $admin)->assertOk()->json('data');
        $data['stock'] = 3;
        $this->postJson('/api/catalog/internal/products', $data, ['X-Service-Token' => str_repeat('s', 64)])->assertOk()->assertJsonPath('data.id', $p['id']);
        $this->getJson('/api/catalog/products/'.$p['id'])->assertOk()->assertJsonPath('data.stock', 3);
        $this->getJson('/api/catalog/categories')->assertOk()->assertJsonPath('data.0', 'Laptops');
        $this->getJson('/api/catalog/brands')->assertOk()->assertJsonPath('data.0', 'Brand');
        $data['price'] = '1.234';
        $this->postJson('/api/catalog/products', $data, $admin)->assertUnprocessable();
    }

    public function test_reservations_prevent_overselling_and_cancel_releases_stock(): void
    {
        $h = $this->token();
        $p = $this->product(2);
        $o = $this->order($h, $p);
        $this->getJson('/api/catalog/products/'.$p['id'])->assertOk()->assertJsonPath('data.stock', 0);
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1]]], $this->token())->assertConflict();
        $admin = $this->token('ADMIN');
        $this->patchJson('/api/orders/'.$o['id'].'/status/CANCELLED', [], $admin)->assertOk();
        $this->getJson('/api/catalog/products/'.$p['id'])->assertOk()->assertJsonPath('data.stock', 2);
        $this->patchJson('/api/orders/'.$o['id'].'/status/CANCELLED', [], $admin)->assertConflict();
    }

    public function test_failed_cart_rolls_back_all_reservations_and_duplicates_are_rejected(): void
    {
        $h = $this->token();
        $p = $this->product();
        $other = $this->product(0, 'SKU-2');
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1], ['productId' => $other['id'], 'quantity' => 1]]], $h)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'reserved' => 0]);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1], ['productId' => $p['id'], 'quantity' => 1]]], $h)->assertUnprocessable();
    }

    public function test_payment_ownership_confirmation_idempotency_and_transitions(): void
    {
        $h = $this->token();
        $other = $this->token();
        $admin = $this->token('ADMIN');
        $p = $this->product();
        $o = $this->order($h, $p, 3);
        $this->assertSame('30.45', $o['total']);
        $this->getJson('/api/orders/'.$o['id'], $other)->assertForbidden();
        $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $other)->assertForbidden();
        $body = ['orderId' => $o['id'], 'provider' => 'MANUAL'];
        $pay = $this->postJson('/api/payments', $body, $h)->assertOk()->json('data');
        $this->postJson('/api/payments', $body, $h)->assertOk()->assertJsonPath('data.id', $pay['id']);
        $this->getJson('/api/payments/'.$pay['id'], $other)->assertForbidden();
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-1'], $h)->assertForbidden();
        $this->patchJson('/api/orders/'.$o['id'].'/status/PAID', [], $admin)->assertForbidden();
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-1'], $admin)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-2'], $admin)->assertOk()->assertJsonPath('data.externalReference', 'BANK-1');
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 2, 'reserved' => 0]);
        $this->patchJson('/api/orders/'.$o['id'].'/status/DELIVERED', [], $admin)->assertConflict();
        foreach (['PROCESSING', 'SHIPPED', 'DELIVERED'] as $state) {
            $this->patchJson('/api/orders/'.$o['id'].'/status/'.$state, [], $admin)->assertOk()->assertJsonPath('data.status', $state);
        }
    }

    public function test_payment_confirmation_of_cancelled_order_rolls_back(): void
    {
        $h = $this->token();
        $admin = $this->token('ADMIN');
        $o = $this->order($h, $this->product());
        $pay = $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $h)->assertOk()->json('data');
        $this->patchJson('/api/orders/'.$o['id'].'/status/CANCELLED', [], $admin)->assertOk();
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-1'], $admin)->assertConflict();
        $this->assertDatabaseHas('payments', ['id' => $pay['id'], 'status' => 'PENDING']);
    }

    public function test_catalog_updates_cannot_erase_pending_reservations(): void
    {
        $p = $this->product();
        $this->order($this->token(), $p, 3);
        $this->postJson('/api/catalog/products', ['sku' => $p['sku'], 'name' => 'Edited', 'price' => '10.15', 'stock' => 2, 'active' => true], $this->token('ADMIN'))->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 5, 'reserved' => 3]);
    }

    public function test_supplier_csv_import_quoted_fields_history_and_rate_limit(): void
    {
        config(['commerce.supplier_hosts' => ['feeds.example.com']]);
        $admin = $this->token('ADMIN');
        $this->postJson('/api/suppliers', ['name' => 'Bad', 'feedUrl' => 'https://private.example.com/data.csv', 'minIntervalMinutes' => 15], $admin)->assertUnprocessable();
        $s = $this->postJson('/api/suppliers', ['name' => 'Feed', 'feedUrl' => 'https://feeds.example.com/data.csv', 'minIntervalMinutes' => 15], $admin)->assertOk()->json('data');
        Http::fake(['feeds.example.com/*' => Http::response("sku;name;price;stock;imageUrl;brand;category\nCSV-1;\"Producto; especial\";5.25;10;;Brand;Category\n", 200)]);
        $job = $this->postJson('/api/suppliers/'.$s['id'].'/run', [], $admin)->assertOk()->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.imported', 1)->json('data');
        $this->assertDatabaseHas('products', ['sku' => 'CSV-1', 'name' => 'Producto; especial']);
        $this->getJson('/api/suppliers/jobs/'.$job['id'].'/history', $admin)->assertOk()->assertJsonPath('data.0.level', 'INFO');
        $this->postJson('/api/suppliers/'.$s['id'].'/run', [], $admin)->assertTooManyRequests();
    }

    public function test_invalid_supplier_feed_does_not_partially_import(): void
    {
        config(['commerce.supplier_hosts' => ['feeds.example.com']]);
        $admin = $this->token('ADMIN');
        $s = $this->postJson('/api/suppliers', ['name' => 'Feed', 'feedUrl' => 'https://feeds.example.com/data.csv', 'minIntervalMinutes' => 15], $admin)->assertOk()->json('data');
        Http::fake(['*' => Http::response("sku;name;price;stock;imageUrl;brand;category\nGOOD;Good;2.00;3;;;\nBAD;Bad;-1;1;;;\n", 200)]);
        $this->postJson('/api/suppliers/'.$s['id'].'/run', [], $admin)->assertOk()->assertJsonPath('data.status', 'FAILED');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_invoice_paid_order_requirement_unique_serial_xml_and_missing_credentials(): void
    {
        $h = $this->token();
        $admin = $this->token('ADMIN');
        $o = $this->order($h, $this->product());
        $this->postJson('/api/invoices', $this->invoiceInput($o['id']), $h)->assertForbidden();
        $this->postJson('/api/invoices', $this->invoiceInput($o['id']), $admin)->assertConflict();
        app(OrderService::class)->transition($o['id'], 'PAID');
        $input = $this->invoiceInput($o['id']);
        $i = $this->postJson('/api/invoices', $input, $admin)->assertOk()->assertJsonPath('data.status', 'DRAFT')->json('data');
        $this->postJson('/api/invoices', $input, $admin)->assertConflict();
        $xml = DB::table('xml_documents')->where('invoice_id', $i['id'])->value('generated_xml');
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $this->assertStringContainsString('Cliente &amp; Cía', $xml);
        $this->postJson('/api/invoices/'.$i['id'].'/submit', [], $admin)->assertStatus(503);
        $this->assertDatabaseHas('invoices', ['id' => $i['id'], 'status' => 'DRAFT']);
        $input['type'] = '07';
        $input['serialNumber'] = 'F001-2';
        $this->postJson('/api/invoices', $input, $admin)->assertUnprocessable();
        $input['referencedSerialNumber'] = 'F001-1';
        $this->postJson('/api/invoices', $input, $admin)->assertConflict();
    }

    public function test_invoice_submission_records_cdr_and_is_idempotent(): void
    {
        [$o,$admin] = $this->paidOrder();
        $i = $this->postJson('/api/invoices', $this->invoiceInput($o['id']), $admin)->assertOk()->json('data');
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->once()->andReturn(true);
        $gateway->shouldReceive('sign')->once()->andReturn('<signed/>');
        $gateway->shouldReceive('sendBill')->once()->andReturn('CDR');
        $gateway->shouldReceive('parseCdr')->once()->with('CDR')->andReturn(['0', 'Aceptado']);
        $this->postJson('/api/invoices/'.$i['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED')->assertJsonPath('data.sunatCode', '0');
        $this->postJson('/api/invoices/'.$i['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $input = $this->invoiceInput($o['id']);
        $input['type'] = '07';
        $input['serialNumber'] = 'F001-2';
        $input['referencedSerialNumber'] = 'F001-1';
        $this->postJson('/api/invoices', $input, $admin)->assertOk();
    }

    public function test_invoice_xml_and_cdr_larger_than_mysql_text_limit_are_preserved(): void
    {
        [$order, $admin] = $this->paidOrder();
        $invoice = $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertOk()->json('data');
        $signedXml = '<signed>'.str_repeat('x', 100000).'</signed>';
        $cdr = base64_encode(str_repeat('cdr-bytes', 10000));
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->andReturn(true);
        $gateway->shouldReceive('sign')->andReturn($signedXml);
        $gateway->shouldReceive('sendBill')->andReturn($cdr);
        $gateway->shouldReceive('parseCdr')->andReturn(['0', 'Aceptado']);
        $this->postJson('/api/invoices/'.$invoice['id'].'/submit', [], $admin)
            ->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertSame($signedXml, DB::table('xml_documents')->where('invoice_id', $invoice['id'])->value('signed_xml'));
        $this->assertSame($cdr, DB::table('cdr_responses')->where('invoice_id', $invoice['id'])->value('zip_base64'));
    }

    public function test_invoice_submission_failure_is_persisted(): void
    {
        [$o,$admin] = $this->paidOrder();
        $i = $this->postJson('/api/invoices', $this->invoiceInput($o['id']), $admin)->assertOk()->json('data');
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->andReturn(true);
        $gateway->shouldReceive('sign')->andThrow(new \RuntimeException('Firma inválida'));
        $this->postJson('/api/invoices/'.$i['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.error', 'Firma inválida');
    }
}
