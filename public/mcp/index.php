<?php
$requireAuth = false;
require __DIR__ . '/../bootstrap.php';

use App\Domain\Integrations\IntegrationFactory;

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$mcp = IntegrationFactory::mcp($pdo, $config);

if (!$mcp->isReady()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'MCP server is not activated']], JSON_UNESCAPED_UNICODE);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
$queryToken = $_GET['access_token'] ?? null;
if (!$mcp->authorize(is_string($authHeader) ? $authHeader : null, is_string($queryToken) ? $queryToken : null)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="tagom-mcp"');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Unauthorized']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Use POST for JSON-RPC requests']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo 'Method Not Allowed';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$message = json_decode($raw, true);
if (!is_array($message)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$response = $mcp->handle($message);
if ($response === null) {
    http_response_code(202);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
