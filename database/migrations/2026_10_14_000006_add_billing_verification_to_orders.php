<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se verificó del RUC al comprar: fuente, si existe, razón social, estado y condición según el
 * proveedor, y cuándo. Null si no hubo consulta (desactivada, sin RUC o proveedor caído).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->json('billing_verification')->nullable());
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('billing_verification'));
    }
};
