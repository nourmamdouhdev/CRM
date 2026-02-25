<?php
$requireAuth = false;
require __DIR__ . '/bootstrap.php';

Auth::logout();
header("Location: {$base}/login.php");
exit;

