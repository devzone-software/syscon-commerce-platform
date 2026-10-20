<?php

namespace App\Modules\Users;

use App\Models\User;
use App\Modules\Orders\OrderService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cuentas del personal, solo para el superadmin (users:manage). Reglas de seguridad:
 * - Las cuentas ADMIN solo las crea el superadmin: nacen PENDING con una contraseña temporal que se
 *   muestra una sola vez, y no inician sesión hasta que el superadmin las valida.
 * - En el primer ingreso el admin debe cambiar la contraseña temporal; mientras tanto no tiene permisos.
 * - Suspender o resetear la contraseña revoca de inmediato todas las sesiones de la cuenta.
 * - Solo se gestionan cuentas ADMIN: ni otros superadmins ni la propia cuenta.
 */
class UserController
{
    public function index(Request $r)
    {
        $data = $r->validate(['role' => 'nullable|in:SUPERADMIN,ADMIN,CUSTOMER', 'status' => 'nullable|in:ACTIVE,PENDING,SUSPENDED', 'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('users')->whereIn('role', ['SUPERADMIN', 'ADMIN', 'CUSTOMER']);
        foreach (['role', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['q'])) {
            $like = '%'.strtolower(trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(first_name) LIKE ?', [$like])->orWhereRaw('LOWER(last_name) LIKE ?', [$like]));
        }
        $perPage = (int) ($data['perPage'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $total = (clone $query)->count();
        // El personal primero, luego los clientes; dentro de cada grupo, lo más reciente arriba.
        $items = $query->orderByRaw("CASE role WHEN 'SUPERADMIN' THEN 0 WHEN 'ADMIN' THEN 1 ELSE 2 END")->orderByDesc('created_at')
            ->forPage($page, $perPage)->get()->map(fn ($u) => self::view($u))->all();

        return Api::ok('Usuarios', ['items' => $items, 'page' => $page, 'perPage' => $perPage, 'total' => $total]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'email' => 'required|email|max:255', 'firstName' => 'required|string|max:80', 'lastName' => 'required|string|max:80',
            'documentType' => 'required|in:DNI,CE,PASAPORTE', 'documentNumber' => 'required|string|max:15', 'phone' => ['required', 'regex:/^9\d{8}$/'],
        ]);
        $email = strtolower(trim($data['email']));
        abort_if(DB::table('users')->where('email', $email)->exists(), 409, 'Correo ya registrado');
        $temporary = self::temporaryPassword();
        $user = User::create(['email' => $email, 'password' => $temporary, 'role' => 'ADMIN', 'status' => 'PENDING', 'must_change_password' => true,
            'first_name' => trim($data['firstName']), 'last_name' => trim($data['lastName']), 'document_type' => $data['documentType'],
            'document_number' => trim($data['documentNumber']), 'phone' => $data['phone'], 'created_by' => $r->user()->id]);

        return Api::ok('Administrador creado: pendiente de validación', ['user' => self::view(DB::table('users')->where('id', $user->id)->first()), 'temporaryPassword' => $temporary], 201);
    }

    /** Validación: el superadmin revisó la identidad del admin y habilita su cuenta. */
    public function validateAccount(Request $r, string $id)
    {
        return $this->change($r, $id, function (object $u) use ($r) {
            abort_unless($u->status === 'PENDING', 409, 'La cuenta no está pendiente de validación');

            return ['status' => 'ACTIVE', 'validated_by' => $r->user()->id, 'validated_at' => now()];
        }, 'Cuenta validada');
    }

    public function suspend(Request $r, string $id)
    {
        return $this->change($r, $id, function (object $u) {
            abort_if($u->status === 'SUSPENDED', 409, 'La cuenta ya está suspendida');

            return ['status' => 'SUSPENDED'];
        }, 'Cuenta suspendida', true);
    }

    public function reactivate(Request $r, string $id)
    {
        return $this->change($r, $id, function (object $u) {
            abort_unless($u->status === 'SUSPENDED', 409, 'La cuenta no está suspendida');

            // Una cuenta que nunca se validó vuelve a quedar pendiente, no activa.
            return ['status' => $u->validated_at ? 'ACTIVE' : 'PENDING'];
        }, 'Cuenta reactivada');
    }

    public function resetPassword(Request $r, string $id)
    {
        $temporary = self::temporaryPassword();
        $reply = $this->change($r, $id, fn () => ['password' => Hash::make($temporary), 'must_change_password' => true], 'Contraseña temporal generada', true);

        return Api::ok('Contraseña temporal generada', ['user' => $reply->getData(true)['data'], 'temporaryPassword' => $temporary]);
    }

    private function change(Request $r, string $id, callable $values, string $message, bool $revoke = false)
    {
        return DB::transaction(function () use ($r, $id, $values, $message, $revoke) {
            $u = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            abort_unless($u, 404, 'Usuario no encontrado');
            abort_if($u->id === $r->user()->id, 409, 'No puedes modificar tu propia cuenta');
            abort_unless($u->role === 'ADMIN', 403, 'Solo se gestionan cuentas de administrador');
            DB::table('users')->where('id', $id)->update($values($u) + ['updated_at' => now()]);
            if ($revoke) {
                DB::table('refresh_tokens')->where('user_id', $id)->update(['revoked' => true]);
            }

            return Api::ok($message, self::view(DB::table('users')->where('id', $id)->first()));
        });
    }

    /** 16 caracteres con mayúsculas, minúsculas, números y un símbolo; se entrega una sola vez. */
    private static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $chars = [chr(random_int(65, 90)), chr(random_int(97, 122)), (string) random_int(2, 9), '!@#$%*?'[random_int(0, 6)]];
        while (count($chars) < 16) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }

    public static function view(object $u): array
    {
        $names = DB::table('users')->whereIn('id', array_filter([$u->created_by, $u->validated_by]))->pluck('email', 'id');

        return ['id' => $u->id, 'email' => $u->email, 'firstName' => $u->first_name, 'lastName' => $u->last_name, 'role' => $u->role, 'status' => $u->status,
            'documentType' => $u->document_type, 'documentNumber' => $u->document_number, 'phone' => $u->phone,
            'mustChangePassword' => (bool) $u->must_change_password, 'createdBy' => $names[$u->created_by] ?? null, 'validatedBy' => $names[$u->validated_by] ?? null,
            'validatedAt' => OrderService::iso($u->validated_at), 'lastLoginAt' => OrderService::iso($u->last_login_at), 'createdAt' => OrderService::iso($u->created_at)];
    }
}
