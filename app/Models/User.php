<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    /** Operación diaria del vendedor: catálogo, pedidos, pagos, caja y comprobantes SUNAT. */
    public const ADMIN_PERMISSIONS = ['catalog:write', 'orders:manage', 'payments:confirm', 'cash:manage', 'invoices:submit'];

    /** Además de lo del admin: venta directa, proveedores, reportes, compras/uso interno, usuarios y bitácora. */
    public const SUPERADMIN_PERMISSIONS = [...self::ADMIN_PERMISSIONS, 'sales:create', 'suppliers:manage', 'reports:view', 'inventory:manage', 'users:manage', 'audit:view'];

    public const STAFF_ROLES = ['SUPERADMIN', 'ADMIN'];

    /**
     * Lo que el superadmin puede dar a un administrador: todo menos gestionar usuarios, para que
     * nadie por debajo pueda darse más permisos a sí mismo.
     */
    public const GRANTABLE_PERMISSIONS = ['catalog:write', 'orders:manage', 'payments:confirm', 'cash:manage', 'invoices:submit',
        'sales:create', 'suppliers:manage', 'reports:view', 'inventory:manage', 'audit:view'];

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'must_change_password' => 'boolean', 'permissions' => 'array'];
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** Sin permisos mientras la cuenta no esté activa o tenga una contraseña temporal pendiente de cambio. */
    public function permissions(): array
    {
        if (($this->status ?? 'ACTIVE') !== 'ACTIVE' || $this->must_change_password) {
            return [];
        }

        return match ($this->role) {
            'SUPERADMIN' => self::SUPERADMIN_PERMISSIONS,
            'ADMIN' => self::adminPermissions($this->getAttribute('permissions')),
            default => [],
        };
    }

    /** Permisos elegidos por el superadmin (solo los asignables) o, si no eligió, los estándar. */
    public static function adminPermissions(mixed $chosen): array
    {
        if (is_string($chosen)) {
            $chosen = json_decode($chosen, true);
        }

        return is_array($chosen)
            ? array_values(array_intersect(self::GRANTABLE_PERMISSIONS, $chosen))
            : self::ADMIN_PERMISSIONS;
    }
}
