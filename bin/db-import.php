<?php
// Uso: php bin/db-import.php <export.json> <dsn> <usuario> <clave> <url-local> <url-destino>
// Vacía cada tabla exportada e inserta sus filas en una sola transacción.
// Reemplaza la URL local de la API (imágenes) por la del destino.
[$_, $file, $dsn, $user, $pass, $fromUrl, $toUrl] = $argv;
$data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$q = fn (string $name) => $mysql ? "`$name`" : "\"$name\"";

$db->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
$db->beginTransaction();
foreach ($data as $table => $rows) {
    $db->exec('DELETE FROM '.$q($table));
    $columns = array_keys($rows[0]);
    $insert = $db->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $q($table),
        implode(',', array_map($q, $columns)), implode(',', array_fill(0, count($columns), '?'))));
    foreach ($rows as $row) {
        $insert->execute(array_map(
            fn ($v) => is_string($v) ? str_replace($fromUrl, $toUrl, $v) : $v,
            array_values($row),
        ));
    }
    echo "$table: ".count($rows)."\n";
}
$db->commit();
$db->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
