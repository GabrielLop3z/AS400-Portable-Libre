<?php
// router.php - Enrutador para el servidor PHP interno (Portable)

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Si el archivo buscado realmente existe en el disco, pedimos al servidor que lo entregue tal cual
if (file_exists($_SERVER["DOCUMENT_ROOT"] . $path) && $path !== '/') {
    return false;
}

// Si la URL principal está siendo accedida, servimos index.php
if ($path === '/' || $path === '/index.php') {
    include 'index.php';
    return true;
}

// Si no, el archivo no existe. Emitimos codigo 404 y mostramos nuestra interfaz.
http_response_code(404);
include '404.php';
return true;
?>
