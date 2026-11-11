<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Complaints\ComplaintService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.jwt_secret' => str_repeat('j', 64), 'commerce.admin_email' => null, 'commerce.admin_password' => null, 'mail.default' => 'array']);
    }

    private function headers(string $role): array
    {
        $user = User::create(['email' => strtolower($role).'@complaint.test', 'password' => 'complaint-password-123', 'role' => $role]);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'complaint-password-123'])->assertOk()->json('data.accessToken');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function sheet(array $patch = []): array
    {
        return [...['type' => 'RECLAMO', 'consumerName' => 'Ana Quispe Mamani', 'documentType' => 'DNI', 'documentNumber' => '45678912',
            'address' => 'Jr. Lima 123, Juliaca', 'phone' => '+51 951 234 567', 'email' => 'ana@cliente.test', 'itemType' => 'PRODUCTO',
            'itemDescription' => 'Router Wi-Fi 6', 'amount' => '199.00', 'orderReference' => 'PED-1', 'detail' => 'El router llegó sin su fuente de poder.',
            'consumerRequest' => 'Cambio del equipo', 'accepted' => true], ...$patch];
    }

    public function test_anyone_registers_a_sheet_with_a_correlative_code_and_due_date(): void
    {
        $first = $this->postJson('/api/complaints', $this->sheet())->assertCreated()->json('data');
        $second = $this->postJson('/api/complaints', $this->sheet(['type' => 'QUEJA', 'amount' => null]))->assertCreated()->json('data');

        $year = now()->year;
        $this->assertSame("R-$year-000001", $first['code']);
        $this->assertSame("R-$year-000002", $second['code']);
        $this->assertSame('PENDING', $first['status']);
        $this->assertSame(ComplaintService::dueOn(CarbonImmutable::now())->toDateString(), $first['dueOn']);
        $this->assertNull($second['amount']);
    }

    public function test_the_sheet_validates_the_consumer_data_and_the_acceptance(): void
    {
        $this->postJson('/api/complaints', $this->sheet(['documentNumber' => '123']))->assertStatus(422);
        $this->postJson('/api/complaints', $this->sheet(['type' => 'OTRO']))->assertStatus(422);
        $this->postJson('/api/complaints', $this->sheet(['accepted' => false]))->assertStatus(422);
        $this->postJson('/api/complaints', $this->sheet(['email' => 'no-es-correo']))->assertStatus(422);
    }

    public function test_only_staff_reads_and_answers_the_book_once(): void
    {
        $id = $this->postJson('/api/complaints', $this->sheet())->json('data.id');
        $this->getJson('/api/admin/complaints')->assertUnauthorized();
        $this->getJson('/api/admin/complaints', $this->headers('CUSTOMER'))->assertForbidden();

        $admin = $this->headers('ADMIN');
        $this->getJson('/api/admin/complaints?status=PENDING', $admin)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/admin/complaints/$id/respond", ['response' => 'corto'], $admin)->assertStatus(422);
        $answered = $this->postJson("/api/admin/complaints/$id/respond", ['response' => 'Enviaremos la fuente de poder sin costo.'], $admin)->assertOk()->json('data');
        $this->assertSame('ANSWERED', $answered['status']);
        $this->postJson("/api/admin/complaints/$id/respond", ['response' => 'Otra respuesta distinta.'], $admin)->assertStatus(409);
        $this->getJson('/api/admin/complaints?status=PENDING', $admin)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_due_date_counts_fifteen_business_days(): void
    {
        // Lunes 9/11/2026: se cuenta desde el martes 10; el día hábil 15 es el lunes 30/11/2026.
        $this->assertSame('2026-11-30', ComplaintService::dueOn(CarbonImmutable::parse('2026-11-09 10:00'))->toDateString());
        // Sábado 7/11/2026: el fin de semana no cuenta; el día hábil 15 es el viernes 27/11/2026.
        $this->assertSame('2026-11-27', ComplaintService::dueOn(CarbonImmutable::parse('2026-11-07 10:00'))->toDateString());
    }
}
