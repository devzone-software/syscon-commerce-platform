<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Con explicit_defaults_for_timestamp=OFF (predeterminado en MySQL 5.7 y en varios MariaDB), la
 * primera columna TIMESTAMP NOT NULL sin DEFAULT de cada tabla recibe ON UPDATE CURRENT_TIMESTAMP
 * y se sobrescribe en cada actualización: el inicio de una sincronización, la apertura de caja o
 * el vencimiento de un refresh token cambiaban al actualizar la fila. Se fija un DEFAULT explícito
 * sin ON UPDATE. SQLite y PostgreSQL no tienen ese comportamiento.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'cash_movements' => 'created_at', 'cash_sessions' => 'opened_at', 'invoice_attempts' => 'created_at',
        'order_events' => 'created_at', 'refresh_tokens' => 'expires_at', 'supplier_history' => 'occurred_at',
        'supplier_jobs' => 'started_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE `$table` MODIFY `$column` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // Volver a ON UPDATE reintroduciría el error; no hay nada que revertir.
    }
};
