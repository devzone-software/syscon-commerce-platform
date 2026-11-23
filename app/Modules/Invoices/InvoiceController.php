<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\BillingData;
use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController
{
    private const SERIES = 'regex:/^[FB][A-Z0-9]{3}$/';

    private const SERIAL = 'regex:/^[FB][A-Z0-9]{3}-[1-9][0-9]{0,7}$/';

    public function __construct(private InvoiceIssuer $issuer, private InvoiceNumbering $numbering, private InvoiceFiles $files, private InvoiceSubmission $submission, private InvoiceVoiding $voiding) {}

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
        [$id, $existed] = $this->issuer->create($data);

        return Api::ok($existed ? 'Comprobante ya registrado' : 'Comprobante creado', $this->view($id));
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
            $data = $this->issuer->customer($data + ['type' => $invoice->type], DB::table('orders')->where('id', $invoice->order_id)->first());
            DB::table('invoices')->where('id', $id)->update(['customer_document_type' => $data['customerDocumentType'], 'customer_document_number' => $data['customerDocumentNumber'], 'customer_name' => $data['customerName'], 'updated_at' => now()]);
            $this->issuer->generateXml($id);

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

    private function linesOf(string $id): array
    {
        return DB::table('invoice_lines')->where('invoice_id', $id)->orderBy('line_number')->get()->map(fn ($l) => (array) $l)->all();
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
                'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at), 'test' => InvoiceFiles::testing(), 'hasCdr' => DB::table('cdr_responses')->where('invoice_id', $i->id)->where('zip_base64', '!=', '')->exists()])->all());
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
        $this->submission->run($id, $r->user()?->id, 'SUBMIT');

        return Api::ok('Envío procesado', $this->view($id));
    }

    /** Anula un comprobante aceptado con comunicación de baja (serie F) o resumen diario (serie B). */
    public function void(Request $r, string $id)
    {
        $data = $r->validate(['reason' => 'required|string|min:3|max:100']);
        $this->voiding->void($id, $r->user()?->id, $data['reason']);

        return Api::ok('Anulación procesada', $this->view($id));
    }

    /** Vuelve a consultar el ticket de una anulación que SUNAT aún procesaba. */
    public function checkVoid(Request $r, string $id)
    {
        $void = DB::table('voided_documents')->where('invoice_id', $id)->where('status', 'SENT')->orderByDesc('created_at')->first();
        abort_unless($void, 409, 'No hay una anulación en proceso');
        $this->voiding->check($void->id, $r->user()?->id);

        return Api::ok('Anulación consultada', $this->view($id));
    }

    /** Pendiente de conciliación: consulta el CDR o reenvía el mismo XML firmado (SUNAT responde 1033 si ya lo tenía). */
    public function reconcile(Request $r, string $id)
    {
        $status = DB::table('invoices')->where('id', $id)->value('status');
        abort_unless($status, 404, 'Comprobante no encontrado');
        abort_unless(in_array($status, ['PENDING_CHECK', 'FAILED'], true), 409, 'Solo se concilian comprobantes pendientes o con error de envío');
        $this->submission->run($id, $r->user()?->id, 'RECONCILE');

        return Api::ok('Conciliación procesada', $this->view($id));
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
            'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at), 'test' => InvoiceFiles::testing(), 'hasCdr' => ($c?->zip_base64 ?? '') !== '',
            'sendDeadline' => in_array($i->status, ['DRAFT', 'FAILED'], true) ? InvoiceSubmission::deadline($i) : null,
            'voids' => DB::table('voided_documents')->where('invoice_id', $id)->orderBy('created_at')->get()->map(fn ($v) => InvoiceVoiding::view($v))->all(),
            'attempts' => DB::table('invoice_attempts')->leftJoin('users', 'users.id', '=', 'invoice_attempts.actor_id')->where('invoice_id', $id)->orderBy('invoice_attempts.id')
                ->get(['invoice_attempts.*', 'users.email'])->map(fn ($a) => ['action' => $a->action, 'result' => $a->result, 'sunatCode' => $a->sunat_code, 'message' => $a->message, 'actor' => $a->email, 'at' => OrderService::iso($a->created_at)])->all(),
            'lines' => array_map(fn ($l) => ['lineNumber' => (int) $l['line_number'], 'productId' => $l['product_id'], 'sku' => $l['sku'], 'description' => $l['description'], 'unitCode' => $l['unit_code'], 'quantity' => (int) $l['quantity'],
                'unitPrice' => bcadd((string) $l['unit_price'], '0', 2), 'lineTotal' => bcadd((string) $l['line_total'], '0', 2), 'taxableAmount' => bcadd((string) $l['taxable_amount'], '0', 2), 'taxAmount' => bcadd((string) $l['tax_amount'], '0', 2)], $this->linesOf($id))];
    }
}
