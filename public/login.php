<?php
require __DIR__ . '/../app/Core/DB.php';
require __DIR__ . '/../app/Core/Auth.php';
require __DIR__ . '/../app/Core/CSRF.php';

$config = require __DIR__ . '/../config/config.php';

session_name($config['app']['session_name']);
session_start();

if (Auth::check()) {
header('Location: /tagom/public/index.php');
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

