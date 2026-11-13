<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Pricing\PriceReader;
use App\Modules\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null, 'commerce.pricing_check_dns' => false]);
    }

    private function headers(string $role = 'ADMIN'): array
    {
        $user = User::create(['email' => strtolower($role).'@pricing.test', 'password' => 'pricing-password-123', 'role' => $role]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'pricing-password-123'])->assertOk()->json('data.accessToken');

        return ['Authorization' => 'Bearer '.$token];
    }

    /** Product in a category, created through the catalog API. */
    private function product(array $h, string $sku, string $price, string $category = 'Cámaras IP'): string
    {
        return $this->postJson('/api/catalog/products', ['sku' => $sku, 'name' => "Producto $sku", 'price' => $price, 'stock' => 5, 'active' => true, 'category' => $category, 'brand' => 'Marca'], $h)
            ->assertOk()->json('data.id');
    }

    public function test_commercial_rounding_and_suggested_price(): void
    {
        $this->assertSame(123.9, PricingService::commercialRound(123.10));
        $this->assertSame(124.9, PricingService::commercialRound(123.95));
        $this->assertSame(123.9, PricingService::commercialRound(123.90));
        // S/ 200 de costo + 25 % = 250 → 250.90
        $this->assertSame(250.9, PricingService::suggestedPrice(200, 25));
    }

    public function test_reads_prices_from_json_ld_meta_tags_and_microdata(): void
    {
        $jsonLd = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"Product","name":"X","offers":{"@type":"Offer","price":"1299.90","priceCurrency":"PEN"}}]}</script>';
        $this->assertSame(1299.9, PriceReader::extract($jsonLd));
        $aggregate = '<script type="application/ld+json">[{"@type":["Product"],"offers":{"@type":"AggregateOffer","lowPrice":"189","priceCurrency":"PEN"}}]</script>';
        $this->assertSame(189.0, PriceReader::extract($aggregate));
        $usdOnly = '<script type="application/ld+json">{"@type":"Product","offers":{"price":"99","priceCurrency":"USD"}}</script>';
        $this->assertNull(PriceReader::extract($usdOnly));
        $this->assertSame(459.0, PriceReader::extract('<meta property="product:price:amount" content="459.00">'));
        $this->assertSame(49.9, PriceReader::extract('<span itemprop="price" content="49.90">S/ 49.90</span>'));
        $this->assertSame(1299.9, PriceReader::number('S/ 1,299.90'));
        $this->assertSame(1299.9, PriceReader::number('1.299,90'));
        $this->assertSame(49.9, PriceReader::number('49,90'));
        $this->assertSame(1299.0, PriceReader::number('1,299'));
        $this->assertNull(PriceReader::extract('<p>Sin precio</p>'));
    }

    public function test_full_flow_from_reference_links_to_an_approved_price(): void
    {
        $h = $this->headers();
        $camera = $this->product($h, 'CAM-1', '300.00');
        $source = $this->postJson('/api/admin/pricing/sources', ['name' => 'Mayorista Uno', 'website' => 'https://www.mayorista-uno.pe/tienda'], $h)
            ->assertCreated()->assertJsonPath('data.host', 'mayorista-uno.pe')->json('data.id');
        $other = $this->postJson('/api/admin/pricing/sources', ['name' => 'Mayorista Dos', 'website' => 'mayorista-dos.com'], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/admin/pricing/sources', ['name' => 'Duplicada', 'website' => 'MAYORISTA-UNO.pe'], $h)->assertUnprocessable();

        // Links must be HTTPS pages of that company's website.
        $this->postJson('/api/admin/pricing/references', ['productId' => $camera, 'sourceId' => $source, 'url' => 'https://otra-web.pe/camara'], $h)->assertUnprocessable();
        $this->postJson('/api/admin/pricing/references', ['productId' => $camera, 'sourceId' => $source, 'url' => 'http://www.mayorista-uno.pe/camara'], $h)->assertUnprocessable();

        Http::fake([
            'www.mayorista-uno.pe/*' => Http::response('<html><script type="application/ld+json">{"@type":"Product","offers":{"price":"220.00","priceCurrency":"PEN"}}</script></html>'),
            'mayorista-dos.com/*' => Http::response('<html><p>Página rediseñada sin precio</p></html>'),
        ]);
        $one = $this->postJson('/api/admin/pricing/references', ['productId' => $camera, 'sourceId' => $source, 'url' => 'https://www.mayorista-uno.pe/camara'], $h)->assertCreated()->json('data.id');
        $two = $this->postJson('/api/admin/pricing/references', ['productId' => $camera, 'sourceId' => $other, 'url' => 'https://mayorista-dos.com/p/camara'], $h)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/pricing/references/$one/refresh", [], $h)->assertOk()->assertJsonPath('data.price', '220.00')->assertJsonPath('data.status', 'OK');
        $this->postJson("/api/admin/pricing/references/$two/refresh", [], $h)->assertOk()->assertJsonPath('data.status', 'ERROR')->assertJsonPath('data.price', null);

        // Without a category margin there is no suggestion yet.
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertOk()->assertJsonPath('data.0.status', 'NO_MARGIN');
        $category = collect($this->getJson('/api/admin/pricing/margins', $h)->json('data'))->firstWhere('category', 'Cámaras IP')['categoryId'];
        $this->putJson("/api/admin/pricing/margins/$category", ['margin' => 25], $h)->assertOk()->assertJsonPath('data.margin', '25.00');

        // 220 + 25 % = 275 → 275.90, below today's 300: a price drop to approve.
        $row = $this->getJson('/api/admin/pricing/suggestions', $h)->assertOk()->json('data.0');
        $this->assertSame(['220.00', 'Mayorista Uno', '275.90', 'DOWN', '36.4'], [$row['referencePrice'], $row['referenceSource'], $row['suggestedPrice'], $row['status'], $row['currentMargin']]);

        // A cheaper price typed by hand on the second company wins.
        $this->postJson("/api/admin/pricing/references/$two/price", ['price' => '210.00'], $h)->assertOk()->assertJsonPath('data.status', 'MANUAL');
        $row = $this->getJson('/api/admin/pricing/suggestions', $h)->json('data.0');
        $this->assertSame(['210.00', 'Mayorista Dos', '262.90'], [$row['referencePrice'], $row['referenceSource'], $row['suggestedPrice']]);

        // Dismissed until the reference cost changes.
        $this->postJson("/api/admin/pricing/products/$camera/dismiss", [], $h)->assertOk();
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.status', 'REVIEWED');
        $this->postJson("/api/admin/pricing/references/$two/price", ['price' => '205.00'], $h)->assertOk();
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.status', 'DOWN');

        // Approving changes the selling price; the action is in the staff activity log.
        // 205 + 25 % = 256.25 → 256.90 suggested; staff adjusts it to 257.90 and it is not proposed again.
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.suggestedPrice', '256.90');
        $this->postJson("/api/admin/pricing/products/$camera/approve", ['price' => '257.90'], $h)->assertOk()->assertJsonPath('data.price', '257.90');
        $this->getJson("/api/catalog/products/$camera")->assertJsonPath('data.price', '257.90');
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.status', 'REVIEWED');
        // When the best cost moves again it is proposed again: at 230 the first company (220) is cheapest,
        // 220 + 25 % = 275 → 275.90. Approving the suggestion leaves it OK.
        $this->postJson("/api/admin/pricing/references/$two/price", ['price' => '230.00'], $h)->assertOk();
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.status', 'UP')
            ->assertJsonPath('data.0.referenceSource', 'Mayorista Uno')->assertJsonPath('data.0.suggestedPrice', '275.90');
        $this->postJson("/api/admin/pricing/products/$camera/approve", ['price' => '275.90'], $h)->assertOk();
        $this->getJson('/api/admin/pricing/suggestions', $h)->assertJsonPath('data.0.status', 'OK');
        $this->assertDatabaseHas('audit_logs', ['method' => 'POST', 'path' => "api/admin/pricing/products/$camera/approve"]);
        $this->assertSame(3, DB::table('price_reference_history')->where('reference_id', $two)->count());
    }

    public function test_scheduled_check_and_permissions(): void
    {
        $h = $this->headers();
        $product = $this->product($h, 'SW-1', '99.90', 'Switches');
        $source = $this->postJson('/api/admin/pricing/sources', ['name' => 'Ref', 'website' => 'ref.pe'], $h)->json('data.id');
        Http::fake(['ref.pe/*' => Http::response('<meta property="og:price:amount" content="70.00">')]);
        $this->postJson('/api/admin/pricing/references', ['productId' => $product, 'sourceId' => $source, 'url' => 'https://ref.pe/switch'], $h)->assertCreated();
        $this->artisan('prices:check')->expectsOutputToContain('Leídos: 1')->assertSuccessful();
        $this->assertSame('70.00', number_format((float) DB::table('price_references')->value('price'), 2, '.', ''));

        // Customers cannot see or change reference prices.
        $customer = $this->headers('CUSTOMER');
        $this->getJson('/api/admin/pricing/suggestions', $customer)->assertForbidden();
        $this->postJson("/api/admin/pricing/products/$product/approve", ['price' => '1.00'], $customer)->assertForbidden();
    }
}
