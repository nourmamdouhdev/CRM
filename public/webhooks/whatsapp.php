<?php
$requireAuth = false;
require __DIR__ . '/../bootstrap.php';

use App\Domain\Integrations\IntegrationFactory;

header('X-Content-Type-Options: nosniff');

$whatsApp = IntegrationFactory::whatsApp($pdo, $config);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $whatsApp->verifyWebhook($_GET);
    if ($challenge === null) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

if (!$whatsApp->isEnabled()) {
    http_response_code(503);
    echo 'WhatsApp integration is disabled';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
$isLocal = (($config['app']['env'] ?? '') === 'local');

if (!$whatsApp->acceptWebhook($raw, is_string($signature) ? $signature : null, $isLocal)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

try {
    $whatsApp->ingestWebhook($payload);
} catch (Throwable $e) {
    log_message('error', 'WhatsApp webhook ingest failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo 'Error';
    exit;
}

http_response_code(200);
echo 'EVENT_RECEIVED';
