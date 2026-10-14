<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de venta: WEB (checkout del cliente) o STORE (venta directa registrada por el personal,
 * que queda como vendedor). Los pedidos existentes vienen de la web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('channel', 10)->default('WEB');
            $t->foreignUuid('seller_id')->nullable()->constrained('users');
            $t->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['channel', 'created_at']);
            $t->dropConstrainedForeignId('seller_id');
            $t->dropColumn('channel');
        });
    }
};
