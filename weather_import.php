<?php

declare(strict_types=1);

/*
 * Daily mean outside temperature from the Open-Meteo archive (free, no key) into weather_daily, for degree days:
 * how cold it was, to compare heating between winters. The location is WEATHER_LATITUDE and WEATHER_LONGITUDE in
 * ../home_energy_private/config.php (a town is close enough). The archive runs a few days behind, so each run
 * refetches the last week. Safe to re-run.
 *
 * Usage:
 *   php weather_import.php --config=... [--from=2023-01-01]
 */

const WEATHER_ARCHIVE = 'https://archive-api.open-meteo.com/v1/archive';

require_once __DIR__ . '/../home_energy_private/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/SchemaManager.php';

$opts = getopt('', ['config::', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'from::']);
$pdo = connectPdo(loadDbSettings($opts, loadConfigFile($opts)));
(new SchemaManager($pdo))->ensureSchema();

$last = $pdo->query('SELECT MAX(day) FROM weather_daily')->fetchColumn();
$from = $opts['from'] ?? ($last ? date('Y-m-d', strtotime("$last -7 days")) : date('Y-m-d', strtotime('-1 year')));
$to = date('Y-m-d', strtotime('yesterday'));

$url = WEATHER_ARCHIVE . '?' . http_build_query(['latitude' => WEATHER_LATITUDE, 'longitude' => WEATHER_LONGITUDE,
    'start_date' => $from, 'end_date' => $to, 'daily' => 'temperature_2m_mean', 'timezone' => 'Europe/London']);
$json = json_decode((string)file_get_contents($url), true);
if (!isset($json['daily']['time'])) {
    fwrite(STDERR, "Open-Meteo: " . substr(json_encode($json), 0, 300) . "\n");
    exit(1);
}

$stmt = $pdo->prepare('INSERT INTO weather_daily (day, mean_temperature_c) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE mean_temperature_c = VALUES(mean_temperature_c)');
$rows = 0;
foreach ($json['daily']['time'] as $i => $day) {
    $t = $json['daily']['temperature_2m_mean'][$i];
    if ($t !== null) {
        $stmt->execute([$day, $t]);
        $rows++;
    }
}
echo "weather $from..$to: $rows days\n";
