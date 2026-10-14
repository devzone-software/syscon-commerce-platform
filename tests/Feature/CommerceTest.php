<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use App\Modules\Invoices\InvoiceFiles;
use App\Modules\Invoices\InvoiceSubmission;
use App\Modules\Invoices\SunatFault;
use App\Modules\Invoices\SunatGateway;
use App\Modules\Notifications\Notifier;
use App\Modules\Notifications\OrderMail;
use App\Modules\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
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

    /** Boleta con DNI: el bloque de facturación que exige la creación de pedidos. */
    private const BILLING = ['type' => '03', 'documentType' => '1', 'documentNumber' => '12345678', 'name' => 'Ana Quispe'];

    /** Retiro en tienda: la entrega más simple que acepta la creación de pedidos. */
    private const PICKUP = ['method' => 'PICKUP', 'contactName' => 'Ana Quispe', 'contactPhone' => '987654321'];

    /** RUC con dígito verificador válido (módulo 11). */
    private const RUC = '20123456786';

    private function order(array $headers, array $product, int $quantity = 2, array $billing = self::BILLING, array $delivery = self::PICKUP): array
    {
        return $this->postJson('/api/orders', ['items' => [['productId' => $product['id'], 'quantity' => $quantity]], 'billing' => $billing, 'delivery' => $delivery], $headers)->assertOk()->json('data');
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
        return ['orderId' => $order, 'type' => '01', 'customerDocumentType' => '6', 'customerDocumentNumber' => self::RUC, 'customerName' => 'Cliente & Cía'];
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

    public function test_register_stores_profile_and_jwt_carries_identity_claims(): void
    {
        $profile = ['email' => 'ana@example.com', 'password' => 'secure-password-123', 'firstName' => 'Ana', 'lastName' => 'Quispe', 'documentType' => 'DNI', 'documentNumber' => '12345678', 'phone' => '987654321'];
        $id = $this->postJson('/api/auth/register', $profile)->assertCreated()->json('data');
        $this->assertDatabaseHas('users', ['id' => $id, 'first_name' => 'Ana', 'last_name' => 'Quispe', 'document_type' => 'DNI', 'document_number' => '12345678', 'phone' => '987654321']);
        $this->postJson('/api/auth/register', ['phone' => '123'] + $profile + ['email' => 'otro@example.com'])->assertUnprocessable();
        $token = $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'secure-password-123'])->json('data.accessToken');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertSame(['ana@example.com', 'Ana', 'Quispe'], [$claims['email'], $claims['given_name'], $claims['family_name']]);
    }

    public function test_google_login_verifies_audience_and_creates_customer_once(): void
    {
        config(['services.google.client_id' => 'web-client']);
        $claims = ['aud' => 'web-client', 'iss' => 'https://accounts.google.com', 'sub' => 'g-1', 'email' => 'Luis@Gmail.com', 'email_verified' => 'true', 'exp' => (string) (time() + 300), 'given_name' => 'Luis', 'family_name' => 'Rojas'];
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()->push($claims)->push($claims)->push(['aud' => 'other-client'] + $claims)->push(['error' => 'invalid_token'], 400)]);
        $first = $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertOk()->json('data');
        $this->assertTrue($first['created']);
        $this->assertArrayHasKey('refreshToken', $first);
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertOk()->assertJsonPath('data.created', false);
        $this->assertDatabaseHas('users', ['email' => 'luis@gmail.com', 'google_sub' => 'g-1', 'first_name' => 'Luis', 'role' => 'CUSTOMER']);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertUnauthorized();
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertUnauthorized();
        config(['services.google.client_id' => '']);
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertStatus(503);
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
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1]], 'billing' => self::BILLING, 'delivery' => self::PICKUP], $this->token())->assertConflict();
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
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1], ['productId' => $other['id'], 'quantity' => 1]], 'billing' => self::BILLING, 'delivery' => self::PICKUP], $h)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'reserved' => 0]);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/orders', ['items' => [['productId' => $p['id'], 'quantity' => 1], ['productId' => $p['id'], 'quantity' => 1]], 'billing' => self::BILLING, 'delivery' => self::PICKUP], $h)->assertUnprocessable();
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
        $this->assertDatabaseHas('payments', ['id' => $pay['id'], 'status' => 'CANCELLED', 'external_reference' => null]);
        $this->assertDatabaseHas('orders', ['id' => $o['id'], 'status' => 'CANCELLED']);
    }

    public function test_unpaid_orders_expire_once_and_release_stock(): void
    {
        $h = $this->token();
        $admin = $this->token('ADMIN');
        $p = $this->product();
        $o = $this->order($h, $p, 3);
        $this->assertNotNull($o['expiresAt']);
        $paid = $this->order($h, $p, 1);
        $payPaid = $this->postJson('/api/payments', ['orderId' => $paid['id'], 'provider' => 'MANUAL'], $h)->assertOk()->json('data');
        $this->postJson('/api/payments/'.$payPaid['id'].'/confirm', ['reference' => 'BANK-9'], $admin)->assertOk();
        $pay = $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $h)->assertOk()->json('data');
        $this->assertSame(0, app(OrderService::class)->expireDue());
        $this->patchJson('/api/orders/'.$o['id'].'/status/EXPIRED', [], $admin)->assertForbidden();

        $this->travel(49)->hours();
        $this->artisan('orders:expire')->expectsOutput('Pedidos vencidos: 1')->assertSuccessful();
        $this->assertSame(0, app(OrderService::class)->expireDue());
        $this->assertDatabaseHas('orders', ['id' => $o['id'], 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('orders', ['id' => $paid['id'], 'status' => 'PAID']);
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'stock' => 4, 'reserved' => 0]);
        $this->assertDatabaseHas('payments', ['id' => $pay['id'], 'status' => 'CANCELLED']);
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-1'], $admin)->assertConflict();
        $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $h)->assertConflict();
        $this->assertDatabaseHas('order_events', ['order_id' => $o['id'], 'type' => 'EXPIRED', 'actor_id' => null, 'from_status' => 'PAYMENT_PENDING', 'to_status' => 'EXPIRED']);
        $this->getJson('/api/orders/'.$o['id'], $h)->assertOk()->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.expiresAt', null);
    }

    public function test_reservation_without_deadline_never_expires(): void
    {
        config(['commerce.orders.reservation_hours' => 0]);
        $o = $this->order($this->token(), $this->product());
        $this->assertNull($o['expiresAt']);
        $this->travel(365)->days();
        $this->assertSame(0, app(OrderService::class)->expireDue());
    }

    public function test_manual_payment_shows_instructions_only_while_pending(): void
    {
        config(['commerce.payments.manual_instructions' => "BCP 123-456\nAvisar al 987654321"]);
        $h = $this->token();
        $o = $this->order($h, $this->product());
        $pay = $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $h)->assertOk()
            ->assertJsonPath('data.instructions', "BCP 123-456\nAvisar al 987654321")->json('data');
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'OP-77'], $this->token('ADMIN'))->assertOk()
            ->assertJsonPath('data.instructions', null)->assertJsonPath('data.status', 'APPROVED');
        $this->assertNotNull(DB::table('payments')->where('id', $pay['id'])->value('confirmed_at'));
    }

    public function test_admin_inboxes_list_filter_and_detail_with_history(): void
    {
        $h = $this->token();
        $other = $this->token();
        $admin = $this->token('ADMIN');
        $p = $this->product(10);
        $first = $this->order($h, $p, 1);
        $second = $this->order($other, $p, 1, ['type' => '01', 'documentType' => '6', 'documentNumber' => self::RUC, 'name' => 'Empresa Andina SAC', 'address' => 'Av. Lima 123']);
        $pay = $this->postJson('/api/payments', ['orderId' => $first['id'], 'provider' => 'MANUAL'], $h)->assertOk()->json('data');
        $this->travel(1)->minutes();
        $this->postJson('/api/payments', ['orderId' => $second['id'], 'provider' => 'MANUAL'], $other)->assertOk();

        $this->getJson('/api/admin/orders', $h)->assertForbidden();
        $this->getJson('/api/admin/payments', $h)->assertForbidden();
        $this->getJson('/api/admin/orders/'.$first['id'], $h)->assertForbidden();
        $this->getJson('/api/admin/orders?status=UNKNOWN', $admin)->assertStatus(422);

        $this->getJson('/api/admin/payments', $admin)->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.id', $pay['id'])->assertJsonPath('data.items.0.orderStatus', 'PAYMENT_PENDING');
        $this->postJson('/api/payments/'.$pay['id'].'/confirm', ['reference' => 'BANK-55'], $admin)->assertOk();
        $this->getJson('/api/admin/payments', $admin)->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/payments?status=APPROVED&q=BANK-55', $admin)->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.externalReference', 'BANK-55');

        $list = $this->getJson('/api/admin/orders?perPage=1', $admin)->assertOk()->json('data');
        $this->assertSame(2, $list['total']);
        $this->assertCount(1, $list['items']);
        $this->assertSame(['PAYMENT_PENDING' => 1, 'PAID' => 1], array_filter($list['counts']));
        $this->getJson('/api/admin/orders?status=PAID', $admin)->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $first['id'])->assertJsonPath('data.items.0.payment.status', 'APPROVED');
        $this->getJson('/api/admin/orders?q=andina', $admin)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $second['id']);
        $this->getJson('/api/admin/orders?q='.self::RUC, $admin)->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/orders?q='.substr($first['id'], 0, 8), $admin)->assertOk()->assertJsonPath('data.items.0.id', $first['id']);
        $this->getJson('/api/admin/orders?q=100%25', $admin)->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/admin/orders?from=2000-01-02&to=2000-01-01', $admin)->assertStatus(422);

        $this->patchJson('/api/orders/'.$first['id'].'/status/PROCESSING', [], $admin)->assertOk();
        $detail = $this->getJson('/api/admin/orders/'.$first['id'], $admin)->assertOk()->json('data');
        $this->assertSame('PROCESSING', $detail['status']);
        $this->assertNotNull($detail['customer']['email']);
        $this->assertNotNull($detail['payments'][0]['confirmedBy']);
        $this->assertSame(['CREATED', 'PAYMENT_REQUESTED', 'PAYMENT_CONFIRMED', 'STATUS_CHANGED'], array_column($detail['events'], 'type'));
        $this->assertSame('BANK-55', $detail['events'][2]['reference']);
        $this->assertSame($detail['payments'][0]['confirmedBy'], $detail['events'][2]['actor']);
        $this->assertSame($detail['customer']['email'], $detail['events'][0]['actor']);
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

    public function test_order_requires_valid_billing_and_keeps_a_copy(): void
    {
        $h = $this->token();
        $p = $this->product(10);
        $items = [['productId' => $p['id'], 'quantity' => 1]];
        $this->postJson('/api/orders', ['items' => $items, 'delivery' => self::PICKUP], $h)->assertUnprocessable()->assertJsonPath('success', false);
        $invalid = [
            ['documentNumber' => '1234567'] + self::BILLING,
            ['type' => '01', 'documentType' => '1'] + self::BILLING,
            ['type' => '01', 'documentType' => '6', 'documentNumber' => '20123456789'] + self::BILLING,
            ['documentType' => '7', 'documentNumber' => 'AB#1'] + self::BILLING,
            ['name' => ' '] + self::BILLING,
            ['type' => '07'] + self::BILLING,
        ];
        foreach ($invalid as $billing) {
            $this->postJson('/api/orders', ['items' => $items, 'billing' => $billing, 'delivery' => self::PICKUP], $h)->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'reserved' => 0]);

        $factura = ['type' => '01', 'documentType' => '6', 'documentNumber' => ' 2012 3456 786 ', 'name' => '  Comercial  Andina SAC ', 'address' => 'Av. Arequipa 123, Lima'];
        $o = $this->order($h, $p, 1, $factura);
        $this->assertSame(['type' => '01', 'documentType' => '6', 'documentNumber' => self::RUC, 'name' => 'Comercial Andina SAC', 'address' => 'Av. Arequipa 123, Lima', 'verification' => null], $o['billing']);
        $this->assertNotNull($o['createdAt']);
        $this->getJson('/api/orders/'.$o['id'], $h)->assertOk()->assertJsonPath('data.billing.name', 'Comercial Andina SAC');
        // Se guarda en el propio pedido (no se lee del perfil), así que cambios posteriores del perfil no la alteran.
        $this->assertDatabaseHas('orders', ['id' => $o['id'], 'billing_document_number' => self::RUC]);
        $this->assertNull($this->order($h, $p, 1, ['address' => ''] + self::BILLING)['billing']['address']);
    }

    public function test_delivery_cost_is_set_by_server_and_included_in_total(): void
    {
        config(['commerce.delivery.fee' => '', 'commerce.delivery.pickup_address' => 'Av. Principal 100, Lima']);
        $h = $this->token();
        $p = $this->product(10);
        $items = [['productId' => $p['id'], 'quantity' => 2]];
        $home = ['method' => 'DELIVERY', 'contactName' => 'Ana Quispe', 'contactPhone' => '987 654 321', 'address' => 'Jr. Las Flores 245', 'district' => 'Miraflores', 'reference' => 'Frente al parque', 'cost' => '0.01'];
        $this->getJson('/api/orders/delivery-options')->assertOk()->assertJsonPath('data.delivery.enabled', false)->assertJsonPath('data.pickup.address', 'Av. Principal 100, Lima');
        // Sin tarifa configurada el envío no está disponible.
        $this->postJson('/api/orders', ['items' => $items, 'billing' => self::BILLING, 'delivery' => $home], $h)->assertUnprocessable();
        $this->postJson('/api/orders', ['items' => $items, 'billing' => self::BILLING], $h)->assertUnprocessable();
        foreach ([['contactPhone' => '12345'] + self::PICKUP, ['address' => ''] + $home, ['district' => null] + $home, ['method' => 'DRONE'] + self::PICKUP] as $delivery) {
            config(['commerce.delivery.fee' => '15']);
            $this->postJson('/api/orders', ['items' => $items, 'billing' => self::BILLING, 'delivery' => $delivery], $h)->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 0);

        config(['commerce.delivery.fee' => '15']);
        $this->getJson('/api/orders/delivery-options')->assertOk()->assertJsonPath('data.delivery.cost', '15.00');
        // El costo enviado por el cliente se ignora: lo fija el servidor.
        $o = $this->order($h, $p, 2, self::BILLING, $home);
        $this->assertSame(['20.30', '15.00', '35.30'], [$o['subtotal'], $o['delivery']['cost'], $o['total']]);
        $this->assertSame(['DELIVERY', '987654321', 'Jr. Las Flores 245', 'Miraflores'], [$o['delivery']['method'], $o['delivery']['contactPhone'], $o['delivery']['address'], $o['delivery']['district']]);
        $payment = $this->postJson('/api/payments', ['orderId' => $o['id'], 'provider' => 'MANUAL'], $h)->assertOk()->json('data');
        $this->assertSame('35.30', $payment['amount']);

        // Retirar en tienda es gratis y no guarda dirección aunque llegue una.
        $pickup = $this->order($h, $p, 1, self::BILLING, ['address' => 'Jr. Las Flores 245', 'district' => 'Miraflores'] + self::PICKUP);
        $this->assertSame(['10.15', '0.00', null], [$pickup['total'], $pickup['delivery']['cost'], $pickup['delivery']['address']]);
    }

    public function test_idempotency_key_returns_the_same_order_without_reserving_twice(): void
    {
        $h = $this->token();
        $p = $this->product(5);
        $key = (string) Str::uuid();
        $body = ['items' => [['productId' => $p['id'], 'quantity' => 2]], 'billing' => self::BILLING, 'delivery' => self::PICKUP, 'idempotencyKey' => $key];
        $first = $this->postJson('/api/orders', $body, $h)->assertOk()->assertJsonPath('message', 'Pedido creado')->json('data');
        $again = $this->postJson('/api/orders', $body, $h)->assertOk()->assertJsonPath('message', 'Pedido ya registrado')->json('data');
        $this->assertSame($first, $again);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', ['id' => $p['id'], 'reserved' => 2]);
        // La misma clave con otro contenido no crea otro pedido.
        $body['items'][0]['quantity'] = 1;
        $this->postJson('/api/orders', $body, $h)->assertUnprocessable();
        // Las claves son por cliente; otro cliente puede usar la misma.
        $this->postJson('/api/orders', $body, $this->token())->assertOk();
        $this->assertDatabaseCount('orders', 2);
        $this->postJson('/api/orders', ['idempotencyKey' => 'no-uuid'] + $body, $h)->assertUnprocessable();
    }

    public function test_invoice_xml_lists_delivery_so_lines_match_the_total(): void
    {
        config(['commerce.delivery.fee' => '12.50']);
        $h = $this->token();
        $admin = $this->token('ADMIN');
        $o = $this->order($h, $this->product(), 1, self::BILLING, ['method' => 'DELIVERY', 'address' => 'Jr. Las Flores 245', 'district' => 'Miraflores'] + self::PICKUP);
        app(OrderService::class)->transition($o['id'], 'PAID');
        $invoice = $this->postJson('/api/invoices', ['orderId' => $o['id'], 'type' => '03'], $admin)->assertOk()->json('data');
        $xml = DB::table('xml_documents')->where('invoice_id', $invoice['id'])->value('generated_xml');
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        // Valores sin IGV: el total del documento y luego cada línea (producto y envío).
        $lines = array_map(fn ($n) => $n->textContent, iterator_to_array($doc->getElementsByTagName('LineExtensionAmount')));
        $this->assertSame(['19.19', '8.60', '10.59'], $lines);
        $this->assertSame('22.65', $doc->getElementsByTagName('PayableAmount')->item(0)->textContent);
        $this->assertStringContainsString('Servicio de envío a domicilio', $xml);
        $this->assertSame(['taxableAmount' => '19.19', 'taxAmount' => '3.46', 'total' => '22.65'], array_intersect_key($invoice, array_flip(['taxableAmount', 'taxAmount', 'total'])));
        $this->assertSame(['ZZ', '12.50', '1.91'], [$invoice['lines'][1]['unitCode'], $invoice['lines'][1]['lineTotal'], $invoice['lines'][1]['taxAmount']]);
    }

    public function test_order_and_invoice_split_igv_per_line_and_document(): void
    {
        $h = $this->token();
        $order = $this->order($h, $this->product(), 3);
        // 3 × 10.15 = 30.45 con IGV: base 30.45 / 1.18 = 25.81 y el IGV es la diferencia.
        $this->assertSame(['rate' => '0.1800', 'taxableAmount' => '25.81', 'taxAmount' => '4.64'], $order['taxes']);
        $this->assertSame(['lineTotal' => '30.45', 'taxableAmount' => '25.81', 'taxAmount' => '4.64'], array_intersect_key($order['details'][0], array_flip(['lineTotal', 'taxableAmount', 'taxAmount'])));
        $this->assertSame('30.45', $order['total']);

        $admin = $this->token('ADMIN');
        app(OrderService::class)->transition($order['id'], 'PAID');
        $invoice = $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertOk()->json('data');
        $doc = new \DOMDocument;
        $doc->loadXML(DB::table('xml_documents')->where('invoice_id', $invoice['id'])->value('generated_xml'));
        $x = new \DOMXPath($doc);
        $x->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $x->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $value = fn (string $path) => $x->evaluate('string('.$path.')');
        $this->assertSame('0101', $value('/*/cbc:InvoiceTypeCode/@listID'));
        $this->assertSame('4.64', $value('/*/cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('25.81', $value('/*/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'));
        $this->assertSame('1000', $value('/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:ID'));
        $this->assertSame('30.45', $value('/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('Contado', $value('/*/cac:PaymentTerms/cbc:PaymentMeansID'));
        $this->assertSame('3', $value('/*/cac:InvoiceLine/cbc:InvoicedQuantity'));
        $this->assertSame('10.15', $value('/*/cac:InvoiceLine/cac:PricingReference/cac:AlternativeConditionPrice/cbc:PriceAmount'));
        $this->assertSame('18', $value('/*/cac:InvoiceLine/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent'));
        $this->assertSame('10', $value('/*/cac:InvoiceLine/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode'));
        $this->assertSame('8.6016949153', $value('/*/cac:InvoiceLine/cac:Price/cbc:PriceAmount'));
        $this->assertSame('SKU-1', $value('/*/cac:InvoiceLine/cac:Item/cac:SellersItemIdentification/cbc:ID'));
        $this->assertSame(self::RUC, $value('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID'));
        $this->assertSame('Cliente & Cía', $value('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'));
        // Una nueva tasa no altera lo ya vendido.
        config(['commerce.tax.igv_rate' => '0.10']);
        $this->patchJson('/api/invoices/'.$invoice['id'], ['customerDocumentType' => '6', 'customerDocumentNumber' => self::RUC, 'customerName' => 'Cliente SAC'], $admin)->assertOk()->assertJsonPath('data.taxAmount', '4.64');
        $this->assertSame('25.81', $this->getJson('/api/orders/'.$order['id'], $h)->json('data.taxes.taxableAmount'));
    }

    private function acceptSubmissions(): void
    {
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->andReturn(true);
        $gateway->shouldReceive('sign')->andReturnUsing(fn ($xml) => $xml)->byDefault();
        $gateway->shouldReceive('sendBill')->andReturn('CDR');
        $gateway->shouldReceive('parseCdr')->andReturn(['0', 'Aceptado'])->byDefault();
    }

    public function test_credit_note_annulment_allows_reissuing_the_receipt(): void
    {
        [$order, $admin] = $this->paidOrder();
        $this->acceptSubmissions();
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->json('data');
        $note = ['orderId' => $order['id'], 'type' => '07', 'referencedSerialNumber' => $boleta['serialNumber'], 'noteCode' => '01', 'noteReason' => 'El cliente pidió factura'];
        $this->postJson('/api/invoices', $note, $admin)->assertConflict();
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $this->postJson('/api/invoices', ['noteCode' => null] + $note, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', ['noteCode' => '05'] + $note, $admin)->assertUnprocessable();
        $credit = $this->postJson('/api/invoices', $note, $admin)->assertOk()->assertJsonPath('data.serialNumber', 'BC01-1')
            ->assertJsonPath('data.total', $boleta['total'])->assertJsonPath('data.taxAmount', $boleta['taxAmount'])->assertJsonPath('data.noteCode', '01')->json('data');
        $this->assertCount(count($boleta['lines']), $credit['lines']);
        // Reintento: misma nota, sin otro correlativo.
        $this->postJson('/api/invoices', $note, $admin)->assertOk()->assertJsonPath('data.id', $credit['id']);
        $this->postJson('/api/invoices', ['noteCode' => '09', 'amount' => '1.00'] + $note, $admin)->assertUnprocessable();

        $doc = new \DOMDocument;
        $doc->loadXML(DB::table('xml_documents')->where('invoice_id', $credit['id'])->value('generated_xml'));
        $this->assertSame('CreditNote', $doc->documentElement->localName);
        $this->assertSame('01', $doc->getElementsByTagName('ResponseCode')->item(0)->textContent);
        $this->assertSame('El cliente pidió factura', $doc->getElementsByTagName('DiscrepancyResponse')->item(0)->getElementsByTagName('Description')->item(0)->textContent);
        $this->assertSame('03', $doc->getElementsByTagName('DocumentTypeCode')->item(0)->textContent);
        $this->assertSame(0, $doc->getElementsByTagName('InvoiceTypeCode')->length);

        // Mientras la anulación no sea aceptada, el pedido sigue facturado con la boleta.
        $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertConflict();
        $this->postJson('/api/invoices/'.$credit['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertOk()->assertJsonPath('data.serialNumber', 'F001-1');
        $events = DB::table('order_events')->where('order_id', $order['id'])->whereIn('type', ['INVOICE_ACCEPTED', 'CREDIT_NOTE_ACCEPTED'])->orderBy('id')->pluck('reference', 'type')->all();
        $this->assertSame(['INVOICE_ACCEPTED' => 'B001-1', 'CREDIT_NOTE_ACCEPTED' => 'BC01-1'], $events);
    }

    public function test_partial_credit_and_debit_notes_respect_invoiced_amounts(): void
    {
        $customer = $this->token();
        $admin = $this->token('ADMIN');
        $product = $this->product();
        $order = $this->order($customer, $product, 3);
        app(OrderService::class)->transition($order['id'], 'PAID');
        $this->acceptSubmissions();
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->json('data');
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk();
        $base = ['orderId' => $order['id'], 'type' => '07', 'referencedSerialNumber' => 'B001-1', 'noteCode' => '07', 'noteReason' => 'Devolución de una unidad'];

        $this->postJson('/api/invoices', $base, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', $base + ['items' => [['productId' => (string) Str::uuid(), 'quantity' => 1]]], $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', $base + ['items' => [['productId' => $product['id'], 'quantity' => 4]]], $admin)->assertUnprocessable();
        $return = $this->postJson('/api/invoices', $base + ['items' => [['productId' => $product['id'], 'quantity' => 2]]], $admin)->assertOk()->json('data');
        $this->assertSame(['20.30', '17.20', '3.10'], [$return['total'], $return['taxableAmount'], $return['taxAmount']]);
        $this->assertSame([2, '10.15', 'NIU'], [$return['lines'][0]['quantity'], $return['lines'][0]['unitPrice'], $return['lines'][0]['unitCode']]);
        // Ya se devolvieron 2 de 3 unidades.
        $this->postJson('/api/invoices', $base + ['items' => [['productId' => $product['id'], 'quantity' => 2]]], $admin)->assertUnprocessable();
        // Una disminución no puede superar lo que queda por acreditar (30.45 − 20.30 = 10.15).
        $decrease = ['noteCode' => '09', 'noteReason' => 'Descuento posterior'] + $base;
        $this->postJson('/api/invoices', $decrease, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', ['amount' => '10.16'] + $decrease, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', ['amount' => '10.15'] + $decrease, $admin)->assertOk()->assertJsonPath('data.lines.0.unitCode', 'ZZ')->assertJsonPath('data.lines.0.description', 'Descuento posterior');
        // Con notas parciales ya no cabe una anulación total.
        $this->postJson('/api/invoices', ['noteCode' => '01'] + $base, $admin)->assertConflict();

        $debit = ['orderId' => $order['id'], 'type' => '08', 'referencedSerialNumber' => 'B001-1', 'noteCode' => '01', 'noteReason' => 'Intereses por pago tardío'];
        $this->postJson('/api/invoices', $debit, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', ['noteCode' => '07', 'amount' => '5.90'] + $debit, $admin)->assertUnprocessable();
        $note = $this->postJson('/api/invoices', ['amount' => '5.90'] + $debit, $admin)->assertOk()->assertJsonPath('data.serialNumber', 'BD01-1')->json('data');
        $this->assertSame(['5.90', '5.00', '0.90'], [$note['total'], $note['taxableAmount'], $note['taxAmount']]);
        $doc = new \DOMDocument;
        $doc->loadXML(DB::table('xml_documents')->where('invoice_id', $note['id'])->value('generated_xml'));
        $this->assertSame('5.90', $doc->getElementsByTagName('RequestedMonetaryTotal')->item(0)->getElementsByTagName('PayableAmount')->item(0)->textContent);
        $this->assertSame(1, $doc->getElementsByTagName('DebitNoteLine')->length);
    }

    public function test_profile_returns_registered_identity(): void
    {
        $this->postJson('/api/auth/register', ['email' => 'luz@example.com', 'password' => 'secure-password-123', 'firstName' => 'Luz', 'lastName' => 'Paz', 'documentType' => 'DNI', 'documentNumber' => '87654321'])->assertCreated();
        $token = $this->postJson('/api/auth/login', ['email' => 'luz@example.com', 'password' => 'secure-password-123'])->json('data.accessToken');
        $this->getJson('/api/auth/profile', ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('data.firstName', 'Luz')->assertJsonPath('data.documentType', 'DNI')->assertJsonPath('data.documentNumber', '87654321');
        $this->getJson('/api/auth/profile')->assertUnauthorized();
    }

    public function test_invoice_prefills_customer_from_order_billing_and_validates_document(): void
    {
        [$order, $admin] = $this->paidOrder();
        $base = ['orderId' => $order['id'], 'type' => '01'];
        // El pedido se compró con boleta/DNI: una factura necesita RUC explícito.
        $this->postJson('/api/invoices', $base, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', $base + ['customerDocumentType' => '6', 'customerDocumentNumber' => '20123456789'], $admin)->assertUnprocessable();
        $boleta = $this->postJson('/api/invoices', ['type' => '03'] + $base, $admin)->assertOk()->assertJsonPath('data.serialNumber', 'B001-1')->json('data');
        $this->assertDatabaseHas('invoices', ['id' => $boleta['id'], 'customer_document_type' => '1', 'customer_document_number' => '12345678', 'customer_name' => 'Ana Quispe']);
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
        $i = $this->postJson('/api/invoices', $input, $admin)->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.serialNumber', 'F001-1')->json('data');
        // Reintento: devuelve el mismo comprobante sin consumir otro correlativo.
        $this->postJson('/api/invoices', $input, $admin)->assertOk()->assertJsonPath('message', 'Comprobante ya registrado')->assertJsonPath('data.id', $i['id']);
        $this->postJson('/api/invoices', ['type' => '03'] + $input, $admin)->assertConflict();
        $this->assertDatabaseHas('invoice_series', ['type' => '01', 'series' => 'F001', 'last_number' => 1]);
        $xml = DB::table('xml_documents')->where('invoice_id', $i['id'])->value('generated_xml');
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $this->assertStringContainsString('Cliente &amp; Cía', $xml);
        $this->postJson('/api/invoices/'.$i['id'].'/submit', [], $admin)->assertStatus(503)->assertJsonPath('message', 'Credenciales SUNAT no configuradas');
        $this->assertDatabaseHas('invoices', ['id' => $i['id'], 'status' => 'DRAFT']);
        $input = ['type' => '07', 'noteCode' => '01', 'noteReason' => 'Anulación de la operación'] + $input;
        $this->postJson('/api/invoices', $input, $admin)->assertUnprocessable();
        $input['referencedSerialNumber'] = 'F001-1';
        $this->postJson('/api/invoices', $input, $admin)->assertConflict();
        $this->assertDatabaseHas('invoice_series', ['type' => '07', 'series' => 'FC01', 'last_number' => 0]);
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
        $input = ['type' => '07', 'noteCode' => '01', 'noteReason' => 'Anulación de la operación'] + $input;
        $input['referencedSerialNumber'] = 'F001-1';
        $this->postJson('/api/invoices', ['series' => 'BC01'] + $input, $admin)->assertUnprocessable();
        // La nota de una factura toma la serie de notas que empieza con F.
        $this->postJson('/api/invoices', $input, $admin)->assertOk()->assertJsonPath('data.serialNumber', 'FC01-1')->assertJsonPath('data.referencedSerialNumber', 'F001-1');
        $this->patchJson('/api/invoices/'.$i['id'], ['customerDocumentType' => '6', 'customerDocumentNumber' => self::RUC, 'customerName' => 'Otro'], $admin)->assertConflict();
    }

    public function test_invoice_numbers_are_sequential_per_series_and_configurable(): void
    {
        $admin = $this->token('ADMIN');
        $serials = [];
        foreach (range(1, 3) as $n) {
            [$order] = $this->paidOrder();
            $serials[] = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->json('data.serialNumber');
        }
        $this->assertSame(['B001-1', 'B001-2', 'B001-3'], $serials);

        $this->getJson('/api/invoices/series', $this->token())->assertForbidden();
        $this->postJson('/api/invoices/series', ['type' => '03', 'series' => 'F002'], $admin)->assertUnprocessable();
        $this->postJson('/api/invoices/series', ['type' => '03', 'series' => 'B001'], $admin)->assertConflict();
        // Una serie que viene de otro sistema continúa desde su último número.
        $b002 = $this->postJson('/api/invoices/series', ['type' => '03', 'series' => 'B002', 'startAfter' => 120], $admin)->assertOk()
            ->assertJsonPath('data.nextNumber', 'B002-121')->json('data');
        [$order] = $this->paidOrder();
        $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03', 'series' => 'B002'], $admin)->assertOk()->assertJsonPath('data.serialNumber', 'B002-121');
        $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03', 'series' => 'B002'], $admin)->assertOk()->assertJsonPath('data.serialNumber', 'B002-121');

        $b001 = collect($this->getJson('/api/invoices/series', $admin)->assertOk()->json('data'))->firstWhere('series', 'B001');
        $this->assertSame(['lastNumber' => 3, 'nextNumber' => 'B001-4', 'active' => true], array_intersect_key($b001, array_flip(['lastNumber', 'nextNumber', 'active'])));
        $this->patchJson('/api/invoices/series/'.$b001['id'], ['active' => false], $admin)->assertOk()->assertJsonPath('data.active', false);
        [$order] = $this->paidOrder();
        $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03', 'series' => 'B001'], $admin)->assertConflict();
        // Sin serie indicada se usa la siguiente activa; el conflicto anterior no consumió números.
        $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->assertJsonPath('data.serialNumber', 'B002-122');
        $this->assertDatabaseHas('invoice_series', ['id' => $b001['id'], 'last_number' => 3]);
        $this->assertDatabaseHas('invoice_series', ['id' => $b002['id'], 'last_number' => 122]);
        $this->patchJson('/api/invoices/series/999', ['active' => true], $admin)->assertNotFound();
    }

    public function test_draft_invoice_customer_can_be_corrected_before_submission(): void
    {
        [$order, $admin] = $this->paidOrder();
        $invoice = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()
            ->assertJsonPath('data.customerName', 'Ana Quispe')->json('data');
        $this->patchJson('/api/invoices/'.$invoice['id'], ['customerDocumentType' => '1', 'customerDocumentNumber' => '123', 'customerName' => 'Ana'], $admin)->assertUnprocessable();
        $this->patchJson('/api/invoices/'.$invoice['id'], ['customerDocumentType' => '1', 'customerDocumentNumber' => '87654321', 'customerName' => 'Ana María Quispe'], $admin)->assertOk()
            ->assertJsonPath('data.customerName', 'Ana María Quispe')->assertJsonPath('data.serialNumber', $invoice['serialNumber']);
        $xml = DB::table('xml_documents')->where('invoice_id', $invoice['id'])->value('generated_xml');
        $this->assertStringContainsString('Ana María Quispe', $xml);
        $this->assertStringContainsString('87654321', $xml);
        $this->assertDatabaseCount('xml_documents', 1);
        // El pedido conserva la copia del momento de compra.
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'billing_name' => 'Ana Quispe']);
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

    public function test_printed_invoice_uses_stored_lines_and_states_its_validity(): void
    {
        config(['commerce.sunat.ruc' => '20999999995', 'commerce.sunat.legal_name' => 'SYSCON S.A.C.', 'commerce.sunat.address' => 'Av. Arequipa 123, Lima']);
        [$order, $admin] = $this->paidOrder();
        $invoice = $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertOk()->json('data');
        $row = fn () => DB::table('invoices')->where('id', $invoice['id'])->first();
        $html = app(InvoiceFiles::class)->html($row());
        foreach (['SYSCON S.A.C.', 'Av. Arequipa 123, Lima', 'RUC 20999999995', 'Factura electrónica', 'F001-1', 'Cliente &amp; Cía', self::RUC, 'Producto &amp; prueba', 'S/ 17.20', 'S/ 3.10', 'S/ 20.30', 'IGV (18%)', 'Contado', 'Borrador'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $pdf = $this->get('/api/invoices/'.$invoice['id'].'/pdf', $admin)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('20999999995-01-F001-1.pdf', $pdf->headers->get('Content-Disposition'));
        $this->get('/api/invoices/'.$invoice['id'].'/xml', $admin)->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->get('/api/invoices/'.$invoice['id'].'/cdr', $admin)->assertNotFound();
        $this->get('/api/invoices/'.$invoice['id'].'/pdf', $this->token())->assertForbidden();

        // Aceptado por el servicio beta: es una prueba; en producción es la representación válida.
        DB::table('invoices')->where('id', $invoice['id'])->update(['status' => 'ACCEPTED']);
        DB::table('xml_documents')->where('invoice_id', $invoice['id'])->update(['signed_xml' => '<x><ds:DigestValue>abc123=</ds:DigestValue></x>']);
        $this->assertStringContainsString('Documento de prueba', app(InvoiceFiles::class)->html($row()));
        config(['commerce.sunat.endpoint' => 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService']);
        $html = app(InvoiceFiles::class)->html($row());
        $this->assertStringNotContainsString('sin valor tributario', $html);
        $this->assertStringContainsString('Representación impresa de la factura electrónica', $html);
        $this->assertStringContainsString('Valor resumen: abc123=', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
        $this->assertSame('20999999995|01|F001|1|3.10|20.30|'.now('America/Lima')->toDateString().'|6|'.self::RUC.'|abc123=|', InvoiceFiles::qrText($row(), 'abc123='));
        DB::table('invoices')->where('id', $invoice['id'])->update(['status' => 'REJECTED']);
        $this->assertStringContainsString('Rechazado por SUNAT', app(InvoiceFiles::class)->html($row()));
    }

    public function test_customer_emails_are_sent_once_after_each_commercial_step(): void
    {
        Mail::fake();
        config(['commerce.web_url' => 'https://tienda.test', 'commerce.payments.manual_instructions' => 'Yape 999 888 777']);
        $user = User::create(['email' => 'ana@example.com', 'password' => 'customer-password-123', 'role' => 'CUSTOMER']);
        $customer = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'customer-password-123'])->json('data.accessToken')];
        $admin = $this->token('ADMIN');
        $body = ['items' => [['productId' => $this->product()['id'], 'quantity' => 1]], 'billing' => self::BILLING, 'delivery' => self::PICKUP, 'idempotencyKey' => (string) Str::uuid()];
        $order = $this->postJson('/api/orders', $body, $customer)->assertOk()->json('data');
        // Un reintento de la misma compra no envía otro correo.
        $this->postJson('/api/orders', $body, $customer)->assertOk();
        Mail::assertSent(OrderMail::class, 1);
        Mail::assertSent(OrderMail::class, fn ($m) => $m->kind === 'ORDER_CREATED' && $m->hasTo('ana@example.com'));
        $html = (new OrderMail('ORDER_CREATED', app(OrderService::class)->get($order['id']), null, null))->render();
        $this->assertStringContainsString('Yape 999 888 777', $html);
        $this->assertStringContainsString('https://tienda.test/pedido/'.$order['id'], $html);

        $payment = $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'MANUAL'], $customer)->json('data');
        $this->postJson('/api/payments/'.$payment['id'].'/confirm', ['reference' => 'OP-1'], $admin)->assertOk();
        $this->postJson('/api/payments/'.$payment['id'].'/confirm', ['reference' => 'OP-1'], $admin)->assertOk();
        Mail::assertSent(OrderMail::class, fn ($m) => $m->kind === 'PAYMENT_CONFIRMED');
        Mail::assertSent(OrderMail::class, 2);

        // El comprobante solo se avisa cuando SUNAT lo acepta, con el PDF adjunto.
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->json('data');
        Mail::assertSent(OrderMail::class, 2);
        $this->acceptSubmissions();
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk();
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk();
        Mail::assertSent(OrderMail::class, 3);
        Mail::assertSent(OrderMail::class, fn ($m) => $m->kind === 'INVOICE_AVAILABLE' && $m->invoice->serial_number === 'B001-1'
            && count($m->attachments()) === 1 && str_ends_with($m->envelope()->subject, '· pedido '.strtoupper(substr($order['id'], 0, 8))));
        $this->assertSame(3, DB::table('notifications')->where('order_id', $order['id'])->where('status', 'SENT')->count());
        $this->getJson('/api/admin/orders/'.$order['id'], $admin)->assertOk()->assertJsonCount(3, 'data.notifications')
            ->assertJsonPath('data.notifications.2.type', 'INVOICE_AVAILABLE')->assertJsonPath('data.notifications.2.reference', $boleta['id']);
        $this->assertSame($user->id, $order['customerId']);
    }

    public function test_email_failures_do_not_undo_the_sale_and_are_retried(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP no disponible'));
        $customer = $this->token();
        $order = $this->order($customer, $this->product());
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PAYMENT_PENDING']);
        $n = DB::table('notifications')->where('order_id', $order['id'])->first();
        $this->assertSame(['FAILED', 1, 'SMTP no disponible'], [$n->status, (int) $n->attempts, $n->last_error]);
        $notifier = app(Notifier::class);
        // Antes del plazo de reintento no se vuelve a intentar.
        $this->assertSame(0, $notifier->sendDue());
        $this->assertSame(1, (int) DB::table('notifications')->where('id', $n->id)->value('attempts'));
        foreach (range(2, Notifier::MAX_ATTEMPTS) as $attempt) {
            $this->travel(2)->hours();
            $notifier->sendDue();
        }
        $n = DB::table('notifications')->where('id', $n->id)->first();
        $this->assertSame([Notifier::MAX_ATTEMPTS, null], [(int) $n->attempts, $n->next_attempt_at]);
        $this->travel(1)->days();
        $this->assertSame(0, $notifier->sendDue());

        // El reenvío manual funciona cuando el correo vuelve y no toca el pedido.
        Mail::swap(new MailFake(new MailManager(app())));
        $admin = $this->token('ADMIN');
        $this->postJson('/api/admin/notifications/'.$n->id.'/resend', [], $customer)->assertForbidden();
        $this->postJson('/api/admin/notifications/'.$n->id.'/resend', [], $admin)->assertOk()->assertJsonPath('data.status', 'SENT');
        Mail::assertSent(OrderMail::class, 1);
        $this->postJson('/api/admin/notifications/'.Str::uuid().'/resend', [], $admin)->assertNotFound();
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'type' => 'NOTIFICATION_RESENT', 'reference' => 'ORDER_CREATED']);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PAYMENT_PENDING']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_customers_download_only_accepted_documents_of_their_orders(): void
    {
        $customer = $this->token();
        $admin = $this->token('ADMIN');
        $order = $this->order($customer, $this->product());
        app(OrderService::class)->transition($order['id'], 'PAID');
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()->json('data');
        $list = '/api/orders/'.$order['id'].'/invoices';
        $file = $list.'/'.$boleta['id'];

        // El borrador no se muestra ni se descarga.
        $this->getJson($list, $customer)->assertOk()->assertJsonCount(0, 'data');
        $this->get($file.'/pdf', $customer)->assertNotFound();
        $this->acceptSubmissions();
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.hasCdr', true);

        $this->getJson($list, $customer)->assertOk()->assertJsonPath('data.0.serialNumber', 'B001-1')->assertJsonPath('data.0.test', true)->assertJsonPath('data.0.hasCdr', true);
        $this->get($file.'/pdf', $customer)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($file.'/xml', $customer)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="SYSCON-03-B001-1.xml"');
        $this->get($file.'/cdr', $customer)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get($file.'/txt', $customer)->assertNotFound();
        // Pedido ajeno y comprobante de otro pedido.
        $this->getJson($list, $this->token())->assertForbidden();
        $this->get($file.'/pdf', $this->token())->assertForbidden();
        $other = $this->order($customer, $this->product(5, 'SKU-2'));
        $this->get('/api/orders/'.$other['id'].'/invoices/'.$boleta['id'].'/pdf', $customer)->assertNotFound();
        $this->getJson('/api/orders/'.Str::uuid().'/invoices', $customer)->assertNotFound();
    }

    public function test_sales_report_reconciles_orders_payments_and_invoices(): void
    {
        Mail::fake();
        // 22:30 en Lima del 13 de octubre: la venta cuenta ese día, no el 14 (UTC).
        $this->travelTo(Carbon::parse('2026-10-14 03:30:00', 'UTC'));
        $this->acceptSubmissions();
        [$ok, $admin] = $this->paidOrder();
        $boleta = $this->postJson('/api/invoices', ['orderId' => $ok['id'], 'type' => '03'], $admin)->json('data');
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk();
        [$withoutInvoice] = $this->paidOrder();
        // Pagado por el canal interno: no hay pago aprobado registrado.
        $customer = $this->token();
        $internal = $this->order($customer, $this->product(5, 'SKU-2'));
        app(OrderService::class)->transition($internal['id'], 'PAID');
        [$returned] = $this->paidOrder();
        $doc = $this->postJson('/api/invoices', ['orderId' => $returned['id'], 'type' => '03'], $admin)->json('data');
        $this->postJson('/api/invoices/'.$doc['id'].'/submit', [], $admin)->assertOk();
        $note = $this->postJson('/api/invoices', ['orderId' => $returned['id'], 'type' => '07', 'referencedSerialNumber' => $doc['serialNumber'], 'noteCode' => '07', 'noteReason' => 'Devolución', 'items' => [['productId' => $returned['details'][0]['productId'], 'quantity' => 1]]], $admin)->json('data');
        $this->postJson('/api/invoices/'.$note['id'].'/submit', [], $admin)->assertOk();
        // Un pedido pendiente y uno cancelado no son ventas.
        $this->order($customer, $this->product(5, 'SKU-3'));
        $cancelled = $this->order($customer, $this->product(5, 'SKU-4'));
        app(OrderService::class)->transition($cancelled['id'], 'CANCELLED');
        $this->travelBack();

        $period = '?from=2026-10-13&to=2026-10-13';
        $this->getJson('/api/admin/reports/summary'.$period, $customer)->assertForbidden();
        $summary = $this->getJson('/api/admin/reports/summary'.$period, $admin)->assertOk()->json('data');
        $this->assertSame(['orders' => 4, 'total' => '81.20', 'taxableAmount' => '68.80', 'taxAmount' => '12.40', 'deliveryTotal' => '0.00', 'averageTicket' => '20.30'], array_diff_key($summary['sales'], ['byDay' => 1, 'byChannel' => 1, 'byPaymentMethod' => 1]));
        $this->assertSame([['date' => '2026-10-13', 'orders' => 4, 'total' => '81.20']], $summary['sales']['byDay']);
        $this->assertSame(['orders' => 4, 'total' => '81.20'], $summary['sales']['byChannel']['WEB']);
        $this->assertSame([['method' => 'MANUAL', 'payments' => 3, 'total' => '60.90']], $summary['sales']['byPaymentMethod']);
        $this->assertSame(['orders' => 1, 'total' => '20.30'], $summary['releasedOrders']['CANCELLED']);
        $this->assertSame([1, '20.30'], [$summary['pendingPayments']['orders'], $summary['pendingPayments']['total']]);
        $this->assertSame(2, $summary['reservations']['units']);
        $this->assertSame(['paidOrdersTotal' => '81.20', 'approvedPaymentsTotal' => '60.90', 'netInvoicedTotal' => '30.45', 'ordersWithoutInvoice' => 2, 'mismatchedOrders' => 3], $summary['reconciliation']);
        $boletas = collect($summary['invoices'])->firstWhere('type', '03');
        $this->assertSame([2, '40.60'], [$boletas['byStatus']['ACCEPTED'], $boletas['acceptedTotal']]);
        $this->assertSame(0, $this->getJson('/api/admin/reports/summary?from=2026-10-14&to=2026-10-14', $admin)->json('data.sales.orders'));

        $rows = collect($this->getJson('/api/admin/reports/sales'.$period, $admin)->assertOk()->assertJsonPath('data.total', 4)->json('data.items'))->keyBy('orderId');
        $this->assertSame([], $rows[$ok['id']]['issues']);
        $this->assertSame(['NOT_INVOICED'], $rows[$withoutInvoice['id']]['issues']);
        $this->assertSame(['PAYMENT_MISMATCH', 'NOT_INVOICED'], $rows[$internal['id']]['issues']);
        $this->assertSame(['INVOICED_MISMATCH'], $rows[$returned['id']]['issues']);
        $this->assertSame('10.15', $rows[$returned['id']]['netInvoiced']);
        $this->assertStringStartsWith('2026-10-13T22:30', $rows[$ok['id']]['paidAt']);
        $this->getJson('/api/admin/reports/sales'.$period.'&mismatched=1&perPage=2', $admin)->assertOk()->assertJsonPath('data.total', 3)->assertJsonCount(2, 'data.items');

        $csv = $this->get('/api/admin/reports/sales'.$period.'&format=csv', $admin)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = explode("\n", trim($csv->getContent()));
        $this->assertCount(5, $lines);
        $this->assertStringStartsWith("\u{FEFF}\"Fecha de pago\";Pedido;Canal;Estado;", $lines[0]);
        $this->assertStringContainsString('B001-1 (ACCEPTED)', $csv->getContent());
        $this->getJson('/api/admin/reports/summary?from=2025-01-01&to=2026-10-13', $admin)->assertUnprocessable();
        $this->getJson('/api/admin/reports/summary?from=2026-10-13&to=2026-10-01', $admin)->assertUnprocessable();
        $this->getJson('/api/admin/reports/summary', $admin)->assertOk()->assertJsonPath('data.period.timezone', 'America/Lima');
    }

    public function test_direct_sale_charges_delivers_and_consumes_stock_in_one_step(): void
    {
        Mail::fake();
        $admin = $this->token('ADMIN');
        $product = $this->product(5);
        $sale = ['items' => [['productId' => $product['id'], 'quantity' => 2]], 'billing' => self::BILLING, 'payment' => ['method' => 'CASH'], 'idempotencyKey' => (string) Str::uuid()];
        $this->postJson('/api/admin/sales', $sale, $this->token())->assertForbidden();
        $this->postJson('/api/admin/sales', ['payment' => ['method' => 'YAPE']] + $sale, $admin)->assertUnprocessable();
        $this->postJson('/api/admin/sales', ['payment' => ['method' => 'BITCOIN']] + $sale, $admin)->assertUnprocessable();
        $this->postJson('/api/admin/sales', ['items' => [['productId' => $product['id'], 'quantity' => 6]]] + $sale, $admin)->assertConflict();
        // Sin caja abierta no se vende, y el intento no deja reservas.
        $this->postJson('/api/admin/sales', $sale, $admin)->assertConflict()->assertJsonPath('message', 'Abre tu caja antes de registrar ventas');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 5, 'reserved' => 0]);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '100.00'], $admin)->assertOk();

        $done = $this->postJson('/api/admin/sales', $sale, $admin)->assertOk()->assertJsonPath('message', 'Venta registrada')
            ->assertJsonPath('data.status', 'DELIVERED')->assertJsonPath('data.channel', 'STORE')->assertJsonPath('data.total', '20.30')
            ->assertJsonPath('data.payment.provider', 'CASH')->assertJsonPath('data.payment.status', 'APPROVED')->json('data');
        // Reintento con la misma clave: no cobra ni descuenta otra vez.
        $this->postJson('/api/admin/sales', $sale, $admin)->assertOk()->assertJsonPath('message', 'Venta ya registrada')->assertJsonPath('data.id', $done['id']);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 3, 'reserved' => 0]);
        $this->assertDatabaseCount('payments', 1);
        // La cuenta de mostrador no recibe correos ni puede iniciar sesión con permisos.
        $walkIn = DB::table('users')->where('email', 'mostrador@syscon.local')->first();
        $this->assertSame([$walkIn->id, 'WALK_IN'], [$done['customerId'], $walkIn->role]);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('notifications', 0);
        $events = DB::table('order_events')->where('order_id', $done['id'])->orderBy('id')->pluck('type')->all();
        $this->assertSame(['STORE_SALE', 'PAYMENT_CONFIRMED', 'STATUS_CHANGED'], $events);

        // Venta a un cliente registrado, con Yape: lleva referencia y le llegan los correos.
        $this->postJson('/api/auth/register', ['email' => 'luz@example.com', 'password' => 'secure-password-123', 'firstName' => 'Luz', 'lastName' => 'Paz', 'documentType' => 'DNI', 'documentNumber' => '87654321'])->assertCreated();
        $this->getJson('/api/admin/customers?q=a', $admin)->assertUnprocessable();
        $found = $this->getJson('/api/admin/customers?q=87654321', $admin)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['luz@example.com', 'Luz Paz'], [$found['email'], $found['name']]);
        $this->assertSame([], $this->getJson('/api/admin/customers?q=mostrador', $admin)->json('data'));
        $this->postJson('/api/admin/sales', ['customerId' => (string) Str::uuid(), 'idempotencyKey' => (string) Str::uuid()] + $sale, $admin)->assertUnprocessable();
        $second = $this->postJson('/api/admin/sales', ['customerId' => $found['id'], 'payment' => ['method' => 'YAPE', 'reference' => 'YP-778899'], 'idempotencyKey' => (string) Str::uuid()] + $sale, $admin)
            ->assertOk()->assertJsonPath('data.payment.externalReference', 'YP-778899')->json('data');
        Mail::assertSent(OrderMail::class, fn ($m) => $m->kind === 'PAYMENT_CONFIRMED' && $m->hasTo('luz@example.com'));

        // El comprobante se emite igual que en la web, y el reporte separa el canal y el medio de pago.
        $this->postJson('/api/invoices', ['orderId' => $second['id'], 'type' => '03'], $admin)->assertOk()->assertJsonPath('data.total', '20.30');
        $summary = $this->getJson('/api/admin/reports/summary', $admin)->assertOk()->json('data.sales');
        $this->assertSame(['orders' => 2, 'total' => '40.60'], $summary['byChannel']['STORE']);
        $this->assertEqualsCanonicalizing([['method' => 'CASH', 'payments' => 1, 'total' => '20.30'], ['method' => 'YAPE', 'payments' => 1, 'total' => '20.30']], $summary['byPaymentMethod']);
    }

    public function test_lost_sunat_responses_are_reconciled_without_duplicating_documents(): void
    {
        Mail::fake();
        [$order, $admin] = $this->paidOrder();
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->json('data');
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->andReturn(true);
        $gateway->shouldReceive('canConsult')->andReturn(false);
        // Se firma una sola vez: todos los reenvíos usan el mismo XML.
        $gateway->shouldReceive('sign')->once()->andReturn('<signed-once/>');
        $gateway->shouldReceive('sendBill')->once()->with('-03-B001-1', '<signed-once/>')->andThrow(new ConnectionException('Operation timed out'));
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk()->assertJsonPath('data.status', 'PENDING_CHECK');
        $this->postJson('/api/invoices/'.$boleta['id'].'/reconcile', [], $this->token())->assertForbidden();

        // SUNAT sí lo había recibido: el reenvío del mismo XML responde 1033 y queda aceptado.
        $gateway->shouldReceive('sendBill')->once()->with('-03-B001-1', '<signed-once/>')->andThrow(new SunatFault('1033', 'El comprobante fue registrado previamente con otros datos'));
        $data = $this->postJson('/api/invoices/'.$boleta['id'].'/reconcile', [], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED')->assertJsonPath('data.hasCdr', false)->json('data');
        $this->assertSame([['SUBMIT', 'PENDING_CHECK', null], ['RECONCILE', 'ACCEPTED', '1033']], array_map(fn ($a) => [$a['action'], $a['result'], $a['sunatCode']], $data['attempts']));
        $this->assertStringContainsString('Registrado previamente', $data['sunatDescription']);
        $this->get('/api/invoices/'.$boleta['id'].'/cdr', $admin)->assertNotFound();
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'type' => 'INVOICE_ACCEPTED', 'reference' => 'B001-1']);
        Mail::assertSent(OrderMail::class, fn ($m) => $m->kind === 'INVOICE_AVAILABLE');
        $this->postJson('/api/invoices/'.$boleta['id'].'/reconcile', [], $admin)->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_reconciliation_uses_the_cdr_query_service_when_available(): void
    {
        [$order, $admin] = $this->paidOrder();
        $first = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->json('data');
        DB::table('invoices')->where('id', $first['id'])->update(['status' => 'PENDING_CHECK', 'updated_at' => now()->subMinutes(10)]);
        DB::table('xml_documents')->where('invoice_id', $first['id'])->update(['signed_xml' => '<signed/>']);
        $gateway = $this->mock(SunatGateway::class);
        $gateway->shouldReceive('configured')->andReturn(true);
        $gateway->shouldReceive('canConsult')->andReturn(true);
        $gateway->shouldNotReceive('sendBill');
        // SUNAT no lo registra: queda listo para enviarse de nuevo, sin reenviar a ciegas.
        $gateway->shouldReceive('statusCdr')->once()->with('03', 'B001-1')->andReturn(['code' => '0127', 'message' => 'El ticket no existe', 'cdr' => null]);
        $this->postJson('/api/invoices/'.$first['id'].'/reconcile', [], $admin)->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.attempts.0.sunatCode', '0127');
        // Con CDR disponible, el scheduler lo concilia y guarda la constancia.
        DB::table('invoices')->where('id', $first['id'])->update(['status' => 'PENDING_CHECK', 'updated_at' => now()->subMinutes(10)]);
        $gateway->shouldReceive('statusCdr')->once()->andReturn(['code' => '0004', 'message' => 'La constancia existe', 'cdr' => 'CDR-ZIP']);
        $gateway->shouldReceive('parseCdr')->once()->with('CDR-ZIP')->andReturn(['0', 'La Boleta numero B001-1, ha sido aceptada']);
        $this->assertSame(1, app(InvoiceSubmission::class)->reconcilePending());
        $this->assertDatabaseHas('invoices', ['id' => $first['id'], 'status' => 'ACCEPTED']);
        $this->assertDatabaseHas('invoice_attempts', ['invoice_id' => $first['id'], 'action' => 'RECONCILE', 'result' => 'ACCEPTED', 'actor_id' => null]);
        $this->assertSame(0, app(InvoiceSubmission::class)->reconcilePending());
    }

    public function test_cash_register_tracks_sales_movements_and_closing_count(): void
    {
        $admin = $this->token('ADMIN');
        $this->getJson('/api/admin/cash/current', $admin)->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/admin/cash/movements', ['type' => 'IN', 'amount' => '10', 'reason' => 'Sencillo'], $admin)->assertConflict();
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '-1'], $admin)->assertUnprocessable();
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '150.00'], $admin)->assertOk()->assertJsonPath('data.status', 'OPEN');
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '10.00'], $admin)->assertConflict();

        $product = $this->product(10);
        $sell = fn (string $method, ?string $reference = null) => $this->postJson('/api/admin/sales', ['items' => [['productId' => $product['id'], 'quantity' => 1]], 'billing' => self::BILLING,
            'payment' => ['method' => $method, 'reference' => $reference], 'idempotencyKey' => (string) Str::uuid()], $admin)->assertOk();
        $sell('CASH');
        $sell('CASH');
        $sell('CARD', 'VISA-4455');
        $this->postJson('/api/admin/cash/movements', ['type' => 'IN', 'amount' => '20.00', 'reason' => 'Sencillo del banco'], $admin)->assertOk();
        $this->postJson('/api/admin/cash/movements', ['type' => 'OUT', 'amount' => '500.00', 'reason' => 'Pago a proveedor'], $admin)->assertUnprocessable();
        $current = $this->postJson('/api/admin/cash/movements', ['type' => 'OUT', 'amount' => '15.50', 'reason' => 'Compra de bolsas'], $admin)->assertOk()->json('data');
        // 150 + 2 × 10.15 en efectivo + 20 − 15.50; la tarjeta no entra en el efectivo.
        $this->assertSame('174.80', $current['expectedCash']);
        $this->assertEqualsCanonicalizing([['method' => 'CASH', 'payments' => 2, 'total' => '20.30'], ['method' => 'CARD', 'payments' => 1, 'total' => '10.15']], $current['byMethod']);
        $this->assertCount(3, $current['sales']);
        $this->assertCount(2, $current['movements']);

        // Otro usuario tiene su propia caja y no ve la del primero.
        $other = $this->token('ADMIN');
        $this->getJson('/api/admin/cash/current', $other)->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/admin/cash/current', $this->token())->assertForbidden();

        $closed = $this->postJson('/api/admin/cash/close', ['countedCash' => '174.00', 'notes' => 'Faltan 80 céntimos'], $admin)->assertOk()->json('data');
        $this->assertSame(['CLOSED', '174.80', '174.00', '-0.80'], [$closed['status'], $closed['expectedCash'], $closed['countedCash'], $closed['difference']]);
        // En MySQL sin DEFAULT explícito la apertura se sobrescribía al cerrar (ON UPDATE CURRENT_TIMESTAMP).
        $this->assertSame($current['openedAt'], $closed['openedAt']);
        $this->postJson('/api/admin/cash/close', ['countedCash' => '1'], $admin)->assertConflict();
        $this->postJson('/api/admin/sales', ['items' => [['productId' => $product['id'], 'quantity' => 1]], 'billing' => self::BILLING, 'payment' => ['method' => 'CASH'], 'idempotencyKey' => (string) Str::uuid()], $admin)->assertConflict();
        $this->getJson('/api/admin/cash/sessions', $admin)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.difference', '-0.80');
        // Una caja nueva empieza de cero.
        $this->postJson('/api/admin/cash/open', ['openingAmount' => '50'], $admin)->assertOk()->assertJsonPath('data.expectedCash', '50.00');
    }

    public function test_accepted_documents_are_voided_with_daily_summary_or_voided_notice(): void
    {
        config(['commerce.sunat.ruc' => '20000000001']);
        [$order, $admin] = $this->paidOrder();
        $this->acceptSubmissions();
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->json('data');
        $void = fn (string $id) => $this->postJson('/api/invoices/'.$id.'/void', ['reason' => 'Error en la emisión'], $admin);
        $void($boleta['id'])->assertConflict();
        $this->postJson('/api/invoices/'.$boleta['id'].'/submit', [], $admin)->assertOk();
        $this->postJson('/api/invoices/'.$boleta['id'].'/void', ['reason' => 'x'], $admin)->assertUnprocessable();
        $this->postJson('/api/invoices/'.$boleta['id'].'/void', ['reason' => 'Error en la emisión'], $this->token())->assertForbidden();

        $gateway = app(SunatGateway::class);
        $gateway->shouldReceive('sendSummary')->once()->withArgs(function ($name, $xml) {
            $doc = new \DOMDocument;
            $doc->loadXML($xml);

            return str_starts_with($name, '20000000001-RC-') && $doc->documentElement->localName === 'SummaryDocuments'
                && $doc->getElementsByTagName('ConditionCode')->item(0)->textContent === '3'
                && $doc->getElementsByTagName('SummaryDocumentsLine')->item(0)->getElementsByTagName('ID')->item(0)->textContent === 'B001-1'
                && $doc->getElementsByTagName('TotalAmount')->item(0)->textContent === '20.30';
        })->andReturn('TICKET-1');
        // Primero SUNAT aún procesa; la segunda consulta trae el CDR.
        $gateway->shouldReceive('ticketStatus')->with('TICKET-1')->twice()->andReturn(['code' => '98', 'cdr' => null], ['code' => '0', 'cdr' => 'CDR-RC']);
        $gateway->shouldReceive('parseCdr')->with('CDR-RC')->andReturn(['0', 'El Resumen diario ha sido aceptado']);
        $data = $void($boleta['id'])->assertOk()->assertJsonPath('data.status', 'VOIDING')->assertJsonPath('data.voids.0.status', 'SENT')->json('data');
        $this->assertMatchesRegularExpression('/^RC-\d{8}-1$/', $data['voids'][0]['identifier']);
        // Mientras se anula no se puede emitir otro comprobante ni anular otra vez.
        $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertConflict();
        $void($boleta['id'])->assertConflict();
        $this->postJson('/api/invoices/'.$boleta['id'].'/void/check', [], $admin)->assertOk()->assertJsonPath('data.status', 'VOIDED')->assertJsonPath('data.voids.0.status', 'ACCEPTED');
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'type' => 'INVOICE_VOIDED']);
        // Anulada, el pedido se vuelve a facturar (aquí con factura y RUC).
        $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->assertOk()->assertJsonPath('data.serialNumber', 'F001-1');
        $this->getJson('/api/orders/'.$order['id'].'/invoices', $admin)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_voiding_rules_for_notes_deadline_and_rejection(): void
    {
        [$order, $admin] = $this->paidOrder();
        $this->acceptSubmissions();
        $factura = $this->postJson('/api/invoices', $this->invoiceInput($order['id']), $admin)->json('data');
        $this->postJson('/api/invoices/'.$factura['id'].'/submit', [], $admin)->assertOk();
        $note = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '08', 'referencedSerialNumber' => 'F001-1', 'noteCode' => '01', 'noteReason' => 'Intereses', 'amount' => '5.90'], $admin)->json('data');
        // Con una nota vigente, la factura no se anula; la nota sí (comunicación de baja RA).
        $this->postJson('/api/invoices/'.$factura['id'].'/void', ['reason' => 'Error'], $admin)->assertConflict();
        $this->postJson('/api/invoices/'.$note['id'].'/submit', [], $admin)->assertOk();
        $gateway = app(SunatGateway::class);
        $gateway->shouldReceive('sendSummary')->once()->withArgs(fn ($name, $xml) => str_contains($name, '-RA-') && str_contains($xml, 'VoidedDocuments') && str_contains($xml, '<sac:DocumentSerialID>FD01</sac:DocumentSerialID>'))->andReturn('T-2');
        $gateway->shouldReceive('ticketStatus')->once()->andReturn(['code' => '99', 'cdr' => 'CDR-99']);
        $gateway->shouldReceive('parseCdr')->with('CDR-99')->andReturn(['2324', 'El comprobante no puede ser dado de baja']);
        // SUNAT la rechaza: la nota sigue aceptada y el motivo queda registrado.
        $this->postJson('/api/invoices/'.$note['id'].'/void', ['reason' => 'Error en importe'], $admin)->assertOk()->assertJsonPath('data.status', 'ACCEPTED')
            ->assertJsonPath('data.voids.0.status', 'REJECTED')->assertJsonPath('data.voids.0.responseCode', '2324');
        // Fuera de plazo se exige nota de crédito.
        DB::table('invoices')->where('id', $note['id'])->update(['created_at' => now()->subDays(9)]);
        $this->postJson('/api/invoices/'.$note['id'].'/void', ['reason' => 'Error'], $admin)->assertUnprocessable()->assertJsonPath('message', 'Plazo de anulación vencido (7 días); emite una nota de crédito');
        $this->postJson('/api/invoices/'.$factura['id'].'/void/check', [], $admin)->assertConflict();
    }

    public function test_ruc_lookup_validates_format_before_querying_the_provider(): void
    {
        $customer = $this->token();
        $this->getJson('/api/lookup/ruc/20000000001')->assertUnauthorized();
        // Sin proveedor configurado se informa y el cliente escribe la razón social.
        $this->getJson('/api/lookup/ruc/20000000001', $customer)->assertStatus(503);
        config(['commerce.ruc_lookup.driver' => 'fake']);
        $this->getJson('/api/lookup/ruc/20000000002', $customer)->assertUnprocessable();
        $this->getJson('/api/lookup/ruc/123', $customer)->assertNotFound();
        $this->getJson('/api/lookup/ruc/20000000001', $customer)->assertOk()
            ->assertJsonPath('data.name', 'SYSCON PRUEBA S.A.C.')->assertJsonPath('data.active', true)->assertJsonPath('data.source', 'fake');
        $this->getJson('/api/lookup/ruc/20100000009', $customer)->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.condition', 'NO HABIDO');
        $this->getJson('/api/lookup/ruc/20600000005', $customer)->assertNotFound()->assertJsonPath('message', 'SUNAT no registra ese RUC.');
        // Un proveedor que falla responde 503 sin romper la compra.
        config(['commerce.ruc_lookup.driver' => 'caido']);
        $this->getJson('/api/lookup/ruc/20123456786', $customer)->assertStatus(503);
    }

    public function test_orders_keep_what_was_verified_about_the_ruc(): void
    {
        $customer = $this->token();
        $factura = ['type' => '01', 'documentType' => '6', 'documentNumber' => self::RUC, 'name' => 'Mi empresa'];
        $order = $this->order($customer, $this->product(), 1, $factura);
        $this->assertNull($order['billing']['verification']);
        config(['commerce.ruc_lookup.driver' => 'fake']);
        $order = $this->order($customer, $this->product(5, 'SKU-2'), 1, $factura);
        // MySQL reordena las claves de una columna JSON: se compara sin importar el orden.
        $verification = array_diff_key($order['billing']['verification'], ['checkedAt' => 1]);
        ksort($verification);
        $this->assertSame(['condition' => 'HABIDO', 'found' => true, 'name' => 'COMERCIAL ANDINA S.A.C.', 'source' => 'fake', 'status' => 'ACTIVO'], $verification);
        // El nombre que escribió el cliente se conserva; la verificación queda al lado para revisarla.
        $this->assertSame('Mi empresa', $order['billing']['name']);
        // Con el proveedor caído la compra sigue, sin verificación.
        config(['commerce.ruc_lookup.driver' => 'caido']);
        $order = $this->order($customer, $this->product(5, 'SKU-3'), 1, ['documentNumber' => '20100000009'] + $factura);
        $this->assertNull($order['billing']['verification']);
        // Una boleta con DNI no consulta nada.
        config(['commerce.ruc_lookup.driver' => 'fake']);
        $this->assertNull($this->order($customer, $this->product(5, 'SKU-4'))['billing']['verification']);
    }

    private function sandboxEvent(array $payment, string $status, array $override = []): array
    {
        $body = json_encode($override + ['paymentId' => $payment['id'], 'orderId' => $payment['orderId'], 'amount' => $payment['amount'], 'currency' => 'PEN', 'status' => $status, 'transactionId' => 'SBX-'.Str::random(6)]);

        return [$body, ['X-Signature' => hash_hmac('sha256', $body, 'sandbox-secret'), 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
    }

    private function postSandbox(array $event)
    {
        return $this->call('POST', '/api/payments/webhooks/sandbox', [], [], [], $this->transformHeadersToServerVars($event[1]), $event[0]);
    }

    public function test_sandbox_gateway_confirms_payments_through_signed_webhooks(): void
    {
        $customer = $this->token();
        $product = $this->product();
        $order = $this->order($customer, $product);
        $this->getJson('/api/payments/methods')->assertOk()->assertJsonPath('data.gateways', []);
        $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'SANDBOX'], $customer)->assertUnprocessable();
        config(['commerce.payments.sandbox' => ['enabled' => true, 'secret' => 'sandbox-secret'], 'commerce.web_url' => 'https://tienda.test']);
        $this->getJson('/api/payments/methods')->assertOk()->assertJsonPath('data.gateways', ['SANDBOX']);

        $payment = $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'SANDBOX'], $customer)->assertOk()
            ->assertJsonPath('data.provider', 'SANDBOX')->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->assertStringContainsString('/sandbox/checkout/'.$payment['id'].'?sig=', $payment['paymentUrl']);
        // Reintentar abre el mismo intento pendiente.
        $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'SANDBOX'], $customer)->assertJsonPath('data.id', $payment['id']);
        $path = parse_url($payment['paymentUrl'], PHP_URL_PATH).'?'.parse_url($payment['paymentUrl'], PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee('PASARELA DE PRUEBA')->assertSee('S/ 20.30');
        $this->get('/sandbox/checkout/'.$payment['id'].'?sig=falsa')->assertForbidden();

        // Firma, importe y pedido se validan antes de tocar nada.
        [$body] = $this->sandboxEvent($payment, 'approved');
        $this->call('POST', '/api/payments/webhooks/sandbox', [], [], [], $this->transformHeadersToServerVars(['X-Signature' => 'mala', 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)->assertUnauthorized();
        $this->postSandbox($this->sandboxEvent($payment, 'approved', ['amount' => '1.00']))->assertUnprocessable();
        $this->postSandbox($this->sandboxEvent($payment, 'approved', ['orderId' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PAYMENT_PENDING']);

        // Rechazado: el pedido sigue pendiente y un nuevo intento crea otro pago.
        $this->postSandbox($this->sandboxEvent($payment, 'rejected'))->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PAYMENT_PENDING']);
        $retry = $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'SANDBOX'], $customer)->assertOk()->json('data');
        $this->assertNotSame($payment['id'], $retry['id']);

        // La decisión simulada pasa por el mismo webhook firmado y vuelve a la tienda.
        $retryPath = parse_url($retry['paymentUrl'], PHP_URL_PATH);
        $sig = parse_url($retry['paymentUrl'], PHP_URL_QUERY);
        $this->get($retryPath.'/approve?'.$sig)->assertRedirect('https://tienda.test/pedido/'.$order['id'].'?pago=retorno');
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PAID']);
        $this->assertDatabaseHas('payments', ['id' => $retry['id'], 'status' => 'APPROVED']);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 3, 'reserved' => 0]);
        // Un webhook repetido no vuelve a cobrar ni descontar.
        $this->postSandbox($this->sandboxEvent($retry, 'approved'))->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 3, 'reserved' => 0]);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['id'])->where('type', 'PAYMENT_CONFIRMED')->count());
    }

    public function test_gateway_approval_after_cancellation_requires_a_refund(): void
    {
        config(['commerce.payments.sandbox' => ['enabled' => true, 'secret' => 'sandbox-secret']]);
        $customer = $this->token();
        $order = $this->order($customer, $this->product());
        $payment = $this->postJson('/api/payments', ['orderId' => $order['id'], 'provider' => 'SANDBOX'], $customer)->json('data');
        // El pedido vence mientras el cliente pagaba; la pasarela aprueba después.
        app(OrderService::class)->transition($order['id'], 'EXPIRED');
        DB::table('payments')->where('id', $payment['id'])->update(['status' => 'PENDING']);
        $this->postSandbox($this->sandboxEvent($payment, 'approved'))->assertOk()->assertJsonPath('data.status', 'REFUND_REQUIRED');
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'type' => 'PAYMENT_REFUND_REQUIRED']);
        $this->getJson('/api/admin/payments?status=REFUND_REQUIRED', $this->token('ADMIN'))->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_boleta_without_customer_document_only_below_700(): void
    {
        $customer = $this->token();
        $admin = $this->token('ADMIN');
        $anonymous = ['type' => '03', 'documentType' => '0', 'documentNumber' => '', 'name' => ''];
        $order = $this->order($customer, $this->product(), 1, $anonymous);
        $this->assertSame(['documentType' => '0', 'documentNumber' => '-', 'name' => 'CLIENTES VARIOS'], array_intersect_key($order['billing'], array_flip(['documentType', 'documentNumber', 'name'])));
        // Factura nunca; boleta solo por debajo de S/ 700.
        $this->postJson('/api/orders', ['items' => [['productId' => $this->product(5, 'SKU-2')['id'], 'quantity' => 1]], 'billing' => ['type' => '01'] + $anonymous, 'delivery' => self::PICKUP], $customer)->assertUnprocessable();
        $expensive = app(CatalogService::class)->upsert(['sku' => 'SKU-700', 'name' => 'Laptop', 'price' => '700.00', 'stock' => 3, 'active' => true, 'category' => 'Computación', 'brand' => 'SYSCON']);
        $this->postJson('/api/orders', ['items' => [['productId' => $expensive['id'], 'quantity' => 1]], 'billing' => $anonymous, 'delivery' => self::PICKUP], $customer)
            ->assertUnprocessable()->assertJsonPath('message', 'Desde S/ 700 la boleta requiere el documento del cliente.');

        app(OrderService::class)->transition($order['id'], 'PAID');
        $boleta = $this->postJson('/api/invoices', ['orderId' => $order['id'], 'type' => '03'], $admin)->assertOk()
            ->assertJsonPath('data.customerDocumentType', '0')->assertJsonPath('data.customerDocumentNumber', '-')->json('data');
        $xml = DB::table('xml_documents')->where('invoice_id', $boleta['id'])->value('generated_xml');
        $this->assertStringContainsString('<cbc:ID schemeID="0">-</cbc:ID>', $xml);
        // Corregir un borrador con un número distinto de "-" no es válido.
        $this->patchJson('/api/invoices/'.$boleta['id'], ['customerDocumentType' => '0', 'customerDocumentNumber' => '123', 'customerName' => 'X'], $admin)->assertUnprocessable();
    }
}
