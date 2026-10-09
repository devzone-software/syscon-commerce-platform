<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController
{
    public function __construct(private OrderService $orders, private UblXmlBuilder $builder, private SunatGateway $gateway) {}

    public function store(Request $r)
    {
        $data = $r->validate(['orderId' => 'required|uuid', 'type' => 'required|in:01,03,07,08', 'serialNumber' => ['required', 'regex:/^[FB][A-Z0-9]{3}-[1-9][0-9]{0,7}$/'], 'customerDocumentType' => 'required|string|max:2', 'customerDocumentNumber' => 'required|string|max:20', 'customerName' => 'required|string|max:255', 'referencedSerialNumber' => 'nullable|string|max:20']);
        abort_if(($data['type'] === '01' && ! str_starts_with($data['serialNumber'], 'F')) || ($data['type'] === '03' && ! str_starts_with($data['serialNumber'], 'B')), 422, 'Serie incompatible');

        return DB::transaction(function () use ($data) {
            $o = DB::table('orders')->where('id', $data['orderId'])->lockForUpdate()->first();
            abort_unless($o, 404, 'Pedido no encontrado');
            abort_unless(in_array($o->status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'], true), 409, 'Pedido no pagado');
            abort_if(DB::table('invoices')->where('serial_number', $data['serialNumber'])->exists(), 409, 'Serie ya registrada');
            if (in_array($data['type'], ['01', '03'], true)) {
                abort_if(DB::table('invoices')->where('order_id', $o->id)->whereIn('type', ['01', '03'])->exists(), 409, 'Pedido ya facturado');
            } else {
                abort_if(empty($data['referencedSerialNumber']), 422, 'Documento de referencia requerido');
                $original = DB::table('invoices')->where('serial_number', $data['referencedSerialNumber'])->first();
                abort_unless($original && $original->order_id === $o->id && $original->status === 'ACCEPTED' && in_array($original->type, ['01', '03'], true), 409, 'Comprobante de referencia no aceptado');
            }
            $id = (string) Str::uuid();
            DB::table('invoices')->insert(['id' => $id, 'order_id' => $o->id, 'type' => $data['type'], 'serial_number' => $data['serialNumber'], 'status' => 'DRAFT', 'customer_document_type' => $data['customerDocumentType'], 'customer_document_number' => $data['customerDocumentNumber'], 'customer_name' => $data['customerName'], 'referenced_serial_number' => $data['referencedSerialNumber'] ?? null, 'total' => $o->total, 'created_at' => now(), 'updated_at' => now()]);
            $invoice = DB::table('invoices')->where('id', $id)->first();
            DB::table('xml_documents')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $id, 'generated_xml' => $this->builder->build($invoice, $this->orders->get($o->id))]);

            return Api::ok('Comprobante creado', $this->view($id));
        }, 3);
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

        return ['id' => $i->id, 'orderId' => $i->order_id, 'type' => $i->type, 'serialNumber' => $i->serial_number, 'status' => $i->status, 'error' => $i->error, 'sunatCode' => $c?->response_code, 'sunatDescription' => $c?->description];
    }
}
