<?php

namespace App\Modules\Reports;

use App\Modules\Orders\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes operativos. Reglas:
 * - Los períodos son días calendario de Lima (America/Lima), ambos extremos incluidos.
 * - Una venta es un pedido cuyo pago se confirmó en el período (evento PAYMENT_CONFIRMED); se
 *   cuenta por su total, que incluye IGV y envío. Los pedidos cancelados o vencidos nunca se
 *   pagaron (solo un pedido impago puede cancelarse), así que no restan ventas: se informan aparte.
 * - Lo facturado neto de un pedido es la suma de sus boletas/facturas aceptadas, más sus notas de
 *   débito aceptadas, menos sus notas de crédito aceptadas.
 * - Un pedido vendido concilia si su pago aprobado y lo facturado neto son iguales a su total.
 */
class ReportService
{
    public const TZ = 'America/Lima';

    /** @return array{0: Carbon, 1: Carbon} Límites en la zona de la base de datos. */
    public static function bounds(string $from, string $to): array
    {
        $tz = config('app.timezone');

        return [Carbon::parse($from, self::TZ)->startOfDay()->setTimezone($tz), Carbon::parse($to, self::TZ)->endOfDay()->setTimezone($tz)];
    }

    public function summary(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $sales = $this->sales($start, $end);
        $days = [];
        for ($day = Carbon::parse($from, self::TZ); $day->toDateString() <= $to; $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'orders' => 0, 'total' => '0.00'];
        }
        foreach ($sales as $s) {
            $key = $s['paidAt']->toDateString();
            $days[$key]['orders']++;
            $days[$key]['total'] = bcadd($days[$key]['total'], $s['total'], 2);
        }
        $released = DB::table('order_events')->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->whereIn('order_events.type', ['STATUS_CHANGED', 'EXPIRED'])->whereIn('order_events.to_status', ['CANCELLED', 'EXPIRED'])
            ->whereBetween('order_events.created_at', [$start, $end])->get(['order_events.to_status', 'orders.total']);
        $pending = DB::table('orders')->where('status', 'PAYMENT_PENDING');
        $invoices = DB::table('invoices')->whereBetween('created_at', [$start, $end])->get(['type', 'status', 'total']);
        $mismatched = $sales->filter(fn ($s) => ! $s['reconciled']);

