<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\BillingData;
use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController
{
    private const SERIES = 'regex:/^[FB][A-Z0-9]{3}$/';

    private const SERIAL = 'regex:/^[FB][A-Z0-9]{3}-[1-9][0-9]{0,7}$/';

    public function __construct(private OrderService $orders, private UblXmlBuilder $builder, private SunatGateway $gateway, private InvoiceNumbering $numbering, private InvoiceLines $lines, private InvoiceFiles $files) {}

    /**
     * Crea el borrador con la serie y el correlativo que asigna el backend. Si el pedido ya tiene
     * su boleta o factura del mismo tipo, devuelve esa (un reintento no emite un segundo documento);
     * solo una anulación aceptada (nota de crédito 01 o 02) permite emitir otra.
     */
    public function store(Request $r)
    {
        $data = $r->validate(['orderId' => 'required|uuid', 'type' => 'required|in:01,03,07,08', 'series' => ['nullable', self::SERIES], 'customerDocumentType' => 'nullable|in:'.implode(',', BillingData::DOCUMENT_TYPES), 'customerDocumentNumber' => 'nullable|string|max:15', 'customerName' => 'nullable|string|max:255', 'referencedSerialNumber' => ['nullable', self::SERIAL],
            'noteCode' => 'required_if:type,07,08|nullable|in:01,02,03,06,07,09', 'noteReason' => 'required_if:type,07,08|nullable|string|max:250',
            'items' => 'nullable|array|max:100', 'items.*.productId' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:100000',
            'amount' => 'nullable|decimal:0,2|gt:0|max:999999999999.99']);
        $main = in_array($data['type'], ['01', '03'], true);

        return DB::transaction(function () use ($data, $main) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless(in_array($o->status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'], true), 409, 'Pedido no pagado');
            if ($main) {
                $existing = DB::table('invoices')->where('order_id', $o->id)->whereIn('type', ['01', '03'])->get()->first(fn ($i) => ! $this->annulled($i));
                if ($existing) {
                    abort_unless($existing->type === $data['type'], 409, 'Pedido ya facturado');

                    return Api::ok('Comprobante ya registrado', $this->view($existing->id));
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
                    return Api::ok('Comprobante ya registrado', $this->view($repeated->id));
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

            return Api::ok('Comprobante creado', $this->view($id));
        }, 3);
    }

    /** Un comprobante anulado por una nota de crédito aceptada ya no cuenta como el del pedido. */
    private function annulled(object $invoice): bool
    {
        return DB::table('invoices')->where('referenced_serial_number', $invoice->serial_number)->where('type', '07')
            ->whereIn('note_code', InvoiceLines::ANNULMENTS)->where('status', 'ACCEPTED')->exists();
    }

    /**
     * Corrige los datos del cliente antes de enviar. Solo en borrador: un comprobante que ya pasó por
     * el envío pudo llegar a SUNAT, así que su corrección es una nota, no una edición.
     */
    public function update(Request $r, string $id)
    {
        $data = $r->validate(['customerDocumentType' => 'required|in:'.implode(',', BillingData::DOCUMENT_TYPES), 'customerDocumentNumber' => 'required|string|max:15', 'customerName' => 'required|string|max:255']);

        return DB::transaction(function () use ($id, $data) {
            $invoice = DB::table('invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Comprobante no encontrado');
            abort_unless($invoice->status === 'DRAFT', 409, 'Solo se corrigen comprobantes en borrador');
            $data = $this->customer($data + ['type' => $invoice->type], DB::table('orders')->where('id', $invoice->order_id)->first());
            DB::table('invoices')->where('id', $id)->update(['customer_document_type' => $data['customerDocumentType'], 'customer_document_number' => $data['customerDocumentNumber'], 'customer_name' => $data['customerName'], 'updated_at' => now()]);
            $this->generateXml($id);

            return Api::ok('Comprobante corregido', $this->view($id));
        });
    }

    public function series()
    {
        return Api::ok('Series', $this->numbering->all());
    }

    /** Alta de una serie; `startAfter` continúa una numeración usada fuera del sistema. */
    public function createSeries(Request $r)
    {
        $data = $r->validate(['type' => 'required|in:01,03,07,08', 'series' => ['required', self::SERIES], 'startAfter' => 'nullable|integer|min:0|max:'.(InvoiceNumbering::MAX_NUMBER - 1)]);

        return Api::ok('Serie registrada', $this->numbering->create($data['type'], $data['series'], (int) ($data['startAfter'] ?? 0)));
    }

    public function updateSeries(Request $r, int $id)
    {
        $data = $r->validate(['active' => 'required|boolean']);

        return Api::ok('Serie actualizada', $this->numbering->setActive($id, (bool) $data['active']));
    }

    /** Completa el cliente desde el pedido y aplica las mismas reglas de documento que el checkout. */
    private function customer(array $data, object $order): array
    {
        $data['customerDocumentType'] ??= $order->billing_document_type;
        $data['customerDocumentNumber'] = strtoupper(str_replace(' ', '', trim((string) ($data['customerDocumentNumber'] ?? $order->billing_document_number))));
        $data['customerName'] = trim((string) ($data['customerName'] ?? $order->billing_name));
        abort_if(! $data['customerDocumentType'] || $data['customerDocumentNumber'] === '' || $data['customerName'] === '', 422, 'Datos del cliente requeridos');
        abort_if($data['type'] === '01' && $data['customerDocumentType'] !== '6', 422, 'La factura requiere el RUC del cliente.');
        $error = BillingData::documentError($data['customerDocumentType'], $data['customerDocumentNumber']);
        abort_if($error !== null, 422, $error ?? '');

        return $data;
    }

    private function generateXml(string $id): void
    {
        $invoice = DB::table('invoices')->where('id', $id)->first();
        DB::table('xml_documents')->updateOrInsert(['invoice_id' => $id], ['id' => DB::table('xml_documents')->where('invoice_id', $id)->value('id') ?? (string) Str::uuid(), 'generated_xml' => $this->builder->build($invoice, $this->linesOf($id), $this->referenceOf($invoice)), 'signed_xml' => null]);
    }

    private function linesOf(string $id): array
    {
        return DB::table('invoice_lines')->where('invoice_id', $id)->orderBy('line_number')->get()->map(fn ($l) => (array) $l)->all();
    }

    private function referenceOf(object $invoice): ?object
    {
        return $invoice->referenced_serial_number ? DB::table('invoices')->where('serial_number', $invoice->referenced_serial_number)->first() : null;
    }

    public function show(string $id)
    {
        return Api::ok('Comprobante', $this->view($id));
    }

    /** Administración: PDF de cualquier estado (con su advertencia), XML y CDR. */
    public function file(string $id, string $format)
    {
        $invoice = DB::table('invoices')->where('id', $id)->first();
        abort_unless($invoice, 404, 'Comprobante no encontrado');

        return $this->files->{$format}($invoice);
    }

    /** Comprobantes que el cliente puede descargar: los aceptados por SUNAT de su pedido. */
    public function ofOrder(Request $r, string $id)
    {
        $this->ownOrder($r, $id);

        return Api::ok('Comprobantes', DB::table('invoices')->where('order_id', $id)->where('status', 'ACCEPTED')->orderBy('created_at')->get()
            ->map(fn ($i) => ['id' => $i->id, 'type' => $i->type, 'serialNumber' => $i->serial_number, 'status' => $i->status, 'referencedSerialNumber' => $i->referenced_serial_number,
                'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at), 'test' => InvoiceFiles::testing(), 'hasCdr' => DB::table('cdr_responses')->where('invoice_id', $i->id)->exists()])->all());
    }

    public function customerFile(Request $r, string $id, string $invoice, string $format)
    {
        $this->ownOrder($r, $id);
        $row = DB::table('invoices')->where('id', $invoice)->where('order_id', $id)->where('status', 'ACCEPTED')->first();
        abort_unless($row, 404, 'Comprobante no disponible');

        return $this->files->{$format}($row);
    }

    private function ownOrder(Request $r, string $id): void
    {
        $customer = DB::table('orders')->where('id', $id)->value('customer_id');
        abort_unless($customer, 404, 'Pedido no encontrado');
        abort_unless($customer === $r->user()->id || in_array('orders:manage', $r->user()->permissions(), true), 403, 'Acceso denegado');
    }

    public function submit(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $invoice = DB::table('invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Comprobante no encontrado');
            if ($invoice->status === 'ACCEPTED') {
                return Api::ok('Envío procesado', $this->view($id));
            }
            abort_unless(in_array($invoice->status, ['DRAFT', 'FAILED'], true), 409, 'Estado de comprobante inválido');
            abort_unless($this->gateway->configured(), 503, 'Credenciales SUNAT no configuradas');
            try {
                $doc = DB::table('xml_documents')->where('invoice_id', $id)->first();
                $signed = $doc->signed_xml ?? $this->gateway->sign($doc->generated_xml);
                DB::table('xml_documents')->where('invoice_id', $id)->update(['signed_xml' => $signed]);
                $cdr = $this->gateway->sendBill(config('commerce.sunat.ruc').'-'.$invoice->type.'-'.$invoice->serial_number, $signed);
                [$code,$description] = $this->gateway->parseCdr($cdr);
                $existing = DB::table('cdr_responses')->where('invoice_id', $id)->first();
                DB::table('cdr_responses')->updateOrInsert(['invoice_id' => $id], ['id' => $existing?->id ?? (string) Str::uuid(), 'zip_base64' => $cdr, 'response_code' => $code, 'description' => $description]);
                DB::table('invoices')->where('id', $id)->update(['status' => $code === '0' ? 'ACCEPTED' : 'REJECTED', 'error' => null, 'updated_at' => now()]);
                if ($code === '0') {
                    // Las notas no mueven stock ni el estado del pedido: la devolución física la registra
                    // inventario. Quedan en el historial para conciliar lo cobrado con lo facturado.
                    $event = ['01' => 'INVOICE_ACCEPTED', '03' => 'INVOICE_ACCEPTED', '07' => 'CREDIT_NOTE_ACCEPTED', '08' => 'DEBIT_NOTE_ACCEPTED'][$invoice->type];
                    $this->orders->record($invoice->order_id, $r->user()?->id, $event, null, null, $invoice->serial_number);
                }
            } catch (\Throwable $e) {
                DB::table('invoices')->where('id', $id)->update(['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
            }

            return Api::ok('Envío procesado', $this->view($id));
        });
    }

    private function view(string $id): array
    {
        $i = DB::table('invoices')->where('id', $id)->first();
        abort_unless($i, 404, 'Comprobante no encontrado');
        $c = DB::table('cdr_responses')->where('invoice_id', $id)->first();

        return ['id' => $i->id, 'orderId' => $i->order_id, 'type' => $i->type, 'serialNumber' => $i->serial_number, 'status' => $i->status, 'error' => $i->error, 'sunatCode' => $c?->response_code, 'sunatDescription' => $c?->description,
            'customerDocumentType' => $i->customer_document_type, 'customerDocumentNumber' => $i->customer_document_number, 'customerName' => $i->customer_name,
            'referencedSerialNumber' => $i->referenced_serial_number, 'noteCode' => $i->note_code, 'noteReason' => $i->note_reason,
            'taxRate' => bcadd((string) $i->tax_rate, '0', 4), 'taxableAmount' => bcadd((string) $i->taxable_amount, '0', 2), 'taxAmount' => bcadd((string) $i->tax_amount, '0', 2),
            'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at), 'test' => InvoiceFiles::testing(), 'hasCdr' => $c !== null,
            'lines' => array_map(fn ($l) => ['lineNumber' => (int) $l['line_number'], 'productId' => $l['product_id'], 'sku' => $l['sku'], 'description' => $l['description'], 'unitCode' => $l['unit_code'], 'quantity' => (int) $l['quantity'],
                'unitPrice' => bcadd((string) $l['unit_price'], '0', 2), 'lineTotal' => bcadd((string) $l['line_total'], '0', 2), 'taxableAmount' => bcadd((string) $l['taxable_amount'], '0', 2), 'taxAmount' => bcadd((string) $l['tax_amount'], '0', 2)], $this->linesOf($id))];
    }
}
