<?php

use App\Modules\Orders\Tax;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Base imponible e IGV por línea y por documento, y líneas propias del comprobante. El XML y la
 * representación impresa leen las líneas del comprobante, no el pedido: una nota de crédito por
 * ítem o una nota de débito tienen importes distintos a los del pedido.
 */
return new class extends Migration
{
    /** Tasa con la que se vendieron los pedidos existentes (precios con IGV incluido). */
    private const RATE = '0.1800';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('tax_rate', 5, 4)->nullable();
            $t->decimal('taxable_amount', 14, 2)->nullable();
            $t->decimal('tax_amount', 14, 2)->nullable();
        });
        Schema::table('order_details', function (Blueprint $t) {
            $t->decimal('line_total', 14, 2)->nullable();
            $t->decimal('taxable_amount', 14, 2)->nullable();
            $t->decimal('tax_amount', 14, 2)->nullable();
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('tax_rate', 5, 4)->nullable();
            $t->decimal('taxable_amount', 14, 2)->nullable();
            $t->decimal('tax_amount', 14, 2)->nullable();
            // Motivo de la nota (catálogo 09 para crédito, 10 para débito) y su sustento.
            $t->string('note_code', 2)->nullable();
            $t->string('note_reason', 250)->nullable();
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained();
            $t->unsignedSmallInteger('line_number');
            $t->foreignUuid('product_id')->nullable()->constrained();
            $t->string('sku')->nullable();
            $t->string('description', 500);
            // NIU: bienes; ZZ: servicios (envío, intereses, penalidades).
            $t->string('unit_code', 3);
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->decimal('line_total', 14, 2);
            $t->decimal('taxable_amount', 14, 2);
            $t->decimal('tax_amount', 14, 2);
            $t->unique(['invoice_id', 'line_number']);
        });

        foreach (DB::table('orders')->select('id', 'delivery_cost')->cursor() as $order) {
            $base = '0.00';
            $tax = '0.00';
            foreach (DB::table('order_details')->where('order_id', $order->id)->get() as $d) {
                $gross = bcmul((string) $d->unit_price, (string) $d->quantity, 2);
                [$b, $i] = Tax::split($gross, self::RATE);
                DB::table('order_details')->where('id', $d->id)->update(['line_total' => $gross, 'taxable_amount' => $b, 'tax_amount' => $i]);
                $base = bcadd($base, $b, 2);
                $tax = bcadd($tax, $i, 2);
            }
            [$b, $i] = Tax::split(bcadd((string) ($order->delivery_cost ?? '0'), '0', 2), self::RATE);
            DB::table('orders')->where('id', $order->id)->update(['tax_rate' => self::RATE, 'taxable_amount' => bcadd($base, $b, 2), 'tax_amount' => bcadd($tax, $i, 2)]);
        }
        // Hasta ahora todo comprobante, nota incluida, cubría el pedido completo.
        foreach (DB::table('invoices')->select('id', 'order_id')->cursor() as $invoice) {
            $order = DB::table('orders')->where('id', $invoice->order_id)->first();
            $lines = DB::table('order_details')->where('order_id', $order->id)->orderBy('product_id')->get()
                ->map(fn ($d) => ['product_id' => $d->product_id, 'sku' => $d->sku, 'description' => $d->name, 'unit_code' => 'NIU', 'quantity' => $d->quantity, 'unit_price' => $d->unit_price, 'line_total' => $d->line_total, 'taxable_amount' => $d->taxable_amount, 'tax_amount' => $d->tax_amount])->all();
            if (bccomp((string) ($order->delivery_cost ?? '0'), '0', 2) > 0) {
                [$b, $i] = Tax::split(bcadd((string) $order->delivery_cost, '0', 2), self::RATE);
                $lines[] = ['product_id' => null, 'sku' => null, 'description' => 'Servicio de envío a domicilio', 'unit_code' => 'ZZ', 'quantity' => 1, 'unit_price' => $order->delivery_cost, 'line_total' => $order->delivery_cost, 'taxable_amount' => $b, 'tax_amount' => $i];
            }
            foreach (array_values($lines) as $n => $line) {
                DB::table('invoice_lines')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'line_number' => $n + 1] + $line);
            }
            DB::table('invoices')->where('id', $invoice->id)->update(['tax_rate' => self::RATE, 'taxable_amount' => $order->taxable_amount, 'tax_amount' => $order->tax_amount]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['tax_rate', 'taxable_amount', 'tax_amount', 'note_code', 'note_reason']));
        Schema::table('order_details', fn (Blueprint $t) => $t->dropColumn(['line_total', 'taxable_amount', 'tax_amount']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['tax_rate', 'taxable_amount', 'tax_amount']));
    }
};
