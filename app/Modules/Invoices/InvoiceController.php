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

    public function __construct(private OrderService $orders, private UblXmlBuilder $builder, private SunatGateway $gateway, private InvoiceNumbering $numbering) {}

    /**
     * Crea el borrador con la serie y el correlativo que asigna el backend. Si el pedido ya tiene
     * su boleta o factura del mismo tipo, devuelve esa (un reintento no emite un segundo documento).
     */
    public function store(Request $r)
    {
        $data = $r->validate(['orderId' => 'required|uuid', 'type' => 'required|in:01,03,07,08', 'series' => ['nullable', self::SERIES], 'customerDocumentType' => 'nullable|in:'.implode(',', BillingData::DOCUMENT_TYPES), 'customerDocumentNumber' => 'nullable|string|max:15', 'customerName' => 'nullable|string|max:255', 'referencedSerialNumber' => ['nullable', self::SERIAL]]);
        $main = in_array($data['type'], ['01', '03'], true);

        return DB::transaction(function () use ($data, $main) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless(in_array($o->status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'], true), 409, 'Pedido no pagado');
            if ($main) {
                $existing = DB::table('invoices')->where('order_id', $o->id)->whereIn('type', ['01', '03'])->first();
                if ($existing) {
                    abort_unless($existing->type === $data['type'], 409, 'Pedido ya facturado');

                    return Api::ok('Comprobante ya registrado', $this->view($existing->id));
                }
                $prefix = null;
            } else {
                abort_if(empty($data['referencedSerialNumber']), 422, 'Documento de referencia requerido');
                $original = DB::table('invoices')->where('serial_number', $data['referencedSerialNumber'])->first();
                abort_unless($original && $original->order_id === $o->id && $original->status === 'ACCEPTED' && in_array($original->type, ['01', '03'], true), 409, 'Comprobante de referencia no aceptado');
                // La nota usa una serie con la misma letra que el comprobante que modifica.
                $prefix = $original->serial_number[0];
                abort_if(isset($data['series']) && $data['series'][0] !== $prefix, 422, 'La serie de la nota debe empezar con '.$prefix);
            }
            // Sin datos explícitos se usa la copia guardada en el pedido al comprar.
            $data = $this->customer($data, $o);
            $serial = $this->numbering->next($data['type'], $data['series'] ?? null, $prefix);
            $id = (string) Str::uuid();
            DB::table('invoices')->insert(['id' => $id, 'order_id' => $o->id, 'type' => $data['type'], 'serial_number' => $serial, 'status' => 'DRAFT', 'customer_document_type' => $data['customerDocumentType'], 'customer_document_number' => $data['customerDocumentNumber'], 'customer_name' => $data['customerName'], 'referenced_serial_number' => $main ? null : $data['referencedSerialNumber'], 'total' => $o->total, 'created_at' => now(), 'updated_at' => now()]);
            $this->generateXml($id);

            return Api::ok('Comprobante creado', $this->view($id));
        }, 3);
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
        DB::table('xml_documents')->updateOrInsert(['invoice_id' => $id], ['id' => DB::table('xml_documents')->where('invoice_id', $id)->value('id') ?? (string) Str::uuid(), 'generated_xml' => $this->builder->build($invoice, $this->orders->get($invoice->order_id)), 'signed_xml' => null]);
    }

    public function show(string $id)
    {
        return Api::ok('Comprobante', $this->view($id));
    }

    public function submit(string $id)
    {
        return DB::transaction(function () use ($id) {
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
            'referencedSerialNumber' => $i->referenced_serial_number, 'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at)];
    }
}
