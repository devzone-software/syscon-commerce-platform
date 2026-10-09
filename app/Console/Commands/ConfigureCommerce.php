<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConfigureCommerce extends Command
{
    protected $signature = 'commerce:configure {--sqlite : Configura SQLite para desarrollo local}';

    protected $description = 'Crea .env y genera únicamente los secretos vacíos';

    public function handle(): int
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
        }
        $text = file_get_contents($path);
        foreach (['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'JWT_SECRET' => bin2hex(random_bytes(32)), 'SERVICE_TOKEN' => bin2hex(random_bytes(32)), 'ADMIN_PASSWORD' => bin2hex(random_bytes(24))] as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $text, $matches)) {
                if (in_array(trim($matches[1]), ['', '""', "''"], true)) {
                    $text = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $text);
                }
            } else {
                $text .= "\n".$key.'='.$value."\n";
            }
        }
        if ($this->option('sqlite')) {
            $db = database_path('database.sqlite');
            if (! file_exists($db)) {
                touch($db);
            }
            foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db] as $key => $value) {
                $text = preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $text);
            }
        }
        file_put_contents($path, $text);
        chmod($path, 0600);
        $this->info('.env listo. Los secretos existentes se conservaron. Configura las credenciales de base de datos proporcionadas por tu hosting.');

        return self::SUCCESS;
    }
}
