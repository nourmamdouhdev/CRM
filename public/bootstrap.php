<?php
// public/bootstrap.php
// Shared bootstrap for all public entrypoints.

spl_autoload_register(static function (string $class): void {
  $prefix = 'App\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
    return;
  }

  $relative = substr($class, strlen($prefix));
  $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/core/CSRF.php';
require_once __DIR__ . '/../app/Middlewares/AuthMiddleware.php';
require_once __DIR__ . '/../app/helpers.php';

$config = require __DIR__ . '/../config/config.php';

// Session & cookie configuration
$appConfig = $config['app'] ?? [];

$sessionName = $appConfig['session_name'] ?? 'TAGOMSESSID';
$env        = $appConfig['env']        ?? 'production';
$sameSite   = $appConfig['samesite']   ?? 'Lax';

$cookieParams = session_get_cookie_params();

$secure = $appConfig['secure'] ??
  (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
  'lifetime' => $cookieParams['lifetime'],
  'path'     => $cookieParams['path'],
  'domain'   => $cookieParams['domain'],
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => $sameSite,
]);

session_name($sessionName);
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Base URL helper variable for views and redirects
$base = $appConfig['base_url'] ?? '/tagom/public';

// By default, all pages require authentication unless they explicitly
// set $requireAuth = false before including this file.
if (!isset($requireAuth)) {
  $requireAuth = true;
}

if ($requireAuth) {
  AuthMiddleware::handle();
}

$user = Auth::user();
$pdo  = DB::pdo();

App\Infrastructure\Database\SchemaGuard::ensure($pdo);

