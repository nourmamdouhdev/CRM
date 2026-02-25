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
  ],
];
