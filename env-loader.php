<?php
declare(strict_types=1);

/**
 * Basic .env loader helper
 * Reads a .env file and populates putenv(), $_ENV, and $_SERVER.
 */
function loadEnv(string $filePath): void
{
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        // Split key and value by the first '=' sign
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes if present
            if (
                (strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                (strpos($value, "'") === 0 && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            // Populate environments
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Load .env automatically from current directory
loadEnv(__DIR__ . '/.env');
