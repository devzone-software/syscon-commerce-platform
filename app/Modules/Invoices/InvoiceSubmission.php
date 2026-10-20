<?php

namespace App\Modules\Invoices;

use App\Modules\Notifications\Notifier;
use App\Modules\Orders\OrderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Envío y conciliación de comprobantes con SUNAT. Reglas:
 * - Una vez firmado, siempre se reenvía el mismo XML: si SUNAT ya lo tenía responde 1033 y se
 *   toma como aceptado, así un reintento nunca registra dos documentos.
 * - Si la conexión se corta o vence el tiempo, el comprobante queda PENDING_CHECK (pudo llegar):
 *   se concilia consultando su CDR (si hay servicio de consulta) o reenviando el mismo XML.
 * - Cada intento queda en invoice_attempts como evidencia.
 */
class InvoiceSubmission
{
    /** SUNAT: "El comprobante fue registrado previamente con otros datos" o duplicado. */
    public const ALREADY_REGISTERED = ['1033', '1032'];

    public function __construct(private SunatGateway $gateway, private OrderService $orders, private Notifier $notifier, private UblXmlBuilder $builder) {}

    /** Envía (o concilia) el comprobante; devuelve false si su estado no admite la acción. */
    public function run(string $id, ?string $actor, string $action = 'SUBMIT'): void
    {
        DB::transaction(function () use ($id, $actor, $action) {
            $invoice = DB::table('invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Comprobante no encontrado');
            if (in_array($invoice->status, ['ACCEPTED', 'REJECTED'], true)) {
                return;
            }
            abort_unless(in_array($invoice->status, ['DRAFT', 'FAILED', 'PENDING_CHECK'], true), 409, 'Estado de comprobante inválido');
            // Lo que pudo llegar (PENDING_CHECK) se concilia siempre; lo no enviado respeta el plazo.
            $deadline = self::deadline($invoice);
            abort_if($invoice->status !== 'PENDING_CHECK' && $deadline !== null && now('America/Lima')->toDateString() > $deadline, 422,
                'Plazo de envío vencido el '.$deadline.': SUNAT lo rechazaría, por eso no se envía');
            abort_unless($this->gateway->configured(), 503, 'Credenciales SUNAT no configuradas');
            try {
                $signed = $this->signed($invoice);
                $name = config('commerce.sunat.ruc').'-'.$invoice->type.'-'.$invoice->serial_number;
                // Conciliar con el servicio de consulta evita reenviar; sin él, el reenvío es seguro (1033).
                if ($action === 'RECONCILE' && $this->gateway->canConsult()) {
                    $status = $this->gateway->statusCdr($invoice->type, $invoice->serial_number);
                    if ($status['cdr'] === null) {
                        // SUNAT no lo tiene: no llegó, se puede enviar de nuevo.
                        $this->fail($invoice, $actor, $action, 'FAILED', $status['code'], 'SUNAT no registra el comprobante: '.$status['message']);

                        return;
                    }
                    $this->accept($invoice, $actor, $action, $status['cdr']);

                    return;
                }
                $this->accept($invoice, $actor, $action, $this->gateway->sendBill($name, $signed));
            } catch (SunatFault $e) {
                if (in_array($e->sunatCode, self::ALREADY_REGISTERED, true)) {
                    $this->registered($invoice, $actor, $action, $e);
                } else {
                    // Rechazo por contenido: el número queda usado y se corrige con otro comprobante o nota.
                    $this->fail($invoice, $actor, $action, $e->rejects() ? 'REJECTED' : 'FAILED', $e->sunatCode, $e->getMessage());
                }
            } catch (ConnectionException $e) {
                $this->fail($invoice, $actor, $action, 'PENDING_CHECK', null, 'Sin respuesta de SUNAT; se debe conciliar antes de reenviar. '.$e->getMessage());
            } catch (\Throwable $e) {
                $this->fail($invoice, $actor, $action, 'FAILED', null, $e->getMessage());
            }
        });
    }

    /** Último día (Y-m-d, hora de Lima) para enviar el comprobante; null si no hay plazo configurado. */
    public static function deadline(object $invoice): ?string
    {
        $days = (string) config('commerce.sunat.send_max_days.'.$invoice->serial_number[0], '');
        if (! ctype_digit($days)) {
            return null;
        }

        return Carbon::parse($invoice->created_at)->setTimezone('America/Lima')->addDays((int) $days)->toDateString();
    }

