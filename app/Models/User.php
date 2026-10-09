<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function permissions(): array
    {
        return $this->role === 'ADMIN' ? ['catalog:write', 'orders:manage', 'payments:confirm', 'suppliers:manage', 'invoices:submit'] : [];
    }
}
