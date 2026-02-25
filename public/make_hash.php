<?php
$requireAuth = false;
require __DIR__ . '/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$isLocalEnv = (($config['app']['env'] ?? 'production') === 'local');

if (!$isCli) {
  http_response_code(404);
  exit('Not found');
}

if (!$isLocalEnv) {
  fwrite(STDERR, "This utility is only allowed in local environment.\n");
  exit(1);
}

$input = $argv[1] ?? '123456';
echo password_hash($input, PASSWORD_BCRYPT) . PHP_EOL;
