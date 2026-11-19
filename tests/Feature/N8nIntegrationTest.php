<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\CatalogService;
use App\Modules\Integrations\N8n;
use App\Modules\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class N8nIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://n8n.example.com/webhook/syscon';

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null, 'mail.default' => 'array',
            'commerce.n8n.url' => self::URL, 'commerce.n8n.token' => 'token-de-prueba', 'commerce.web_url' => 'https://tienda.example.com']);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function order(): array
    {
        $user = User::create(['email' => Str::uuid().'@example.com', 'password' => 'customer-password-123', 'role' => 'CUSTOMER']);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'customer-password-123'])->assertOk()->json('data.accessToken');
        $product = app(CatalogService::class)->upsert(['sku' => 'N8N-1', 'name' => 'Router', 'price' => '99.90', 'stock' => 5, 'active' => true, 'category' => 'Redes', 'brand' => 'SYSCON']);

        return $this->postJson('/api/orders', ['items' => [['productId' => $product['id'], 'quantity' => 2]],
            'billing' => ['type' => '03', 'documentType' => '1', 'documentNumber' => '12345678', 'name' => 'Ana Quispe'],
            'delivery' => ['method' => 'PICKUP', 'contactName' => 'Ana Quispe', 'contactPhone' => '987654321']], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');
    }

    public function test_order_created_and_paid_are_posted_to_n8n_with_the_token(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $o = $this->order();
        app(OrderService::class)->transition($o['id'], 'PAID');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->hasHeader('X-Syscon-Token', 'token-de-prueba')
            && $r['event'] === 'order.created' && $r['data']['orderId'] === $o['id'] && $r['data']['total'] === '199.80'
            && $r['data']['items'][0]['sku'] === 'N8N-1' && $r['data']['url'] === 'https://tienda.example.com/pedido/'.$o['id']);
        Http::assertSent(fn (Request $r) => $r['event'] === 'order.paid' && $r['data']['status'] === 'PAID' && $r->hasHeader('X-Syscon-Event', 'order.paid'));
        $this->assertSame(2, DB::table('integration_events')->where('status', 'SENT')->count());
    }

    public function test_a_down_n8n_never_breaks_the_sale_and_is_retried(): void
    {
        Http::fake([self::URL => Http::sequence()->push('caído', 503)->push(['ok' => true])]);

        $o = $this->order();
        $this->assertSame('PAYMENT_PENDING', $o['status']);
        $event = DB::table('integration_events')->first();
        $this->assertSame('FAILED', $event->status);
        $this->assertStringContainsString('503', $event->last_error);

        // Aún no vence el reintento; luego el scheduler lo envía.
        $this->assertSame(0, app(N8n::class)->sendDue());
        $this->travel(2)->minutes();
        $this->assertSame(1, app(N8n::class)->sendDue());
        $this->assertDatabaseHas('integration_events', ['id' => $event->id, 'status' => 'SENT']);
    }

    public function test_without_url_nothing_is_recorded_or_sent(): void
    {
        config(['commerce.n8n.url' => '']);
        Http::fake();

        $this->order();

        Http::assertNothingSent();
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_complaint_is_posted_and_the_test_command_reports_success(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $this->postJson('/api/complaints', ['type' => 'RECLAMO', 'consumerName' => 'Ana Quispe Mamani', 'documentType' => 'DNI', 'documentNumber' => '45678912',
            'address' => 'Jr. Lima 123, Juliaca', 'phone' => '+51 951 234 567', 'email' => 'ana@cliente.test', 'itemType' => 'PRODUCTO',
            'itemDescription' => 'Router Wi-Fi 6', 'detail' => 'El router llegó sin su fuente de poder.', 'consumerRequest' => 'Cambio del equipo', 'accepted' => true])->assertCreated();
        Http::assertSent(fn (Request $r) => $r['event'] === 'complaint.created' && str_starts_with($r['data']['code'], 'R-'));

        $this->artisan('integrations:test')->expectsOutput('n8n recibió el evento de prueba.')->assertExitCode(0);
    }
}
