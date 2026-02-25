<?php
// app/Middlewares/AuthMiddleware.php
final class AuthMiddleware {
  public static function handle(): void {
    if (!Auth::check()) {
      $config = require __DIR__ . '/../../config/config.php';
      $base = $config['app']['base_url'] ?? '/tagom/public';
      header('Location: ' . $base . '/login.php');
      exit;
    }
  }
}
