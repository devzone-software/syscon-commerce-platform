<?php

namespace App\Modules\Lookup;

use App\Modules\Orders\BillingData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de razón social y estado de un RUC. El formato y el dígito verificador se validan
 * aquí, sin consultar; la identidad la responde el proveedor configurado en RUC_LOOKUP_DRIVER:
 * - migo: API de MIGO (api.migo.pe), con el token de RUC_LOOKUP_TOKEN.
 * - fake: datos ficticios deterministas para desarrollo y pruebas (no consulta a nadie).
 * - none: consulta desactivada; el cliente escribe la razón social.
 */
class RucLookup
{
    /** Respuestas de prueba: casos de activo, baja/no habido y no encontrado. */
    private const FAKE = [
        '20000000001' => ['name' => 'SYSCON PRUEBA S.A.C.', 'status' => 'ACTIVO', 'condition' => 'HABIDO', 'address' => 'AV. PRUEBA 123, LIMA'],
        '20123456786' => ['name' => 'COMERCIAL ANDINA S.A.C.', 'status' => 'ACTIVO', 'condition' => 'HABIDO', 'address' => 'AV. PRUEBA 456, LIMA'],
        '20100000009' => ['name' => 'EMPRESA DADA DE BAJA S.A.', 'status' => 'BAJA DE OFICIO', 'condition' => 'NO HABIDO', 'address' => null],
        '20600000005' => null,
    ];

    public function driver(): string
    {
        return (string) config('commerce.ruc_lookup.driver', 'none');
    }

    /** Un proveedor real sin token cuenta como desactivado: no se gasta una llamada que fallará. */
    public function enabled(): bool
    {
        return match ($this->driver()) {
            'none' => false,
            'migo' => (string) config('commerce.ruc_lookup.token') !== '',
            default => true,
        };
    }

    /**
     * @return array{found: bool, ruc: string, name?: string, status?: string, condition?: string, address?: ?string, active?: bool, source: string, checkedAt: string}
     *
     * @throws LookupUnavailable si el proveedor no responde; quien llama decide cómo continuar.
     */
    public function find(string $ruc): array
    {
        abort_unless(BillingData::validRuc($ruc), 422, 'El RUC no es válido.');
        abort_unless($this->enabled(), 503, 'La consulta de RUC no está disponible; ingresa la razón social.');

        return Cache::remember('ruc:'.$this->driver().':'.$ruc, now()->addDay(), function () use ($ruc) {
            $data = match ($this->driver()) {
                'fake' => $this->fake($ruc),
                'migo' => $this->migo($ruc),
                default => throw new LookupUnavailable('Proveedor de RUC desconocido: '.$this->driver()),
            };
            $base = ['ruc' => $ruc, 'source' => $this->driver(), 'checkedAt' => now()->toIso8601String()];

            return $data === null ? ['found' => false] + $base
                : ['found' => true, 'active' => $data['status'] === 'ACTIVO' && $data['condition'] === 'HABIDO'] + $data + $base;
        });
    }

    /** Verificación que guarda el pedido; null si no hay consulta o el proveedor falló (no bloquea la compra). */
    public function verify(string $ruc): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $result = $this->find($ruc);
        } catch (LookupUnavailable $e) {
            Log::warning('Consulta de RUC no disponible', ['ruc' => $ruc, 'error' => $e->getMessage()]);

            return null;
        }

        return array_intersect_key($result, array_flip(['found', 'name', 'status', 'condition', 'source', 'checkedAt']));
    }

    /**
     * GET {url}/{ruc} con token Bearer. 404 = SUNAT no registra el RUC; cualquier otra falla
     * (token rechazado, límite del plan, caída) es LookupUnavailable y no se guarda en caché.
     */
    private function migo(string $ruc): ?array
    {
        try {
            $response = Http::acceptJson()->withToken((string) config('commerce.ruc_lookup.token'))
                ->connectTimeout(5)->timeout(10)->get(rtrim((string) config('commerce.ruc_lookup.url'), '/').'/'.$ruc);
        } catch (ConnectionException $e) {
            throw new LookupUnavailable('MIGO no respondió: '.$e->getMessage());
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful() || $response->json('success') !== true || ! is_string($response->json('nombre_o_razon_social'))) {
            throw new LookupUnavailable('MIGO respondió '.$response->status().': '.$response->json('message', 'respuesta inesperada'));
        }
        $text = fn (string $key) => ($value = trim((string) $response->json($key))) === '' || $value === '-' ? null : $value;

        return [
            'name' => $text('nombre_o_razon_social'),
            'status' => strtoupper((string) $text('estado_del_contribuyente')),
            'condition' => strtoupper((string) $text('condicion_de_domicilio')),
            'address' => $text('direccion'),
        ];
    }

    private function fake(string $ruc): ?array
    {
        if (array_key_exists($ruc, self::FAKE)) {
            return self::FAKE[$ruc];
        }

        return str_starts_with($ruc, '10')
            ? ['name' => 'PERSONA DE PRUEBA '.$ruc, 'status' => 'ACTIVO', 'condition' => 'HABIDO', 'address' => null]
            : ['name' => 'EMPRESA DE PRUEBA '.$ruc.' S.A.C.', 'status' => 'ACTIVO', 'condition' => 'HABIDO', 'address' => 'DIRECCIÓN DE PRUEBA, LIMA'];
    }
}
