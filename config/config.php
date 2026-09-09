<?php
// config/config.php

$getenvOrDefault = static function (string $name, mixed $default): mixed {
  $value = getenv($name);
  if ($value === false) {
    return $default;
  }
  return $value;
};

$toBool = static function (mixed $value, bool $default = false): bool {
  if (is_bool($value)) return $value;
  if ($value === null || $value === '') return $default;
  $normalized = strtolower(trim((string)$value));
  if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return true;
  if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) return false;
  return $default;
};

$baseUrl = $getenvOrDefault('APP_BASE_URL', '/tagom/public');
$appEnv = $getenvOrDefault('APP_ENV', 'local');
$appSameSite = $getenvOrDefault('APP_SAMESITE', 'Lax');
$appSecureEnv = $getenvOrDefault('APP_SECURE', null);

return [
  'db' => [
    'host' => (string)$getenvOrDefault('DB_HOST', '127.0.0.1'),
    'name' => (string)$getenvOrDefault('DB_NAME', 'tagom_crm'),
    'user' => (string)$getenvOrDefault('DB_USER', 'root'),
    'pass' => (string)$getenvOrDefault('DB_PASS', ''),
    'charset' => (string)$getenvOrDefault('DB_CHARSET', 'utf8mb4'),
  ],
  'app' => [
    'session_name' => (string)$getenvOrDefault('APP_SESSION_NAME', 'TAGOMSESSID'),
    'base_url' => (string)$baseUrl,
    'env' => (string)$appEnv,
    'secure' => $appSecureEnv === null ? null : $toBool($appSecureEnv, false),
    'samesite' => (string)$appSameSite,
    'allow_negative_stock' => $toBool($getenvOrDefault('APP_ALLOW_NEGATIVE_STOCK', '0'), false),
    'key' => (string)$getenvOrDefault('APP_KEY', ''),
  ],
  'features' => [
    'use_sales_service' => $toBool($getenvOrDefault('FEATURE_USE_SALES_SERVICE', '0'), false),
    'use_purchase_service' => $toBool($getenvOrDefault('FEATURE_USE_PURCHASE_SERVICE', '0'), false),
    'use_payment_service' => $toBool($getenvOrDefault('FEATURE_USE_PAYMENT_SERVICE', '0'), false),
  ],
  'authz' => [
    'sales_create' => ['owner', 'employee'],
    'purchase_create' => ['owner', 'employee'],
    'payment_in_create' => ['owner', 'employee'],
    'payment_out_create' => ['owner', 'employee'],
    'products_manage' => ['owner', 'employee'],
    'users_manage' => ['owner'],
    'integrations_manage' => ['owner'],
  ],
  'whatsapp' => [
    'enabled' => $toBool($getenvOrDefault('WHATSAPP_ENABLED', '0'), false),
    'graph_base' => (string)$getenvOrDefault('WHATSAPP_GRAPH_BASE', 'https://graph.facebook.com'),
    'api_version' => (string)$getenvOrDefault('WHATSAPP_API_VERSION', 'v21.0'),
    'access_token' => (string)$getenvOrDefault('WHATSAPP_ACCESS_TOKEN', ''),
    'phone_number_id' => (string)$getenvOrDefault('WHATSAPP_PHONE_NUMBER_ID', ''),
    'business_account_id' => (string)$getenvOrDefault('WHATSAPP_BUSINESS_ACCOUNT_ID', ''),
    'verify_token' => (string)$getenvOrDefault('WHATSAPP_VERIFY_TOKEN', ''),
    'app_secret' => (string)$getenvOrDefault('WHATSAPP_APP_SECRET', ''),
    'default_country_code' => (string)$getenvOrDefault('WHATSAPP_DEFAULT_COUNTRY_CODE', '20'),
  ],
  'mcp' => [
    'enabled' => $toBool($getenvOrDefault('MCP_ENABLED', '0'), false),
    'bearer_token' => (string)$getenvOrDefault('MCP_BEARER_TOKEN', ''),
    'protocol_version' => (string)$getenvOrDefault('MCP_PROTOCOL_VERSION', '2025-11-25'),
    'server_name' => (string)$getenvOrDefault('MCP_SERVER_NAME', 'tagom-crm'),
  ],
  'claude' => [
    'enabled' => $toBool($getenvOrDefault('CLAUDE_ENABLED', '0'), false),
    'api_key' => (string)$getenvOrDefault('CLAUDE_API_KEY', ''),
    'api_base' => (string)$getenvOrDefault('CLAUDE_API_BASE', 'https://api.anthropic.com'),
    'api_version' => (string)$getenvOrDefault('CLAUDE_API_VERSION', '2023-06-01'),
    'model' => (string)$getenvOrDefault('CLAUDE_MODEL', 'claude-sonnet-4-5'),
    'mcp_beta' => (string)$getenvOrDefault('CLAUDE_MCP_BETA', 'mcp-client-2025-04-04'),
  ],
];
