<?php
// app/Middlewares/AuthMiddleware.php
final class AuthMiddleware {
  public static function handle(): void {
    if (!Auth::check()) {
      header('Location: /tagom/public/login.php');
      exit;

    }
  }
}
