<?php

spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__) . $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

require_once dirname(__DIR__) . '/app/helpers.php';
