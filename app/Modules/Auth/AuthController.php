<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Support\Api;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController
{
    public function register(Request $r)
    {
        $data = $r->validate(['email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:128']);
        $email = strtolower(trim($data['email']));
        abort_if(User::where('email', $email)->exists(), 409, 'Correo ya registrado');
        $user = User::create(['email' => $email, 'password' => $data['password'], 'role' => 'CUSTOMER']);

        return Api::ok('Usuario creado', $user->id, 201);
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower(trim($data['email'])))->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 401, 'Credenciales inválidas');

        return Api::ok('Autenticado', DB::transaction(fn () => $this->issue($user)));
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

    private function issue(User $user): array
    {
        $secret = config('commerce.jwt_secret');
        abort_if(strlen($secret) < 32, 503, 'JWT_SECRET no configurado');
        $refresh = Str::random(96);
        DB::table('refresh_tokens')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'token_hash' => hash('sha256', $refresh), 'expires_at' => now()->addDays(30), 'revoked' => false]);

        return ['accessToken' => JWT::encode(['iss' => 'syscon-auth', 'sub' => $user->id, 'iat' => time(), 'exp' => time() + 900, 'authorities' => array_merge(['ROLE_'.$user->role], $user->permissions())], $secret, 'HS256'), 'refreshToken' => $refresh, 'expiresInSeconds' => 900];
    }
}
