<?php

namespace App\Modules\Orders;

use App\Modules\Notifications\Notifier;
use App\Modules\Payments\PaymentController;
use App\Support\Api;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bandejas del backoffice: pedidos y pagos de todos los clientes, paginados y filtrables,
 * y el detalle relacionado de un pedido (cliente, pagos, comprobantes e historial).
 */
class AdminOrderController
{
    public const STATUSES = ['PAYMENT_PENDING', 'PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'EXPIRED'];

    public function __construct(private OrderService $orders) {}

    public function index(Request $r)
    {
        $data = $r->validate([
            'status' => 'nullable|string|max:200', 'q' => 'nullable|string|max:120',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100',
        ]);
        $statuses = $this->statuses($data['status'] ?? null);
        $base = DB::table('orders')->join('users', 'users.id', '=', 'orders.customer_id');
        $this->search($base, $data);
        // Conteo por estado con los mismos filtros de búsqueda y fechas, para las pestañas.
        $counts = (clone $base)->select('orders.status', DB::raw('count(*) as total'))->groupBy('orders.status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
        if ($statuses) {
            $base->whereIn('orders.status', $statuses);
        }
        $perPage = (int) ($data['perPage'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $base)->count();
        $rows = $base->select('orders.*', 'users.email as customer_email', 'users.first_name', 'users.last_name')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')->forPage($page, $perPage)->get();
        $ids = $rows->pluck('id')->all();
        $payments = DB::table('payments')->whereIn('order_id', $ids)->orderByDesc('created_at')->get()->groupBy('order_id');
        $invoices = DB::table('invoices')->whereIn('order_id', $ids)->whereIn('type', ['01', '03'])->get()->keyBy('order_id');

        return Api::ok('Pedidos', [
            'items' => $rows->map(fn ($o) => $this->summary($o, $payments->get($o->id)?->first(), $invoices->get($o->id)))->all(),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
            'counts' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $counts[$s] ?? 0])->all(),
        ]);
    }

    public function show(string $id)
    {
        $order = $this->orders->get($id);
        $customer = DB::table('users')->where('id', $order['customerId'])->first(['id', 'email', 'first_name', 'last_name', 'phone', 'document_type', 'document_number']);
        $actors = DB::table('order_events')->where('order_id', $id)->whereNotNull('actor_id')->pluck('actor_id')
            ->merge(DB::table('payments')->where('order_id', $id)->whereNotNull('confirmed_by')->pluck('confirmed_by'))->unique()->values();
        $emails = DB::table('users')->whereIn('id', $actors)->pluck('email', 'id');

        return Api::ok('Pedido', $order + [
            'customer' => $customer ? ['id' => $customer->id, 'email' => $customer->email, 'name' => self::name($customer->first_name, $customer->last_name), 'phone' => $customer->phone, 'documentType' => $customer->document_type, 'documentNumber' => $customer->document_number] : null,
            'payments' => DB::table('payments')->where('order_id', $id)->orderBy('created_at')->get()
                ->map(fn ($p) => PaymentController::view($p) + ['confirmedBy' => $p->confirmed_by ? ($emails[$p->confirmed_by] ?? null) : null])->all(),
            'invoices' => DB::table('invoices')->where('order_id', $id)->orderBy('created_at')->get()
                ->map(fn ($i) => ['id' => $i->id, 'type' => $i->type, 'serialNumber' => $i->serial_number, 'status' => $i->status, 'total' => bcadd((string) $i->total, '0', 2), 'createdAt' => OrderService::iso($i->created_at)])->all(),
            'notifications' => DB::table('notifications')->where('order_id', $id)->get()
                ->sortBy([['created_at', 'asc'], fn ($a, $b) => array_search($a->type, Notifier::TYPES) <=> array_search($b->type, Notifier::TYPES)])
                ->map(fn ($n) => Notifier::view($n))->values()->all(),
            'events' => DB::table('order_events')->where('order_id', $id)->orderBy('id')->get()
                ->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'reference' => $e->reference, 'actor' => $e->actor_id ? ($emails[$e->actor_id] ?? null) : null, 'system' => $e->actor_id === null, 'at' => OrderService::iso($e->created_at)])->all(),
        ]);
    }

    /** Reenvía un correo al cliente; no repite ninguna operación del pedido. */
    public function resendNotification(Request $r, string $id, Notifier $notifier)
    {
        return Api::ok('Aviso reenviado', $notifier->resend($id, $r->user()->id));
    }

    /** Bandeja de pagos: por defecto los pendientes de verificar, los más antiguos primero. */
    public function payments(Request $r)
    {
        $data = $r->validate(['status' => 'nullable|in:PENDING,APPROVED,CANCELLED,REJECTED,REFUND_REQUIRED', 'q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100']);
        $status = $data['status'] ?? 'PENDING';
        $base = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->join('users', 'users.id', '=', 'payments.customer_id')
            ->where('payments.status', $status);
        if ($q = trim($data['q'] ?? '')) {
            $base->where(function (Builder $w) use ($q) {
                $like = '%'.self::escapeLike($q).'%';
                $w->where('payments.id', 'like', self::escapeLike(strtolower($q)).'%')->orWhere('orders.id', 'like', self::escapeLike(strtolower($q)).'%')
                    ->orWhere('users.email', 'like', $like)->orWhere('payments.external_reference', 'like', $like);
            });
        }
        $perPage = (int) ($data['perPage'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $base)->count();
        $rows = $base->select('payments.*', 'orders.status as order_status', 'orders.expires_at as order_expires_at', 'users.email as customer_email')
            ->orderBy($status === 'PENDING' ? 'payments.created_at' : 'payments.updated_at', $status === 'PENDING' ? 'asc' : 'desc')->orderBy('payments.id')->forPage($page, $perPage)->get();

        return Api::ok('Pagos', [
            'items' => $rows->map(fn ($p) => PaymentController::view($p) + ['customerEmail' => $p->customer_email, 'orderStatus' => $p->order_status, 'orderExpiresAt' => $p->order_status === 'PAYMENT_PENDING' ? OrderService::iso($p->order_expires_at) : null])->all(),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
        ]);
    }

    private function statuses(?string $value): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        abort_if(array_diff($list, self::STATUSES) !== [], 422, 'Estado de pedido desconocido');

        return $list;
    }

    /** Busca por inicio del ID, correo, nombre del cliente o de facturación, o número de documento. */
    private function search(Builder $query, array $data): void
    {
        if ($q = trim($data['q'] ?? '')) {
            $query->where(function (Builder $w) use ($q) {
                $like = '%'.self::escapeLike($q).'%';
                $w->where('orders.id', 'like', self::escapeLike(strtolower($q)).'%')
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('orders.billing_name', 'like', $like)
                    ->orWhere('orders.contact_name', 'like', $like)
                    ->orWhere('orders.billing_document_number', strtoupper(str_replace(' ', '', $q)));
            });
        }
        if (! empty($data['from'])) {
            $query->where('orders.created_at', '>=', $data['from'].' 00:00:00');
        }
        if (! empty($data['to'])) {
            $query->where('orders.created_at', '<=', $data['to'].' 23:59:59');
        }
    }

    private function summary(object $o, ?object $payment, ?object $invoice): array
    {
        return [
            'id' => $o->id, 'status' => $o->status, 'total' => bcadd((string) $o->total, '0', 2),
            'createdAt' => OrderService::iso($o->created_at), 'expiresAt' => $o->status === 'PAYMENT_PENDING' ? OrderService::iso($o->expires_at) : null,
            'customer' => ['id' => $o->customer_id, 'email' => $o->customer_email, 'name' => self::name($o->first_name, $o->last_name)],
            'billing' => $o->billing_type ? ['type' => $o->billing_type, 'documentNumber' => $o->billing_document_number, 'name' => $o->billing_name] : null,
            'deliveryMethod' => $o->delivery_method,
            'payment' => $payment ? ['id' => $payment->id, 'status' => $payment->status] : null,
            'invoice' => $invoice ? ['id' => $invoice->id, 'serialNumber' => $invoice->serial_number, 'status' => $invoice->status] : null,
        ];
    }

    private static function name(?string $first, ?string $last): ?string
    {
        $name = trim(($first ?? '').' '.($last ?? ''));

        return $name === '' ? null : $name;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
