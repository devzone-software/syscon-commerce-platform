<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia de los datos de facturación elegidos al comprar. Son nullable porque los pedidos
 * anteriores no los capturaron y no se completan con identidades supuestas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('billing_type', 2)->nullable();
            $t->string('billing_document_type', 1)->nullable();
            $t->string('billing_document_number', 15)->nullable();
            $t->string('billing_name')->nullable();
            $t->string('billing_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['billing_type', 'billing_document_type', 'billing_document_number', 'billing_name', 'billing_address']);
        });
    }
};
