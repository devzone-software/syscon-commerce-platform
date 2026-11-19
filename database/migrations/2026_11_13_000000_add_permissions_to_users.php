<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Permisos elegidos por el superadmin para un administrador; null = los estándar del rol. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('permissions'));
    }
};
