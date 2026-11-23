<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\BillingData;
use App\Modules\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Creación de comprobantes (borrador con serie y correlativo) y emisión automática al confirmarse
 * el pago. SEE – Del contribuyente: la factura y sus notas deben llegar a SUNAT en la fecha de
 * emisión o hasta el día siguiente (RS 097-2012, art. 12.1, modificado por RS 193-2020), por eso la
 * boleta o factura que el cliente eligió al comprar se emite y envía sola, sin esperar al backoffice.
 */
class InvoiceIssuer
{
    /** Evento del pedido cuando la emisión automática no pudo crear el comprobante. */
    public const AUTO_FAILED = 'INVOICE_AUTO_FAILED';

    public function __construct(private InvoiceNumbering $numbering, private InvoiceLines $lines, private UblXmlBuilder $builder, private SunatGateway $gateway, private InvoiceSubmission $submission, private OrderService $orders) {}

    /**
     * Crea el borrador. Si el pedido ya tiene su boleta o factura vigente (o la misma anulación o
     * devolución total), devuelve esa: un reintento no emite un segundo documento.
     *
     * @return array{0: string, 1: bool} [id del comprobante, true si ya existía]
     */
    public function create(array $data): array
    {
        $main = in_array($data['type'], ['01', '03'], true);

        return DB::transaction(function () use ($data, $main) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless(in_array($o->status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'], true), 409, 'Pedido no pagado');
            if ($main) {
                $existing = DB::table('invoices')->where('order_id', $o->id)->whereIn('type', ['01', '03'])->get()->first(fn ($i) => ! $this->annulled($i));
                if ($existing) {
                    abort_unless($existing->type === $data['type'], 409, 'Pedido ya facturado');

                    return [$existing->id, true];
                }
                $prefix = null;
                $lines = $this->lines->forOrder($o);
            } else {
                abort_if(empty($data['referencedSerialNumber']), 422, 'Documento de referencia requerido');
                $original = DB::table('invoices')->where('serial_number', $data['referencedSerialNumber'])->first();
                abort_unless($original && $original->order_id === $o->id && $original->status === 'ACCEPTED' && in_array($original->type, ['01', '03'], true), 409, 'Comprobante de referencia no aceptado');
                // La nota usa una serie con la misma letra que el comprobante que modifica.
                $prefix = $original->serial_number[0];
                abort_if(isset($data['series']) && $data['series'][0] !== $prefix, 422, 'La serie de la nota debe empezar con '.$prefix);
                // Reintento de una anulación o devolución total: devuelve la nota ya creada.
                $repeated = $data['type'] === '07' && in_array($data['noteCode'], InvoiceLines::FULL_CREDIT, true)
                    ? DB::table('invoices')->where('referenced_serial_number', $original->serial_number)->where('type', '07')->where('note_code', $data['noteCode'])->where('status', '!=', 'REJECTED')->first() : null;
                if ($repeated) {
                    return [$repeated->id, true];
                }
                $lines = $this->lines->forNote($original, $data['type'], $data['noteCode'], trim($data['noteReason']), $data['items'] ?? [], isset($data['amount']) ? bcadd((string) $data['amount'], '0', 2) : null);
            }
            // Sin datos explícitos se usa la copia guardada en el pedido al comprar.
            $data = $this->customer($data, $o);
            $serial = $this->numbering->next($data['type'], $data['series'] ?? null, $prefix);
            $id = (string) Str::uuid();
            [$taxable, $tax] = InvoiceLines::taxes($lines);
            DB::table('invoices')->insert(['id' => $id, 'order_id' => $o->id, 'type' => $data['type'], 'serial_number' => $serial, 'status' => 'DRAFT', 'customer_document_type' => $data['customerDocumentType'], 'customer_document_number' => $data['customerDocumentNumber'], 'customer_name' => $data['customerName'], 'referenced_serial_number' => $main ? null : $data['referencedSerialNumber'],
                'note_code' => $main ? null : $data['noteCode'], 'note_reason' => $main ? null : trim($data['noteReason']),
                'tax_rate' => $o->tax_rate, 'taxable_amount' => $taxable, 'tax_amount' => $tax, 'total' => InvoiceLines::total($lines), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_values($lines) as $n => $line) {
                DB::table('invoice_lines')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $id, 'line_number' => $n + 1] + $line);
            }
            $this->generateXml($id);

            return [$id, false];
        }, 3);
    }

