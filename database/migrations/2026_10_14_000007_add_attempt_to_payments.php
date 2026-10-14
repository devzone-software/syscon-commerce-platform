<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un pedido puede tener varios intentos con la misma pasarela (uno rechazado y otro aprobado);
 * la unicidad pasa a ser por intento. El pago manual sigue siendo uno por pedido (intento 1).
 * El índice nuevo se crea antes de quitar el anterior: en MySQL la clave foránea de order_id
 * necesita siempre un índice que empiece por esa columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedSmallInteger('attempt')->default(1);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->unique(['order_id', 'provider', 'attempt']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['order_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->unique(['order_id', 'provider']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['order_id', 'provider', 'attempt']);
            $t->dropColumn('attempt');
        });
    }
};
