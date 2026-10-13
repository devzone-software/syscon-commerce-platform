<?php

namespace App\Modules\Invoices;

use Illuminate\Support\Facades\DB;

/**
 * Series configuradas y asignación del correlativo. `next` debe llamarse dentro de la transacción
 * que inserta el comprobante: bloquea la fila de la serie, así dos emisiones simultáneas reciben
 * números distintos, y si la transacción falla el número vuelve a quedar libre (sin saltos).
 */
class InvoiceNumbering
{
    public const MAX_NUMBER = 99999999;

    /** Primera letra de la serie según SUNAT: facturas con F, boletas con B; las notas siguen al documento que modifican. */
    public static function prefixFor(string $type): ?string
    {
        return ['01' => 'F', '03' => 'B'][$type] ?? null;
    }

    public function all(): array
    {
        return DB::table('invoice_series')->orderBy('type')->orderBy('id')->get()->map(fn ($s) => self::view($s))->all();
    }

    public function create(string $type, string $series, int $startAfter): array
    {
        $prefix = self::prefixFor($type);
        abort_if($prefix !== null && ! str_starts_with($series, $prefix), 422, 'Serie incompatible con el tipo de comprobante');
        abort_if(DB::table('invoice_series')->where('type', $type)->where('series', $series)->exists(), 409, 'Serie ya registrada');
        // Nunca por debajo de un número ya emitido con esa serie.
        $used = DB::table('invoices')->where('type', $type)->where('serial_number', 'like', $series.'-%')->pluck('serial_number')
            ->map(fn ($serial) => (int) substr($serial, 5))->max() ?? 0;
        $id = DB::table('invoice_series')->insertGetId(['type' => $type, 'series' => $series, 'last_number' => max($startAfter, $used), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return self::view(DB::table('invoice_series')->where('id', $id)->first());
    }

    public function setActive(int $id, bool $active): array
    {
        abort_unless(DB::table('invoice_series')->where('id', $id)->exists(), 404, 'Serie no encontrada');
        DB::table('invoice_series')->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);

        return self::view(DB::table('invoice_series')->where('id', $id)->first());
    }

    /**
     * Reserva el siguiente número de la serie pedida o, si no se indica, de la primera serie
     * activa del tipo cuya letra coincide ($prefix: la del comprobante que modifica una nota).
     */
    public function next(string $type, ?string $series, ?string $prefix): string
    {
        $prefix ??= self::prefixFor($type);
        $query = DB::table('invoice_series')->where('type', $type)->where('active', true);
        if ($series !== null) {
            $query->where('series', $series);
        }
        if ($prefix !== null) {
            $query->where('series', 'like', $prefix.'%');
        }
        $row = $query->orderBy('id')->lockForUpdate()->first();
        abort_unless($row, 409, $series !== null ? 'Serie no disponible para este comprobante' : 'No hay una serie activa para este comprobante');
        abort_if($row->last_number >= self::MAX_NUMBER, 409, 'La serie '.$row->series.' llegó a su último correlativo');
        $number = $row->last_number + 1;
        DB::table('invoice_series')->where('id', $row->id)->update(['last_number' => $number, 'updated_at' => now()]);

        return $row->series.'-'.$number;
    }

    private static function view(object $s): array
    {
        return ['id' => (int) $s->id, 'type' => $s->type, 'series' => $s->series, 'lastNumber' => (int) $s->last_number, 'nextNumber' => $s->series.'-'.((int) $s->last_number + 1), 'active' => (bool) $s->active];
    }
}
