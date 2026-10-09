<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('CUSTOMER');
            $t->timestamps();
        });
        Schema::create('refresh_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->boolean('revoked')->default(false);
        });
        foreach (['categories', 'brands'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name')->unique();
            });
        }
        Schema::create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('sku')->unique();
            $t->string('name');
            $t->decimal('price', 14, 2);
            $t->unsignedInteger('stock');
            $t->unsignedInteger('reserved')->default(0);
            $t->text('image_url')->nullable();
            $t->foreignUuid('category_id')->nullable()->constrained();
            $t->foreignUuid('brand_id')->nullable()->constrained();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('customer_id')->constrained('users');
            $t->string('status');
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        Schema::create('order_details', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            $t->foreignUuid('product_id')->constrained();
            $t->string('sku');
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->unique(['order_id', 'product_id']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            $t->foreignUuid('customer_id')->constrained('users');
            $t->string('provider');
            $t->string('status');
            $t->decimal('amount', 14, 2);
            $t->string('external_reference')->nullable();
            $t->text('payment_url')->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'provider']);
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->text('feed_url');
            $t->unsignedInteger('min_interval_minutes')->default(15);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('supplier_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained();
            $t->string('status');
            $t->unsignedInteger('imported')->default(0);
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->index(['supplier_id', 'started_at']);
        });
        Schema::create('supplier_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('job_id')->constrained('supplier_jobs');
            $t->string('level');
            $t->text('message');
            $t->timestamp('occurred_at');
        });
        Schema::create('supplier_products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained();
            $t->string('sku');
            $t->string('name');
            $t->decimal('price', 14, 2);
            $t->unsignedInteger('stock');
            $t->text('image_url')->nullable();
            $t->string('brand')->nullable();
            $t->string('category')->nullable();
            $t->unique(['supplier_id', 'sku']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained();
            $t->string('type', 2);
            $t->string('serial_number')->unique();
            $t->string('status')->default('DRAFT');
            $t->string('customer_document_type');
            $t->string('customer_document_number');
            $t->string('customer_name');
            $t->string('referenced_serial_number')->nullable();
            $t->decimal('total', 14, 2);
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('xml_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->unique()->constrained();
            $t->longText('generated_xml');
            $t->longText('signed_xml')->nullable();
        });
        Schema::create('cdr_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->unique()->constrained();
            $t->longText('zip_base64');
            $t->string('response_code');
            $t->text('description');
        });
    }

    public function down(): void
    {
        foreach (['cdr_responses', 'xml_documents', 'invoices', 'supplier_products', 'supplier_history', 'supplier_jobs', 'suppliers', 'payments', 'order_details', 'orders', 'products', 'brands', 'categories', 'refresh_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
