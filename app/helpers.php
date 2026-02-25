<?php
// app/helpers.php

require_once __DIR__ . '/core/Logger.php';

if (!defined('ROLE_ADMIN')) {
    define('ROLE_ADMIN', 'admin');
}

if (!defined('ROLE_OWNER')) {
    define('ROLE_OWNER', 'owner');
}

if (!defined('ROLE_MANAGER')) {
    define('ROLE_MANAGER', 'manager');
}

if (!defined('ROLE_EMPLOYEE')) {
    define('ROLE_EMPLOYEE', 'employee');
}

if (!defined('ROLE_CLERK')) {
    define('ROLE_CLERK', 'clerk');
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('user_role')) {
    function user_role(): string
    {
        $role = $_SESSION['user']['role'] ?? '';
        return strtolower(trim((string)$role));
    }
}

if (!function_exists('has_role')) {
    function has_role(array $roles): bool
    {
        $currentRole = user_role();
        if ($currentRole === '') {
            return false;
        }

        foreach ($roles as $role) {
            if ($currentRole === strtolower(trim((string)$role))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return has_role([ROLE_ADMIN]);
    }
}

if (!function_exists('authorize')) {
    function authorize(array $roles): void
    {
        $service = new App\Domain\Auth\AuthorizationService();
        $service->assertAnyRole($roles);
    }
}

if (!function_exists('money')) {
    function money($n): string
    {
        return number_format((float)$n, 2, '.', '');
    }
}

if (!function_exists('typeLabel')) {
    function typeLabel(string $type): string
    {
        return match ($type) {
            'sale_invoice' => 'Sales Invoice',
            'purchase_invoice' => 'Purchase Invoice',
            'payment_in' => 'Collection',
            'payment_out' => 'Disbursement',
            'adjustment' => 'Adjustment',
            default => $type,
        };
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        global $base;

        if ($path === '') {
            $path = '/index.php';
        }

        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $targetBase = $base ?? '';
        header('Location: ' . $targetBase . $path);
        exit;
    }
}

if (!function_exists('render_error')) {
    function render_error(string $message, int $statusCode = 500): void
    {
        http_response_code($statusCode);

        Logger::log('error', $message, [
            'status' => $statusCode,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        ?>
<!doctype html>
<html lang="ar" dir="rtl">
  <head>
    <meta charset="utf-8">
    <title>System error</title>
  </head>
  <body>
    <h1>System error</h1>
    <p><?= h($message) ?></p>
  </body>
</html>
<?php
        exit;
    }
}

if (!function_exists('log_message')) {
    function log_message(string $level, string $message, array $context = []): void
    {
        Logger::log($level, $message, $context);
    }
}

if (!function_exists('audit_log')) {
    function audit_log(string $action, string $entityType, int $entityId, array $payload = []): void
    {
        try {
            $actorId = (int)($_SESSION['user']['id'] ?? 0);
            $repo = new App\Infrastructure\Persistence\AuditLogRepository(DB::pdo());
            $repo->insert($actorId > 0 ? $actorId : null, $action, $entityType, $entityId, $payload);
        } catch (Throwable $e) {
            Logger::log('warning', 'Failed to write audit log', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
