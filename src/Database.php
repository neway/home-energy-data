<?php

declare(strict_types=1);

// Config and connection shared by givenergy_import.php and battery_report.php.

function loadConfigFile(array $opts): array {
    if (empty($opts['config'])) {
        return [];
    }

    $config = parse_ini_file((string)$opts['config'], false, INI_SCANNER_TYPED);
    if ($config === false) {
        throw new RuntimeException('Unable to parse config file: ' . $opts['config']);
    }

    return $config;
}

function loadDbSettings(array $opts, array $config): array {
    $settings = [
        'db_host' => $opts['db-host'] ?? ($config['db_host'] ?? '127.0.0.1'),
        'db_port' => (int)($opts['db-port'] ?? ($config['db_port'] ?? 3306)),
        'db_name' => $opts['db-name'] ?? ($config['db_name'] ?? null),
        'db_user' => $opts['db-user'] ?? ($config['db_user'] ?? null),
        'db_pass' => $opts['db-pass'] ?? ($config['db_pass'] ?? null),
    ];

    validateRequiredSettings($settings, ['db_name', 'db_user', 'db_pass']);

    return $settings;
}

function validateRequiredSettings(array $settings, array $required): void {
    foreach ($required as $key) {
        if (empty($settings[$key])) {
            throw new InvalidArgumentException("Missing required setting: {$key}");
        }
    }
}

function connectPdo(array $settings): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $settings['db_host'],
        $settings['db_port'],
        $settings['db_name']
    );

    $pdo = new PDO($dsn, $settings['db_user'], $settings['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
