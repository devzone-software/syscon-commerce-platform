<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anulaciones enviadas a SUNAT: comunicación de baja (RA) para facturas y sus notas, resumen
 * diario (RC) con estado "anulado" para boletas y sus notas. SUNAT las procesa de forma
 * asíncrona: el envío devuelve un ticket y el CDR se obtiene después con getStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voided_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            // RA o RC.
            $t->string('kind', 2);
            // RA-AAAAMMDD-n / RC-AAAAMMDD-n, correlativo por día y tipo.
            $t->string('identifier', 20)->unique();
            $t->date('reference_date');
            $t->date('issue_date');
            $t->string('reason', 100);
            $t->string('ticket', 50)->nullable();
            // SENT (esperando a SUNAT), ACCEPTED, REJECTED o FAILED.
            $t->string('status', 10);
            $t->longText('signed_xml')->nullable();
            $t->longText('cdr')->nullable();
            $t->string('response_code', 10)->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voided_documents');
    }
};