        return [
            'period' => ['from' => $from, 'to' => $to, 'timezone' => self::TZ],
            'sales' => [
                'orders' => $sales->count(), 'total' => self::sum($sales, 'total'), 'taxableAmount' => self::sum($sales, 'taxableAmount'),
                'taxAmount' => self::sum($sales, 'taxAmount'), 'deliveryTotal' => self::sum($sales, 'deliveryCost'),
                'averageTicket' => $sales->count() ? bcdiv(self::sum($sales, 'total'), (string) $sales->count(), 2) : '0.00',
                'byDay' => array_values($days),
                'byChannel' => collect(['WEB', 'STORE'])->mapWithKeys(fn ($c) => [$c => ['orders' => $sales->where('channel', $c)->count(), 'total' => self::sum($sales->where('channel', $c), 'total')]])->all(),
                // Lo cobrado por medio de pago (MANUAL es el pago por transferencia de la web).
                'byPaymentMethod' => $sales->flatMap(fn ($s) => $s['payments'])->groupBy('method')->map(fn ($p, $m) => ['method' => $m, 'payments' => $p->count(), 'total' => self::sum($p, 'total')])->values()->all(),
            ],
            'releasedOrders' => collect(['CANCELLED', 'EXPIRED'])->mapWithKeys(fn ($status) => [$status => [
                'orders' => $released->where('to_status', $status)->count(),
                'total' => self::sum($released->where('to_status', $status)->map(fn ($r) => ['total' => (string) $r->total]), 'total'),
            ]])->all(),
            // Fotografía actual, no depende del período.
            'pendingPayments' => [
                'orders' => (clone $pending)->count(), 'total' => bcadd((string) ((clone $pending)->sum('total') ?: '0'), '0', 2),
                'oldestCreatedAt' => OrderService::iso((clone $pending)->min('created_at')),
                'expiringIn24h' => (clone $pending)->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDay())->count(),
            ],
            'reservations' => [
                'units' => (int) DB::table('products')->sum('reserved'),
                'products' => DB::table('products')->where('reserved', '>', 0)->count(),
                'value' => bcadd((string) (DB::table('products')->where('reserved', '>', 0)->selectRaw('SUM(price * reserved) as v')->value('v') ?: '0'), '0', 2),
            ],
            'invoices' => collect(['01', '03', '07', '08'])->map(fn ($type) => [
                'type' => $type,
                'byStatus' => collect(['DRAFT', 'FAILED', 'PENDING_CHECK', 'REJECTED', 'ACCEPTED', 'VOIDING', 'VOIDED'])->mapWithKeys(fn ($st) => [$st => $invoices->where('type', $type)->where('status', $st)->count()])->all(),
                'acceptedTotal' => self::sum($invoices->where('type', $type)->where('status', 'ACCEPTED')->map(fn ($i) => ['total' => (string) $i->total]), 'total'),
            ])->all(),
            'reconciliation' => [
                'paidOrdersTotal' => self::sum($sales, 'total'),
                'approvedPaymentsTotal' => self::sum($sales, 'paidAmount'),
                'netInvoicedTotal' => self::sum($sales, 'netInvoiced'),
                'ordersWithoutInvoice' => $sales->filter(fn ($s) => in_array('NOT_INVOICED', $s['issues'], true))->count(),
                'mismatchedOrders' => $mismatched->count(),
            ],
        ];
    }

    /** Detalle por pedido vendido en el período, del más reciente al más antiguo. */
    public function rows(string $from, string $to, bool $onlyMismatched = false): Collection
    {
        [$start, $end] = self::bounds($from, $to);
        $rows = $this->sales($start, $end)->sortByDesc(fn ($s) => $s['paidAt']->getTimestamp())->values();

        return $onlyMismatched ? $rows->filter(fn ($s) => ! $s['reconciled'])->values() : $rows;
    }

    /** Pedidos con pago confirmado en el período, con su pago y sus comprobantes. */
    private function sales(Carbon $start, Carbon $end): Collection
    {
        $paid = DB::table('order_events')->where('type', 'PAYMENT_CONFIRMED')->whereBetween('created_at', [$start, $end])
            ->select('order_id', DB::raw('MIN(created_at) as paid_at'))->groupBy('order_id')->pluck('paid_at', 'order_id');
        if ($paid->isEmpty()) {
            return collect();
        }
        $ids = $paid->keys()->all();
        $orders = DB::table('orders')->join('users', 'users.id', '=', 'orders.customer_id')->whereIn('orders.id', $ids)
            ->get(['orders.*', 'users.email'])->keyBy('id');
        $payments = DB::table('payments')->whereIn('order_id', $ids)->where('status', 'APPROVED')->get()->groupBy('order_id');
        $invoices = DB::table('invoices')->whereIn('order_id', $ids)->orderBy('created_at')->get()->groupBy('order_id');

        return $paid->map(function ($paidAt, $id) use ($orders, $payments, $invoices) {
            $o = $orders[$id];
            $docs = $invoices->get($id, collect());
            $net = '0.00';
            foreach ($docs->where('status', 'ACCEPTED') as $d) {
                $net = $d->type === '07' ? bcsub($net, (string) $d->total, 2) : bcadd($net, (string) $d->total, 2);
            }
            $paidAmount = self::sum(($payments->get($id) ?? collect())->map(fn ($p) => ['total' => (string) $p->amount]), 'total');
            $total = bcadd((string) $o->total, '0', 2);
            $issues = array_values(array_filter([
                bccomp($paidAmount, $total, 2) !== 0 ? 'PAYMENT_MISMATCH' : null,
                $docs->where('status', 'ACCEPTED')->whereIn('type', ['01', '03'])->isEmpty() ? 'NOT_INVOICED' : null,
                bccomp($net, $total, 2) !== 0 && $docs->where('status', 'ACCEPTED')->isNotEmpty() ? 'INVOICED_MISMATCH' : null,
            ]));

            return [
                'orderId' => $id, 'channel' => $o->channel ?? 'WEB', 'paidAt' => Carbon::parse($paidAt, config('app.timezone'))->setTimezone(self::TZ), 'status' => $o->status,
                'customerEmail' => $o->email, 'billingName' => $o->billing_name, 'billingDocument' => $o->billing_document_number,
                'total' => $total, 'taxableAmount' => bcadd((string) $o->taxable_amount, '0', 2), 'taxAmount' => bcadd((string) $o->tax_amount, '0', 2),
                'deliveryCost' => bcadd((string) ($o->delivery_cost ?? '0'), '0', 2),
                'paidAmount' => $paidAmount, 'payments' => ($payments->get($id) ?? collect())->map(fn ($p) => ['method' => $p->provider, 'total' => bcadd((string) $p->amount, '0', 2)])->values()->all(),
                'paymentReferences' => ($payments->get($id) ?? collect())->pluck('external_reference')->filter()->values()->all(),
                'invoices' => $docs->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'serialNumber' => $d->serial_number, 'status' => $d->status, 'total' => bcadd((string) $d->total, '0', 2)])->values()->all(),
                'netInvoiced' => $net,
                // PAYMENT_MISMATCH: el pago aprobado no es el total; NOT_INVOICED: sin boleta/factura
                // aceptada; INVOICED_MISMATCH: notas o comprobantes no cuadran con el total.
                'issues' => $issues, 'reconciled' => $issues === [],
            ];
        })->values();
    }

    public static function view(array $row): array
    {
        return ['paidAt' => $row['paidAt']->toIso8601String()] + $row;
    }

    private static function sum(Collection $items, string $key): string
    {
        return $items->reduce(fn ($sum, $i) => bcadd($sum, (string) $i[$key], 2), '0.00');
    }
}
