<?php
// app/Core/CSRF.php
final class CSRF {
  public static function token(): string {
    if (empty($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
  }

  public static function verify(?string $token): void {
    if (
      !$token ||
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], $token)
    ) {
      if (function_exists('log_message')) {
        log_message('warning', 'Invalid CSRF token', [
          'uri' => $_SERVER['REQUEST_URI'] ?? null,
          'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
      }
      if (function_exists('render_error')) {
        render_error('الجلسة انتهت أو الطلب غير صالح. رجاءً حدّث الصفحة وحاول مرة أخرى.', 419);
      }
      http_response_code(419);
      exit('Invalid CSRF token');
    }
  }
}
