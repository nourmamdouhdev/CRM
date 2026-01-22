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
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
      http_response_code(419);
      exit('Invalid CSRF token');
    }
  }
}
