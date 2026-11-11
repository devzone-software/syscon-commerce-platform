<?php

namespace App\Modules\Complaints;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Libro de Reclamaciones virtual. Cada hoja recibe un número correlativo por año
 * (R-2026-000001), el plazo de respuesta y una copia por correo para el consumidor.
 * Los requisitos legales (campos, plazos y conservación) deben validarse con un abogado.
 */
class ComplaintService
{
    /** Plazo de respuesta en días hábiles (lunes a viernes; no descuenta feriados). */
    public const RESPONSE_BUSINESS_DAYS = 15;

    public const TYPES = ['RECLAMO', 'QUEJA'];

    public const DOCUMENT_TYPES = ['DNI', 'CE', 'PASAPORTE', 'RUC'];

    public const ITEM_TYPES = ['PRODUCTO', 'SERVICIO'];

    public function create(array $input): array
    {
        $data = Validator::make($input, [
            'type' => 'required|in:'.implode(',', self::TYPES),
            'consumerName' => 'required|string|min:3|max:255',
            'documentType' => 'required|in:'.implode(',', self::DOCUMENT_TYPES),
            'documentNumber' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]{6,20}$/'],
            'address' => 'required|string|min:5|max:500',
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +()-]{6,30}$/'],
            'email' => 'required|email|max:255',
            'guardianName' => 'nullable|string|max:255',
            'itemType' => 'required|in:'.implode(',', self::ITEM_TYPES),
            'itemDescription' => 'required|string|min:3|max:500',
            'amount' => 'nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
            'orderReference' => 'nullable|string|max:100',
            'detail' => 'required|string|min:10|max:5000',
            'consumerRequest' => 'required|string|min:5|max:3000',
            'accepted' => 'accepted',
        ])->validate();
        if ($data['documentType'] === 'DNI') {
            abort_unless(preg_match('/^\d{8}$/', $data['documentNumber']), 422, 'El DNI debe tener 8 dígitos');
        }
        if ($data['documentType'] === 'RUC') {
            abort_unless(preg_match('/^\d{11}$/', $data['documentNumber']), 422, 'El RUC debe tener 11 dígitos');
        }

        $id = (string) Str::uuid();
        $now = CarbonImmutable::now();
        $values = ['id' => $id, 'type' => $data['type'], 'consumer_name' => trim($data['consumerName']), 'document_type' => $data['documentType'],
            'document_number' => strtoupper($data['documentNumber']), 'address' => trim($data['address']), 'phone' => trim($data['phone']),
            'email' => strtolower(trim($data['email'])), 'guardian_name' => $data['guardianName'] ?? null, 'item_type' => $data['itemType'],
            'item_description' => trim($data['itemDescription']), 'amount' => isset($data['amount']) ? bcadd((string) $data['amount'], '0', 2) : null,
            'order_reference' => $data['orderReference'] ?? null, 'detail' => trim($data['detail']), 'consumer_request' => trim($data['consumerRequest']),
            'status' => 'PENDING', 'due_on' => self::dueOn($now)->toDateString(), 'created_at' => $now, 'updated_at' => $now];

        // El correlativo es único por año; si dos hojas chocan, se vuelve a numerar.
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use (&$values, $now) {
                    $prefix = 'R-'.$now->year.'-';
                    $last = DB::table('complaints')->where('code', 'like', $prefix.'%')->max('code');
                    $values['code'] = $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
                    DB::table('complaints')->insert($values);
                });
                break;
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }

        $complaint = $this->get($id);
        $this->sendCopy($complaint);

        return $complaint;
    }

    public function get(string $id): array
    {
        $c = DB::table('complaints')->where('id', $id)->first();
        abort_unless($c, 404, 'Reclamo no encontrado');

        return self::view($c);
    }

    public function list(?string $status = null): array
    {
        return DB::table('complaints')->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(500)->get()->map(fn ($c) => self::view($c))->all();
    }

    public function respond(string $id, array $input, ?string $actor): array
    {
        $data = Validator::make($input, ['response' => 'required|string|min:10|max:5000'])->validate();
        $c = DB::table('complaints')->where('id', $id)->first();
        abort_unless($c, 404, 'Reclamo no encontrado');
        abort_if($c->status === 'ANSWERED', 409, 'El reclamo ya fue respondido');
        DB::table('complaints')->where('id', $id)->update(['status' => 'ANSWERED', 'response' => trim($data['response']),
            'responded_at' => now(), 'responded_by' => $actor, 'updated_at' => now()]);
        $complaint = $this->get($id);
        $this->sendResponse($complaint);

        return $complaint;
    }

    /** Fecha límite: suma días hábiles (lunes a viernes) desde el día siguiente al registro. */
    public static function dueOn(CarbonImmutable $from): CarbonImmutable
    {
        $day = $from->startOfDay();
        for ($left = self::RESPONSE_BUSINESS_DAYS; $left > 0;) {
            $day = $day->addDay();
            if (! $day->isWeekend()) {
                $left--;
            }
        }

        return $day;
    }

    public static function view(object $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'type' => $c->type, 'consumerName' => $c->consumer_name, 'documentType' => $c->document_type,
            'documentNumber' => $c->document_number, 'address' => $c->address, 'phone' => $c->phone, 'email' => $c->email,
            'guardianName' => $c->guardian_name, 'itemType' => $c->item_type, 'itemDescription' => $c->item_description,
            'amount' => $c->amount === null ? null : bcadd((string) $c->amount, '0', 2), 'orderReference' => $c->order_reference,
            'detail' => $c->detail, 'consumerRequest' => $c->consumer_request, 'status' => $c->status, 'response' => $c->response,
            'respondedAt' => $c->responded_at ? CarbonImmutable::parse($c->responded_at)->toIso8601String() : null,
            'dueOn' => CarbonImmutable::parse($c->due_on)->toDateString(), 'createdAt' => CarbonImmutable::parse($c->created_at)->toIso8601String()];
    }

    /** Copia de la hoja para el consumidor. Un fallo de correo nunca anula el registro. */
    private function sendCopy(array $c): void
    {
        $this->mail($c['email'], "Libro de Reclamaciones: hoja {$c['code']}", implode("\n", [
            "Hola {$c['consumerName']},",
            '',
            "Registramos tu {$this->typeLabel($c['type'])} con el número {$c['code']} el ".CarbonImmutable::parse($c['createdAt'])->format('d/m/Y H:i').'.',
            'Te responderemos a más tardar el '.CarbonImmutable::parse($c['dueOn'])->format('d/m/Y').'.',
            '',
            "Producto o servicio: {$c['itemDescription']}",
            "Detalle: {$c['detail']}",
            "Pedido: {$c['consumerRequest']}",
            '',
            'Grupo SYSCON Tecnología Informática',
        ]));
    }

    private function sendResponse(array $c): void
    {
        $this->mail($c['email'], "Respuesta a tu hoja de reclamación {$c['code']}", implode("\n", [
            "Hola {$c['consumerName']},",
            '',
            "Esta es nuestra respuesta a tu {$this->typeLabel($c['type'])} {$c['code']}:",
            '',
            $c['response'],
            '',
            'Grupo SYSCON Tecnología Informática',
        ]));
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo del Libro de Reclamaciones', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }

    private function typeLabel(string $type): string
    {
        return $type === 'QUEJA' ? 'queja' : 'reclamo';
    }
}
