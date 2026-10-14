<?php

namespace App\Modules\Invoices;

use App\Modules\Orders\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anulación de comprobantes aceptados sin nota de crédito. Reglas:
 * - Serie F (facturas y sus notas): comunicación de baja RA. Serie B (boletas y sus notas):
 *   resumen diario RC con estado 3.
 * - Solo comprobantes ACCEPTED, sin notas vigentes que los modifiquen y emitidos hace como máximo
 *   MAX_DAYS días; fuera de plazo se usa una nota de crédito.
 * - Mientras SUNAT procesa el ticket el comprobante queda VOIDING; aceptada la anulación pasa a
 *   VOIDED y el pedido puede facturarse de nuevo. Si SUNAT la rechaza vuelve a ACCEPTED.
 */
class InvoiceVoiding
{
    public const MAX_DAYS = 7;

    public function __construct(private SunatGateway $gateway, private SummaryXmlBuilder $builder, private OrderService $orders) {}

    public function void(string $invoiceId, ?string $actor, string $reason): string
    {
        $voidId = DB::transaction(function () use ($invoiceId, $actor, $reason) {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404, 'Comprobante no encontrado');
            abort_unless($invoice->status === 'ACCEPTED', 409, 'Solo se anulan comprobantes aceptados por SUNAT');
            $notes = DB::table('invoices')->where('referenced_serial_number', $invoice->serial_number)->whereNotIn('status', ['REJECTED', 'VOIDED'])->exists();
            abort_if($notes, 409, 'El comprobante tiene notas vigentes; anúlalas primero o emite una nota de crédito');
            $issued = Carbon::parse($invoice->created_at)->setTimezone('America/Lima')->startOfDay();
            $today = now('America/Lima')->startOfDay();
            abort_if($issued->diffInDays($today) > self::MAX_DAYS, 422, 'Plazo de anulación vencido ('.self::MAX_DAYS.' días); emite una nota de crédito');
            abort_unless($this->gateway->configured(), 503, 'Credenciales SUNAT no configuradas');
            $kind = $invoice->serial_number[0] === 'F' ? 'RA' : 'RC';
            $prefix = $kind.'-'.$today->format('Ymd').'-';
            $last = DB::table('voided_documents')->where('identifier', 'like', $prefix.'%')->pluck('identifier')->map(fn ($i) => (int) substr($i, strlen($prefix)))->max() ?? 0;
            $id = (string) Str::uuid();
            $row = (object) ['id' => $id, 'identifier' => $prefix.($last + 1), 'reference_date' => $issued->toDateString(), 'issue_date' => $today->toDateString(), 'reason' => mb_substr(trim($reason), 0, 100)];
            $reference = $invoice->referenced_serial_number ? DB::table('invoices')->where('serial_number', $invoice->referenced_serial_number)->first() : null;
            $xml = $kind === 'RA' ? $this->builder->voided($row, $invoice) : $this->builder->summary($row, $invoice, $reference);
            DB::table('voided_documents')->insert(['id' => $id, 'invoice_id' => $invoice->id, 'actor_id' => $actor, 'kind' => $kind, 'identifier' => $row->identifier,
                'reference_date' => $row->reference_date, 'issue_date' => $row->issue_date, 'reason' => $row->reason, 'status' => 'FAILED', 'created_at' => now(), 'updated_at' => now()]);
            try {
                $signed = $this->gateway->sign($xml);
                $ticket = $this->gateway->sendSummary(config('commerce.sunat.ruc').'-'.$row->identifier, $signed);
                DB::table('voided_documents')->where('id', $id)->update(['signed_xml' => $signed, 'ticket' => $ticket, 'status' => 'SENT', 'updated_at' => now()]);
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOIDING', 'updated_at' => now()]);
            } catch (SunatFault $e) {
                DB::table('voided_documents')->where('id', $id)->update(['response_code' => $e->sunatCode, 'description' => $e->getMessage(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                DB::table('voided_documents')->where('id', $id)->update(['description' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
            }

            return $id;
        }, 3);
        // SUNAT suele procesar el ticket en segundos: se consulta una vez; si no, lo hará el cron.
        if (DB::table('voided_documents')->where('id', $voidId)->value('status') === 'SENT') {
            $this->check($voidId);
        }

        return $voidId;
    }

    /** Consulta el ticket y aplica el resultado; 98 (en proceso) lo deja para la siguiente consulta. */
    public function check(string $voidId, ?string $actor = null): void
    {
        DB::transaction(function () use ($voidId, $actor) {
            $void = DB::table('voided_documents')->where('id', $voidId)->lockForUpdate()->first();
            abort_unless($void, 404, 'Anulación no encontrada');
            if ($void->status !== 'SENT') {
                return;
            }
            try {
                $status = $this->gateway->ticketStatus($void->ticket);
            } catch (\Throwable $e) {
                DB::table('voided_documents')->where('id', $voidId)->update(['description' => mb_substr('Consulta del ticket: '.$e->getMessage(), 0, 1000), 'updated_at' => now()]);

                return;
            }
            if ($status['code'] === '98' || $status['cdr'] === null) {
                DB::table('voided_documents')->where('id', $voidId)->update(['description' => 'SUNAT aún procesa el ticket', 'updated_at' => now()]);

                return;
            }
            [$code, $description] = $this->gateway->parseCdr($status['cdr']);
            $accepted = $status['code'] === '0' && $code === '0';
            DB::table('voided_documents')->where('id', $voidId)->update(['status' => $accepted ? 'ACCEPTED' : 'REJECTED', 'cdr' => $status['cdr'],
                'response_code' => $code, 'description' => $description, 'updated_at' => now()]);
            $invoice = DB::table('invoices')->where('id', $void->invoice_id)->lockForUpdate()->first();
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $accepted ? 'VOIDED' : 'ACCEPTED', 'updated_at' => now()]);
            if ($accepted) {
                $this->orders->record($invoice->order_id, $actor ?? $void->actor_id, 'INVOICE_VOIDED', null, null, $invoice->serial_number.' · '.$void->identifier);
            }
        });
    }

    /** Tickets pendientes; lo ejecuta el scheduler junto con la conciliación de envíos. */
    public function checkPending(): int
    {
        $ids = DB::table('voided_documents')->where('status', 'SENT')->where('updated_at', '<=', now()->subMinute())->orderBy('updated_at')->limit(20)->pluck('id');
        foreach ($ids as $id) {
            $this->check($id);
        }

        return $ids->count();
    }

    public static function view(object $v): array
    {
        return ['id' => $v->id, 'kind' => $v->kind, 'identifier' => $v->identifier, 'reason' => $v->reason, 'ticket' => $v->ticket, 'status' => $v->status,
            'responseCode' => $v->response_code, 'description' => $v->description, 'createdAt' => OrderService::iso($v->created_at)];
    }
}
