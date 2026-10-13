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
        return ['orderId' => $order, 'type' => '01', 'serialNumber' => 'F001-1', 'customerDocumentType' => '6', 'customerDocumentNumber' => self::RUC, 'customerName' => 'Cliente & Cía'];
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
        $this->assertSame(['type' => '01', 'documentType' => '6', 'documentNumber' => self::RUC, 'name' => 'Comercial Andina SAC', 'address' => 'Av. Arequipa 123, Lima'], $o['billing']);
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
        $invoice = $this->postJson('/api/invoices', ['orderId' => $o['id'], 'type' => '03', 'serialNumber' => 'B001-5'], $admin)->assertOk()->json('data');
        $xml = DB::table('xml_documents')->where('invoice_id', $invoice['id'])->value('generated_xml');
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $lines = array_map(fn ($n) => $n->textContent, iterator_to_array($doc->getElementsByTagName('LineExtensionAmount')));
        $this->assertSame(['10.15', '12.50'], $lines);
        $this->assertSame('22.65', $doc->getElementsByTagName('PayableAmount')->item(0)->textContent);
        $this->assertStringContainsString('Servicio de envío a domicilio', $xml);
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
        $base = ['orderId' => $order['id'], 'type' => '01', 'serialNumber' => 'F001-9'];
        // El pedido se compró con boleta/DNI: una factura necesita RUC explícito.
        $this->postJson('/api/invoices', $base, $admin)->assertUnprocessable();
        $this->postJson('/api/invoices', $base + ['customerDocumentType' => '6', 'customerDocumentNumber' => '20123456789'], $admin)->assertUnprocessable();
        $boleta = $this->postJson('/api/invoices', ['type' => '03', 'serialNumber' => 'B001-9'] + $base, $admin)->assertOk()->json('data');
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
