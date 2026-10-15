<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Support\Api;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController
{
    public function register(Request $r)
    {
        $data = $r->validate([
            'email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:128',
            'firstName' => 'nullable|string|max:80', 'lastName' => 'nullable|string|max:80',
            'documentType' => 'nullable|in:DNI,CE,RUC,PASAPORTE', 'documentNumber' => 'nullable|string|max:15',
            'phone' => ['nullable', 'regex:/^9\d{8}$/'],
        ]);
        $email = strtolower(trim($data['email']));
        abort_if(User::where('email', $email)->exists(), 409, 'Correo ya registrado');
        $user = User::create(['email' => $email, 'password' => $data['password'], 'role' => 'CUSTOMER',
            'first_name' => $data['firstName'] ?? null, 'last_name' => $data['lastName'] ?? null,
            'document_type' => $data['documentType'] ?? null, 'document_number' => $data['documentNumber'] ?? null,
            'phone' => $data['phone'] ?? null]);

        return Api::ok('Usuario creado', $user->id, 201);
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower(trim($data['email'])))->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 401, 'Credenciales inválidas');

        return Api::ok('Autenticado', DB::transaction(fn () => $this->issue($user)));
    }

    /** Google Identity Services: verifica el ID token con Google y crea el cliente si no existe. */
    public function google(Request $r)
    {
        $data = $r->validate(['credential' => 'required|string|max:4096']);
        $clientId = (string) config('services.google.client_id');
        abort_if($clientId === '', 503, 'Inicio de sesión con Google no configurado');
        try {
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $data['credential']]);
        } catch (ConnectionException) {
            abort(503, 'Google no está disponible');
        }
        $claims = $response->json();
        abort_unless($response->ok() && is_array($claims)
            && hash_equals($clientId, (string) ($claims['aud'] ?? ''))
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['email_verified'] ?? null) === 'true' && (int) ($claims['exp'] ?? 0) > time()
            && isset($claims['sub'], $claims['email']), 401, 'Credencial de Google inválida');

        return DB::transaction(function () use ($claims) {
            $email = strtolower(trim($claims['email']));
            $user = User::where('google_sub', $claims['sub'])->lockForUpdate()->first()
                ?? User::where('email', $email)->lockForUpdate()->first();
            $created = ! $user;
            if ($created) {
                // Contraseña aleatoria desconocida: la cuenta solo entra con Google hasta que se defina otra.
                $user = User::create(['email' => $email, 'password' => Str::random(64), 'role' => 'CUSTOMER',
                    'first_name' => $claims['given_name'] ?? null, 'last_name' => $claims['family_name'] ?? null]);
            }
            if (! $user->google_sub) {
                $user->update(['google_sub' => $claims['sub']]);
            }

            return Api::ok('Autenticado', $this->issue($user) + ['created' => $created]);
        });
    }

    public function refresh(Request $r)
    {
        $data = $r->validate(['refreshToken' => 'required|string|max:256']);

        return DB::transaction(function () use ($data) {
            $token = DB::table('refresh_tokens')->where('token_hash', hash('sha256', $data['refreshToken']))->lockForUpdate()->first();
            abort_unless($token && ! $token->revoked && now()->lt($token->expires_at), 401, 'Refresh token expirado o revocado');
            DB::table('refresh_tokens')->where('id', $token->id)->update(['revoked' => true]);

            return Api::ok('Token renovado', $this->issue(User::findOrFail($token->user_id)));
        });
    }

    public function logout(Request $r)
    {
        $data = $r->validate(['refreshToken' => 'required|string|max:256']);
        DB::table('refresh_tokens')->where('token_hash', hash('sha256', $data['refreshToken']))->update(['revoked' => true]);

        return Api::ok('Sesión cerrada');
    }

    public function me(Request $r)
    {
        return Api::ok('Usuario', $r->user()->id);
    }

    /** Datos del perfil para precargar formularios (p. ej. la facturación del checkout). */
    public function profile(Request $r)
    {
        $u = $r->user();

        return Api::ok('Perfil', ['email' => $u->email, 'firstName' => $u->first_name, 'lastName' => $u->last_name, 'documentType' => $u->document_type, 'documentNumber' => $u->document_number, 'phone' => $u->phone]);
    }

    private function issue(User $user): array
    {
        $secret = config('commerce.jwt_secret');
        abort_if(strlen($secret) < 32, 503, 'JWT_SECRET no configurado');
        $refresh = Str::random(96);
        DB::table('refresh_tokens')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'token_hash' => hash('sha256', $refresh), 'expires_at' => now()->addDays(30), 'revoked' => false]);

        return ['accessToken' => JWT::encode(['iss' => 'syscon-auth', 'sub' => $user->id, 'email' => $user->email, 'given_name' => $user->first_name, 'family_name' => $user->last_name, 'iat' => time(), 'exp' => time() + 900, 'authorities' => array_merge(['ROLE_'.$user->role], $user->permissions())], $secret, 'HS256'), 'refreshToken' => $refresh, 'expiresInSeconds' => 900];
    }
}
