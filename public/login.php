<?php
$requireAuth = false;
require __DIR__ . '/bootstrap.php';

if (Auth::check()) {
  header("Location: {$base}/index.php");
  exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  CSRF::verify($_POST['csrf_token'] ?? null);

  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || $password === '') {
    $error = 'اكتب اليوزر نيم والباسورد.';
  } else {
    if (Auth::attempt($username, $password)) {
      header('Location: ./index.php');
      exit;
    }
    $error = 'بيانات الدخول غلط.';
  }
}

$csrf = CSRF::token();
require __DIR__ . '/../app/views/login.view.php';

