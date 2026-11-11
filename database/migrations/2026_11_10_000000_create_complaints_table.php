<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Libro de Reclamaciones virtual: cada hoja con su número correlativo y la respuesta del proveedor. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('type', 10);
            $table->string('consumer_name');
            $table->string('document_type', 10);
            $table->string('document_number', 20);
            $table->string('address', 500);
            $table->string('phone', 30);
            $table->string('email');
            $table->string('guardian_name')->nullable();
            $table->string('item_type', 10);
            $table->string('item_description', 500);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('order_reference', 100)->nullable();
            $table->text('detail');
            $table->text('consumer_request');
            $table->string('status', 12)->default('PENDING');
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->uuid('responded_by')->nullable();
            $table->date('due_on');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
