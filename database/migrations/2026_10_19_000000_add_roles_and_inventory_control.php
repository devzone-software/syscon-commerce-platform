<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles SUPERADMIN / ADMIN / CUSTOMER con cuentas validadas, bitácora de lo que hace el personal,
 * compras al por mayor que ingresan stock y retiros para uso interno que lo sacan de la venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // ACTIVE, PENDING (creada por el superadmin, aún sin validar) o SUSPENDED.
            $t->string('status', 10)->default('ACTIVE');
            // Contraseña temporal entregada por el superadmin: debe cambiarse en el primer ingreso.
            $t->boolean('must_change_password')->default(false);
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('validated_by')->nullable()->constrained('users');
            $t->timestamp('validated_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        // La cuenta inicial del seeder pasa a ser el superadmin; las demás cuentas ADMIN siguen como admin.
        if ($email = config('commerce.admin_email')) {
            DB::table('users')->where('email', strtolower(trim($email)))->where('role', 'ADMIN')->update(['role' => 'SUPERADMIN']);
        }
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('user_id')->constrained('users');
            $t->string('role', 12);
            $t->string('method', 7);
            $t->string('path', 255);
            $t->unsignedSmallInteger('status');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index('created_at');
        });
        Schema::table('products', function (Blueprint $t) {
            // Último costo unitario de compra; el precio de venta sigue siendo el del catálogo.
            $t->decimal('cost', 14, 2)->nullable();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('supplier_name', 255);
            // Factura o guía del proveedor.
            $t->string('document_ref', 60)->nullable();
            $t->string('notes', 500)->nullable();
            $t->decimal('total', 14, 2);
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('purchase_id')->constrained('purchases');
            $t->foreignUuid('product_id')->constrained('products');
            $t->unsignedInteger('quantity');
            $t->decimal('unit_cost', 14, 2);
        });
        Schema::create('internal_uses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained('products');
            $t->unsignedInteger('quantity');
            $t->string('reason', 255);
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_uses');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('cost'));
        Schema::dropIfExists('audit_logs');
        DB::table('users')->where('role', 'SUPERADMIN')->update(['role' => 'ADMIN']);
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('validated_by');
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['status', 'must_change_password', 'validated_at', 'last_login_at']);
        });
    }
};
