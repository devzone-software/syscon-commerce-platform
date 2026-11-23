<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada proveedor con feed CSV tiene su propia "empresa de referencia": el precio del feed llega
 * como precio de referencia (costo) y no reemplaza el precio de venta. Esas fuentes no se leen de
 * una web; se actualizan con la sincronización del proveedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_sources', function (Blueprint $t) {
            $t->foreignUuid('supplier_id')->nullable()->unique()->constrained('suppliers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_sources', function (Blueprint $t) {
            $t->dropUnique(['supplier_id']);
            $t->dropForeign(['supplier_id']);
        });
        Schema::table('price_sources', fn (Blueprint $t) => $t->dropColumn('supplier_id'));
    }
};
