<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precios de referencia: empresas cuyo precio público (soles, IGV incluido) se toma como
     * costo de compra, el enlace a cada producto en su web, el historial de lo leído y el
     * margen de venta por categoría. Los cambios de precio se aprueban uno por uno.
     */
    public function up(): void
    {
        Schema::create('price_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            // Dominio de la web de la empresa: los enlaces de sus productos deben ser de este dominio.
            $t->string('host', 190)->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('price_references', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('source_id')->constrained('price_sources')->cascadeOnDelete();
            $t->text('url');
            $t->decimal('price', 14, 2)->nullable();
            // OK: leído de la web · MANUAL: escrito por el personal · ERROR: la última lectura falló.
            $t->string('status', 10)->default('PENDING');
            $t->string('error', 255)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'source_id']);
        });
        Schema::create('price_reference_history', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('reference_id')->constrained('price_references')->cascadeOnDelete();
            $t->decimal('price', 14, 2);
            $t->string('origin', 10);
            $t->timestamp('captured_at');
        });
        Schema::create('category_margins', function (Blueprint $t) {
            $t->foreignUuid('category_id')->primary()->constrained()->cascadeOnDelete();
            $t->decimal('margin', 6, 2);
            $t->timestamps();
        });
        Schema::table('products', function (Blueprint $t) {
            // Costo de referencia cuya sugerencia se ignoró: no se vuelve a proponer hasta que cambie.
            $t->decimal('price_dismissed_cost', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('price_dismissed_cost'));
        Schema::dropIfExists('category_margins');
        Schema::dropIfExists('price_reference_history');
        Schema::dropIfExists('price_references');
        Schema::dropIfExists('price_sources');
    }
};
