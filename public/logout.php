<?php
require __DIR__ . '/../app/Core/Auth.php';

$config = require __DIR__ . '/../config/config.php';
session_name($config['app']['session_name']);
session_start();

Auth::logout();
header('Location: /tagom/public/login.php');
exit;

