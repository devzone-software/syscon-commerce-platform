<?php

namespace App\Modules\Lookup;

use App\Modules\Orders\BillingData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de razón social y estado de un RUC. El formato y el dígito verificador se validan
 * aquí, sin consultar; la identidad la responde el proveedor configurado en RUC_LOOKUP_DRIVER:
 * - fake: datos ficticios deterministas para desarrollo y pruebas (no consulta a nadie).
 * - none: consulta desactivada; el cliente escribe la razón social.
 * El proveedor real se agrega como otro driver cuando se contrate.
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

    public function enabled(): bool
    {
        return $this->driver() !== 'none';
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
