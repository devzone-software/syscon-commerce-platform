<?php
// Uso: php bin/db-export.php <database.sqlite> <salida.json>
// Exporta todas las tablas con datos, salvo las que solo tienen sentido en local.
[$_, $source, $target] = $argv;
$skip = ['migrations', 'sqlite_sequence', 'cache', 'cache_locks', 'refresh_tokens', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];
$db = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$out = [];
foreach ($db->query("select name from sqlite_master where type='table' order by name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if (in_array($table, $skip, true)) continue;
    $rows = $db->query("select * from \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) $out[$table] = $rows;
}
file_put_contents($target, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
foreach ($out as $table => $rows) echo "$table: ".count($rows)."\n";
