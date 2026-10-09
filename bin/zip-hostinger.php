<?php

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php bin/zip-hostinger.php DIRECTORIO DESTINO.zip\n");
    exit(1);
}

$source = realpath($argv[1]);
if ($source === false || ! is_dir($source)) {
    throw new RuntimeException('Directorio de origen inválido');
}
$zip = new ZipArchive;
if ($zip->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('No se pudo crear el ZIP');
}
try {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('El paquete no admite enlaces simbólicos');
        }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($file->getPathname(), $relative);
        }
    }
} finally {
    $zip->close();
}
