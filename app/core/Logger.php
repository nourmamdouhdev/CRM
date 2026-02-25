<?php
// app/core/Logger.php

final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            $level = strtoupper($level);
            $date = new DateTimeImmutable('now');

            $logDir = __DIR__ . '/../../storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $file = $logDir . '/app-' . $date->format('Y-m-d') . '.log';

            $contextStr = '';
            if (!empty($context)) {
                $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $line = sprintf(
                "[%s] %s: %s%s\n",
                $date->format('Y-m-d H:i:s'),
                $level,
                $message,
                $contextStr
            );

            @file_put_contents($file, $line, FILE_APPEND);
        } catch (Throwable $e) {
            // Swallow all logging errors to avoid breaking the main flow.
        }
    }
}

