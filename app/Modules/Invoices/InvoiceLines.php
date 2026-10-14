<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\Tax;
use Illuminate\Support\Facades\DB;

/**
 * Líneas de cada comprobante, con base imponible e IGV ya separados. Se guardan en
 * `invoice_lines` y de ahí salen el XML y la representación impresa.
 */
class InvoiceLines
{
    /** Motivos de nota de crédito soportados (catálogo 09): los tres primeros anulan el documento completo. */
    public const CREDIT_REASONS = [
        '01' => 'Anulación de la operación', '02' => 'Anulación por error en el RUC', '06' => 'Devolución total',
        '07' => 'Devolución por ítem', '09' => 'Disminución en el valor',
    ];

    /** Motivos de nota de débito soportados (catálogo 10). */
    public const DEBIT_REASONS = ['01' => 'Intereses por mora', '02' => 'Aumento en el valor', '03' => 'Penalidades / otros conceptos'];

    /** Notas de crédito que revierten el documento completo; tras una aceptada se puede volver a emitir. */
    public const FULL_CREDIT = ['01', '02', '06'];

    /** Anulaciones que permiten emitir de nuevo el comprobante del pedido (p. ej. con el RUC corregido). */
    public const ANNULMENTS = ['01', '02'];

    /** Productos del pedido y, si hubo envío a domicilio, el servicio de envío. */
    public function forOrder(object $order): array
    {
        $rate = (string) $order->tax_rate;
        $lines = DB::table('order_details')->where('order_id', $order->id)->orderBy('product_id')->get()
            ->map(fn ($d) => ['product_id' => $d->product_id, 'sku' => $d->sku, 'description' => $d->name, 'unit_code' => 'NIU', 'quantity' => (int) $d->quantity,
                'unit_price' => (string) $d->unit_price, 'line_total' => (string) $d->line_total, 'taxable_amount' => (string) $d->taxable_amount, 'tax_amount' => (string) $d->tax_amount])->all();
        if (bccomp((string) ($order->delivery_cost ?? '0'), '0', 2) > 0) {
            $lines[] = $this->service('Servicio de envío a domicilio', bcadd((string) $order->delivery_cost, '0', 2), $rate);
        }

        return $lines;
    }

    /**
     * Líneas de una nota sobre el comprobante $original.
     *
     * @param  array<int, array{productId: string, quantity: int}>  $items  Devolución por ítem.
     * @param  string|null  $amount  Importe con IGV de una disminución o de una nota de débito.
     */
    public function forNote(object $original, string $type, string $code, string $reason, array $items, ?string $amount): array
    {
        $rate = (string) $original->tax_rate;
        $previous = DB::table('invoices')->where('referenced_serial_number', $original->serial_number)->where('type', '07')->where('status', '!=', 'REJECTED');
        if ($type === '08') {
            abort_unless(isset(self::DEBIT_REASONS[$code]), 422, 'Motivo de nota de débito no soportado');
            abort_if($amount === null, 422, 'Indique el importe de la nota de débito');

            return [$this->service($reason, $amount, $rate)];
        }
        abort_unless(isset(self::CREDIT_REASONS[$code]), 422, 'Motivo de nota de crédito no soportado');
        $credited = (string) ((clone $previous)->sum('total') ?: '0');
        if (in_array($code, self::FULL_CREDIT, true)) {
            abort_if(bccomp($credited, '0', 2) > 0, 409, 'El comprobante ya tiene notas de crédito; una anulación o devolución total no puede repetir importes');
            $lines = DB::table('invoice_lines')->where('invoice_id', $original->id)->orderBy('line_number')->get()
                ->map(fn ($l) => array_intersect_key((array) $l, array_flip(['product_id', 'sku', 'description', 'unit_code', 'quantity', 'unit_price', 'line_total', 'taxable_amount', 'tax_amount'])))->all();
        } elseif ($code === '07') {
            abort_if($items === [], 422, 'Indique los productos devueltos');
            $returned = DB::table('invoice_lines')->whereIn('invoice_id', (clone $previous)->pluck('id'))->whereNotNull('product_id')
                ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as quantity')->pluck('quantity', 'product_id');
            $lines = [];
            foreach ($items as $item) {
                $sold = DB::table('invoice_lines')->where('invoice_id', $original->id)->where('product_id', $item['productId'])->first();
                abort_unless($sold, 422, 'El producto no figura en el comprobante');
                abort_if($item['quantity'] > $sold->quantity - (int) ($returned[$item['productId']] ?? 0), 422, 'La cantidad devuelta supera la vendida');
                $gross = bcmul((string) $sold->unit_price, (string) $item['quantity'], 2);
                [$base, $igv] = Tax::split($gross, $rate);
                $lines[] = ['product_id' => $sold->product_id, 'sku' => $sold->sku, 'description' => $sold->description, 'unit_code' => 'NIU', 'quantity' => $item['quantity'],
                    'unit_price' => (string) $sold->unit_price, 'line_total' => $gross, 'taxable_amount' => $base, 'tax_amount' => $igv];
            }
        } else {
            abort_if($amount === null, 422, 'Indique el importe de la disminución');
            $lines = [$this->service($reason, $amount, $rate)];
        }
        abort_if(bccomp(bcadd($credited, self::total($lines), 2), (string) $original->total, 2) > 0, 422, 'Las notas de crédito superan el total del comprobante');

        return $lines;
    }

    public static function total(array $lines): string
    {
        return array_reduce($lines, fn ($sum, $l) => bcadd($sum, (string) $l['line_total'], 2), '0.00');
    }

    /** @return array{0: string, 1: string} [base imponible, IGV] del documento. */
    public static function taxes(array $lines): array
    {
        return [array_reduce($lines, fn ($sum, $l) => bcadd($sum, (string) $l['taxable_amount'], 2), '0.00'),
            array_reduce($lines, fn ($sum, $l) => bcadd($sum, (string) $l['tax_amount'], 2), '0.00')];
    }

    private function service(string $description, string $gross, string $rate): array
    {
        [$base, $igv] = Tax::split($gross, $rate);

        return ['product_id' => null, 'sku' => null, 'description' => $description, 'unit_code' => 'ZZ', 'quantity' => 1,
            'unit_price' => $gross, 'line_total' => $gross, 'taxable_amount' => $base, 'tax_amount' => $igv];
    }
}