    /** Concilia los pendientes; lo ejecuta el scheduler. */
    public function reconcilePending(): int
    {
        $count = 0;
        foreach (DB::table('invoices')->where('status', 'PENDING_CHECK')->where('updated_at', '<=', now()->subMinutes(5))->orderBy('updated_at')->limit(20)->pluck('id') as $id) {
            $this->run($id, null, 'RECONCILE');
            $count++;
        }

        return $count;
    }

    /** Hasta la primera firma el XML se regenera con el emisor configurado; luego es inmutable. */
    private function signed(object $invoice): string
    {
        $doc = DB::table('xml_documents')->where('invoice_id', $invoice->id)->first();
        if ($doc?->signed_xml) {
            return $doc->signed_xml;
        }
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('line_number')->get()->map(fn ($l) => (array) $l)->all();
        $reference = $invoice->referenced_serial_number ? DB::table('invoices')->where('serial_number', $invoice->referenced_serial_number)->first() : null;
        $xml = $this->builder->build($invoice, $lines, $reference);
        $signed = $this->gateway->sign($xml);
        DB::table('xml_documents')->updateOrInsert(['invoice_id' => $invoice->id], ['id' => $doc?->id ?? (string) Str::uuid(), 'generated_xml' => $xml, 'signed_xml' => $signed]);

        return $signed;
    }

    private function accept(object $invoice, ?string $actor, string $action, string $cdr): void
    {
        [$code, $description] = $this->gateway->parseCdr($cdr);
        $existing = DB::table('cdr_responses')->where('invoice_id', $invoice->id)->first();
        DB::table('cdr_responses')->updateOrInsert(['invoice_id' => $invoice->id], ['id' => $existing?->id ?? (string) Str::uuid(), 'zip_base64' => $cdr, 'response_code' => $code, 'description' => $description]);
        $status = $code === '0' ? 'ACCEPTED' : 'REJECTED';
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'error' => null, 'updated_at' => now()]);
        $this->attempt($invoice->id, $actor, $action, $status, $code, $description);
        if ($status === 'ACCEPTED') {
            $this->accepted($invoice, $actor);
        }
    }

    /** SUNAT ya tenía este mismo documento: queda aceptado aunque su CDR original no esté disponible. */
    private function registered(object $invoice, ?string $actor, string $action, SunatFault $e): void
    {
        $existing = DB::table('cdr_responses')->where('invoice_id', $invoice->id)->first();
        if (! $existing) {
            DB::table('cdr_responses')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'zip_base64' => '', 'response_code' => '0',
                'description' => 'Registrado previamente en SUNAT ('.$e->sunatCode.'); CDR original no disponible.']);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'ACCEPTED', 'error' => null, 'updated_at' => now()]);
        $this->attempt($invoice->id, $actor, $action, 'ACCEPTED', $e->sunatCode, $e->getMessage());
        $this->accepted($invoice, $actor);
    }

    private function fail(object $invoice, ?string $actor, string $action, string $status, ?string $code, string $message): void
    {
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'error' => mb_substr($message, 0, 1000), 'updated_at' => now()]);
        $this->attempt($invoice->id, $actor, $action, $status, $code, $message);
    }

    private function accepted(object $invoice, ?string $actor): void
    {
        // Las notas no mueven stock ni el estado del pedido: la devolución física la registra
        // inventario. Quedan en el historial para conciliar lo cobrado con lo facturado.
        $event = ['01' => 'INVOICE_ACCEPTED', '03' => 'INVOICE_ACCEPTED', '07' => 'CREDIT_NOTE_ACCEPTED', '08' => 'DEBIT_NOTE_ACCEPTED'][$invoice->type];
        $this->orders->record($invoice->order_id, $actor, $event, null, null, $invoice->serial_number);
        $this->notifier->queue($invoice->order_id, 'INVOICE_AVAILABLE', $invoice->id);
    }

    private function attempt(string $id, ?string $actor, string $action, string $result, ?string $code, ?string $message): void
    {
        DB::table('invoice_attempts')->insert(['invoice_id' => $id, 'actor_id' => $actor, 'action' => $action, 'result' => $result,
            'sunat_code' => $code, 'message' => $message === null ? null : mb_substr($message, 0, 1000), 'created_at' => now()]);
    }
}
