<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/core/DB.php';

$pdo = DB::pdo();
$dir = __DIR__ . '/../database/migrations';

if (!is_dir($dir)) {
    fwrite(STDERR, "Migrations directory not found: {$dir}\n");
    exit(1);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS migrations (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      migration VARCHAR(255) NOT NULL UNIQUE,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )"
);

$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$applied = [];
$stmt = $pdo->query('SELECT migration FROM migrations');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $applied[(string)$row['migration']] = true;
}

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "[skip] {$name}\n";
        continue;
    }

    echo "[run ] {$name}\n";
    $sql = trim((string)file_get_contents($file));
    if ($sql === '') {
        $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
        continue;
    }

    $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
    try {
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                // Keep migrations idempotent for common "already exists" DDL collisions.
                if (in_array($driverCode, [1060, 1061, 1050], true)) {
                    continue;
                }
                throw $e;
            }
        }
        $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
        echo "[ok  ] {$name}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "[fail] {$name}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Done.\n";
