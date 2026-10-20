<?php

namespace App\Modules\Users;

use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bitácora de las acciones del personal (audit:view): quién hizo qué, cuándo, desde qué IP y con qué resultado. */
class AuditController
{
    /** Ruta (sin el prefijo api/) → descripción legible. El primer patrón que coincide gana. */
    private const ACTIONS = [
        ['POST', '#^catalog/products$#', 'Guardó un producto'],
        ['PATCH', '#^orders/[^/]+/status/(\w+)$#', 'Cambió el estado de un pedido a %s'],
        ['POST', '#^payments/[^/]+/confirm$#', 'Confirmó un pago'],
        ['POST', '#^admin/sales$#', 'Registró una venta directa'],
        ['POST', '#^admin/cash/open$#', 'Abrió caja'],
        ['POST', '#^admin/cash/movements$#', 'Registró un movimiento de caja'],
        ['POST', '#^admin/cash/close$#', 'Cerró caja'],
        ['POST', '#^admin/notifications/[^/]+/resend$#', 'Reenvió una notificación'],
        ['POST', '#^admin/purchases$#', 'Registró una compra al por mayor'],
        ['POST', '#^admin/internal-uses$#', 'Retiró stock para uso interno'],
        ['POST', '#^admin/users$#', 'Creó una cuenta de administrador'],
        ['POST', '#^admin/users/[^/]+/validate$#', 'Validó una cuenta de administrador'],
        ['POST', '#^admin/users/[^/]+/suspend$#', 'Suspendió una cuenta de administrador'],
        ['POST', '#^admin/users/[^/]+/reactivate$#', 'Reactivó una cuenta de administrador'],
        ['POST', '#^admin/users/[^/]+/reset-password$#', 'Reseteó la contraseña de un administrador'],
        ['POST', '#^invoices/[^/]+/void/check$#', 'Consultó la baja de un comprobante'],
        ['POST', '#^invoices/[^/]+/void$#', 'Dio de baja un comprobante'],
        ['POST', '#^invoices/[^/]+/submit$#', 'Envió un comprobante a SUNAT'],
        ['POST', '#^invoices/[^/]+/reconcile$#', 'Concilió un comprobante con SUNAT'],
        ['POST', '#^invoices/series$#', 'Creó una serie de comprobantes'],
        ['POST', '#^invoices$#', 'Generó un comprobante'],
        ['PATCH', '#^invoices#', 'Editó un comprobante o serie'],
        ['POST', '#^suppliers#', 'Gestionó proveedores'],
        ['POST', '#^auth/password$#', 'Cambió su contraseña'],
    ];

    public function index(Request $r)
    {
        $data = $r->validate(['userId' => 'nullable|uuid', 'role' => 'nullable|in:SUPERADMIN,ADMIN', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.user_id');
        if (! empty($data['userId'])) {
            $query->where('audit_logs.user_id', $data['userId']);
        }
        if (! empty($data['role'])) {
            $query->where('audit_logs.role', $data['role']);
        }
        if (! empty($data['from'])) {
            $query->where('audit_logs.created_at', '>=', date('Y-m-d 00:00:00', strtotime($data['from'])));
        }
        if (! empty($data['to'])) {
            $query->where('audit_logs.created_at', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));
        }
        $perPage = (int) ($data['perPage'] ?? 30);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('audit_logs.id')->forPage($page, $perPage)
            ->get(['audit_logs.*', 'users.email', 'users.first_name', 'users.last_name'])
            ->map(fn ($l) => ['id' => $l->id, 'userId' => $l->user_id, 'email' => $l->email, 'name' => trim(($l->first_name ?? '').' '.($l->last_name ?? '')) ?: null,
                'role' => $l->role, 'action' => self::describe($l->method, $l->path), 'method' => $l->method, 'path' => '/'.$l->path,
                'status' => $l->status, 'ok' => $l->status < 400, 'ip' => $l->ip, 'at' => OrderService::iso($l->created_at)])->all();

        return Api::ok('Bitácora', ['items' => $items, 'page' => $page, 'perPage' => $perPage, 'total' => $total]);
    }

    public static function describe(string $method, string $path): string
    {
        $path = preg_replace('#^api/#', '', $path);
        foreach (self::ACTIONS as [$verb, $pattern, $label]) {
            if ($verb === $method && preg_match($pattern, $path, $m)) {
                return isset($m[1]) ? sprintf($label, $m[1]) : $label;
            }
        }

        return $method.' /'.$path;
    }
}
