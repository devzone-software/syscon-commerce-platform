<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Series y correlativo por tipo de comprobante. El backend asigna el número dentro de la misma
 * transacción que crea el comprobante, bloqueando la fila de la serie.
 */
return new class extends Migration
{
    /** Series iniciales: F para facturas y sus notas, B para boletas y sus notas. */
    private const DEFAULTS = [['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['07', 'BC01'], ['08', 'FD01'], ['08', 'BD01']];

    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $t) {
            $t->id();
            $t->string('type', 2);
            $t->string('series', 4);
            // Último correlativo asignado; el siguiente comprobante usa last_number + 1.
            $t->unsignedInteger('last_number')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['type', 'series']);
        });

        // Las series ya usadas a mano continúan desde su número más alto para no repetir correlativos.
        $used = [];
        foreach (DB::table('invoices')->select('type', 'serial_number')->cursor() as $invoice) {
            if (preg_match('/^([FB][A-Z0-9]{3})-(\d+)$/', $invoice->serial_number, $m)) {
                $key = $invoice->type.'|'.$m[1];
                $used[$key] = max($used[$key] ?? 0, (int) $m[2]);
            }
        }
        $rows = [];
        foreach (self::DEFAULTS as [$type, $series]) {
            $rows[$type.'|'.$series] = 0;
        }
        foreach ($used as $key => $last) {
            $rows[$key] = $last;
        }
        foreach ($rows as $key => $last) {
            [$type, $series] = explode('|', $key);
            DB::table('invoice_series')->insert(['type' => $type, 'series' => $series, 'last_number' => $last, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