    /**
     * Emite y envía la boleta o factura de los pedidos pagados que aún no la tienen. Solo mira los
     * pagos recientes (`auto_issue_days`): al activar la opción no factura pedidos antiguos. Un
     * pedido cuyos datos no permiten emitir queda marcado (evento INVOICE_AUTO_FAILED con el motivo)
     * para el backoffice y no se reintenta en cada vuelta. Lo ejecuta el scheduler cada minuto.
     */
    public function issuePending(): int
    {
        if (! config('commerce.sunat.auto_issue') || ! $this->gateway->configured()) {
            return 0;
        }
        $since = now()->subDays(max(1, (int) config('commerce.sunat.auto_issue_days', 1)));
        $orders = DB::table('orders')->whereIn('status', ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'])->whereIn('billing_type', BillingData::INVOICE_TYPES)
            ->whereExists(fn ($q) => $q->from('order_events')->whereColumn('order_events.order_id', 'orders.id')->where('order_events.type', 'PAYMENT_CONFIRMED')->where('order_events.created_at', '>=', $since))
            ->whereNotExists(fn ($q) => $q->from('order_events')->whereColumn('order_events.order_id', 'orders.id')->where('order_events.type', self::AUTO_FAILED))
            ->whereNotExists(fn ($q) => $q->from('invoices')->whereColumn('invoices.order_id', 'orders.id')->whereIn('invoices.type', ['01', '03']))
            ->orderBy('updated_at')->limit(20)->get(['id', 'billing_type']);
        $count = 0;
        foreach ($orders as $order) {
            try {
                [$id, $existed] = $this->create(['orderId' => $order->id, 'type' => $order->billing_type]);
            } catch (HttpException $e) {
                $this->orders->record($order->id, null, self::AUTO_FAILED, null, null, mb_substr($e->getMessage(), 0, 255));
                Log::warning('invoices.auto_issue_failed', ['order' => $order->id, 'message' => $e->getMessage()]);

                continue;
            }
            if (! $existed) {
                // Un error de SUNAT deja el comprobante FAILED, REJECTED o PENDING_CHECK con su detalle.
                $this->submission->run($id, null, 'SUBMIT');
                $count++;
            }
        }

        return $count;
    }

    /** Un comprobante anulado (baja aceptada o nota de crédito 01/02 aceptada) ya no cuenta como el del pedido. */
    private function annulled(object $invoice): bool
    {
        return $invoice->status === 'VOIDED' || DB::table('invoices')->where('referenced_serial_number', $invoice->serial_number)->where('type', '07')
            ->whereIn('note_code', InvoiceLines::ANNULMENTS)->where('status', 'ACCEPTED')->exists();
    }

    /** Completa el cliente desde el pedido y aplica las mismas reglas de documento que el checkout. */
    public function customer(array $data, object $order): array
    {
        $data['customerDocumentType'] ??= $order->billing_document_type;
        $data['customerDocumentNumber'] = strtoupper(str_replace(' ', '', trim((string) ($data['customerDocumentNumber'] ?? $order->billing_document_number))));
        $data['customerName'] = trim((string) ($data['customerName'] ?? $order->billing_name));
        abort_if((string) $data['customerDocumentType'] === '' || $data['customerDocumentNumber'] === '' || $data['customerName'] === '', 422, 'Datos del cliente requeridos');
        abort_if($data['type'] === '01' && $data['customerDocumentType'] !== '6', 422, 'La factura requiere el RUC del cliente.');
        abort_if($data['customerDocumentType'] === '0' && ! BillingData::anonymousAllowed((string) $order->total), 422, 'Desde S/ 700 la boleta requiere el documento del cliente.');
        $error = BillingData::documentError($data['customerDocumentType'], $data['customerDocumentNumber']);
        abort_if($error !== null, 422, $error ?? '');

        return $data;
    }

    public function generateXml(string $id): void
    {
        $invoice = DB::table('invoices')->where('id', $id)->first();
        $lines = DB::table('invoice_lines')->where('invoice_id', $id)->orderBy('line_number')->get()->map(fn ($l) => (array) $l)->all();
        $reference = $invoice->referenced_serial_number ? DB::table('invoices')->where('serial_number', $invoice->referenced_serial_number)->first() : null;
        DB::table('xml_documents')->updateOrInsert(['invoice_id' => $id], ['id' => DB::table('xml_documents')->where('invoice_id', $id)->value('id') ?? (string) Str::uuid(), 'generated_xml' => $this->builder->build($invoice, $lines, $reference), 'signed_xml' => null]);
    }
}
