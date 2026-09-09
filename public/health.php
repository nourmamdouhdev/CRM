<?php
$requireAuth = false;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    require __DIR__ . '/bootstrap.php';
    DB::pdo()->query('SELECT 1');
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'unhealthy';
}
