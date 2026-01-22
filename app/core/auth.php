<?php
// app/Core/Auth.php
final class Auth {
  public static function attempt(string $username, string $password): bool {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("
      SELECT u.id, u.full_name, u.username, u.password_hash, u.is_active, r.name AS role
      FROM users u
      JOIN roles r ON r.id = u.role_id
      WHERE u.username = :username
      LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    session_regenerate_id(true);
    $_SESSION['user'] = [
      'id' => (int)$user['id'],
      'full_name' => $user['full_name'],
      'username' => $user['username'],
      'role' => $user['role'],
    ];
    return true;
  }

  public static function check(): bool {
    return !empty($_SESSION['user']['id']);
  }

  public static function user(): ?array {
    return $_SESSION['user'] ?? null;
  }

  public static function logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"], $params["secure"], $params["httponly"]
      );
    }
    session_destroy();
  }
}
