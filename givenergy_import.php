<?php

declare(strict_types=1);

/*
 * GivEnergy daily datapoint importer
 *
 * Usage examples:
 *   php givenergy_import.php --api-key=xxx --inverter=CE12345678 --db-host=127.0.0.1 --db-name=solar --db-user=solar --db-pass=secret --from=2023-01-01 --to=2023-12-31
 *   php givenergy_import.php --config=/path/to/givenergy.ini --from=2023-01-01 --to=2026-04-13
 *   php givenergy_import.php --config=/path/to/givenergy.ini --date=2023-07-27
 *   php givenergy_import.php --config=/path/to/givenergy.ini --auto-catchup
 *   28th July 2023 - Octopus account opened.
 *   Octopus API goes back to April 2024 - so YoY compare...
 *   MyEnergi only gives 12 months worth of data
 *  
 *
 *
 * INI format:
 *   api_key = "..."
 *   inverter_serial = "CE12345678"
 *   db_host = "127.0.0.1"
 *   db_port = 3306
 *   db_name = "solar"
 *   db_user = "solar"
 *   db_pass = "secret"
 *   request_delay_ms = 300
 *   max_retries = 5
 */

const API_BASE = 'https://api.givenergy.cloud/v1';
const OCTOPUS_API_BASE = 'https://api.octopus.energy/v1';
const TABLE_DATAPOINTS = 'givenergy_datapoints';
const TABLE_IMPORT_RUNS = 'givenergy_import_runs';
const TABLE_DAILY_SUMMARY = 'givenergy_daily_summary';
const TABLE_ENERGY_FLOWS = 'givenergy_energy_flows';
const TABLE_OCTOPUS_CONSUMPTION = 'octopus_consumption';
const TABLE_OCTOPUS_DAILY_SUMMARY = 'octopus_daily_summary';
const DEFAULT_TIMEZONE = 'Europe/London';

require_once __DIR__ . '/src/SchemaManager.php';
require_once __DIR__ . '/src/DataNormaliser.php';
require_once __DIR__ . '/src/DataImporter.php';
require_once __DIR__ . '/src/DailySummaryManager.php';
require_once __DIR__ . '/src/GivEnergyClient.php';
require_once __DIR__ . '/src/EnergyFlowNormaliser.php';
require_once __DIR__ . '/src/EnergyFlowImporter.php';
require_once __DIR__ . '/src/OctopusClient.php';
require_once __DIR__ . '/src/OctopusNormaliser.php';
require_once __DIR__ . '/src/OctopusImporter.php';

main($argv);

function main(array $argv): void {
    $startTime = microtime(true);
    $opts = parseCommandLineOptions($argv);

    if (isset($opts['help'])) {
        usage();
        exit(0);
    }

    $settings = loadSettings($opts);
    $pdo = connectPdo($settings);

    $schema = new SchemaManager($pdo);
    $schema->ensureSchema();

    if (isset($opts['rebuild-summary'])) {
        handleRebuildSummary($pdo, $settings);
        exit(0);
    }

    [$from, $to] = determineDateRange($opts, $settings, $pdo);

    $client = new GivEnergyClient(
        $settings['api_key'],
        $settings['request_delay_ms'],
        $settings['max_retries']
    );

    $totalRows = importDataForDateRange($pdo, $client, $settings, $from, $to);

    outputCompletionSummary($startTime, $totalRows, $from, $to);
}

function parseCommandLineOptions(array $argv): array {
    return getopt('', [
        'config::', 'api-key::', 'inverter::', 'db-host::', 'db-port::',
        'db-name::', 'db-user::', 'db-pass::', 'from::', 'to::', 'date::',
        'request-delay-ms::', 'max-retries::', 'rebuild-summary::',
        'auto-catchup::', 'help::',
    ]);
}

function loadSettings(array $opts): array
{
    $config = loadConfigFile($opts);

    $settings = [
        // GivEnergy settings
        'api_key' => $opts['api-key'] ?? ($config['api_key'] ?? null),
        'inverter_serial' => $opts['inverter'] ?? ($config['inverter_serial'] ?? null),

        // Octopus Energy settings
        'octopus_api_key' => $config['octopus_api_key'] ?? null,
        'octopus_electric_import_mpan' => $config['octopus_electric_import_mpan'] ?? null,
        'octopus_electric_import_serial' => $config['octopus_electric_import_serial'] ?? null,
        'octopus_electric_export_mpan' => $config['octopus_electric_export_mpan'] ?? null,
        'octopus_electric_export_serial' => $config['octopus_electric_export_serial'] ?? null,
        'octopus_gas_mprn' => $config['octopus_gas_mprn'] ?? null,
        'octopus_gas_serial' => $config['octopus_gas_serial'] ?? null,

        // Database settings
        'db_host' => $opts['db-host'] ?? ($config['db_host'] ?? '127.0.0.1'),
        'db_port' => (int)($opts['db-port'] ?? ($config['db_port'] ?? 3306)),
        'db_name' => $opts['db-name'] ?? ($config['db_name'] ?? null),
        'db_user' => $opts['db-user'] ?? ($config['db_user'] ?? null),
        'db_pass' => $opts['db-pass'] ?? ($config['db_pass'] ?? null),
        'request_delay_ms' => (int)($opts['request-delay-ms'] ?? ($config['request_delay_ms'] ?? 300)),
        'max_retries' => (int)($opts['max-retries'] ?? ($config['max_retries'] ?? 5)),
    ];

    validateRequiredSettings($settings, ['db_name', 'db_user', 'db_pass']);

    return $settings;
}

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

function validateRequiredSettings(array $settings, array $required): void {
    foreach ($required as $key) {
        if (empty($settings[$key])) {
            throw new InvalidArgumentException("Missing required setting: {$key}");
        }
    }
}

function handleRebuildSummary(PDO $pdo, array $settings): void {
    if (empty($settings['inverter_serial'])) {
        throw new InvalidArgumentException("Missing required setting: inverter_serial");
    }

    $summary = new DailySummaryManager($pdo);
    $summary->rebuildForInverter($settings['inverter_serial']);
}

function determineDateRange(array $opts, array $settings, PDO $pdo): array {
    validateRequiredSettings($settings, ['api_key', 'inverter_serial']);

    if (isset($opts['auto-catchup'])) {
        return handleAutoCatchup($pdo, $settings);
    }

    return handleExplicitDateRange($opts);
}

function handleAutoCatchup(PDO $pdo, array $settings): array {
    $localTz = new DateTimeZone(DEFAULT_TIMEZONE);
    $yesterday = (new DateTimeImmutable('now', $localTz))->modify('-1 day');

    outputAutoCatchupInfo($localTz, $yesterday);

    $maxDate = findMaxDatapointDate($pdo, $settings['inverter_serial']);
    $from = calculateCatchupStartDate($maxDate, $yesterday, $localTz);

    if ($from > $yesterday) {
        fwrite(STDOUT, "Already up to date! Next import would be {$from->format('Y-m-d')}\n");
        exit(0);
    }

    $to = min($from->modify('+6 days'), $yesterday);
    outputCatchupPlan($from, $to);

    return [$from, $to];
}

function outputAutoCatchupInfo(DateTimeZone $tz, DateTimeImmutable $yesterday): void {
    $now = new DateTimeImmutable('now', $tz);
    fwrite(STDOUT, "Auto catch-up mode:\n");
    fwrite(STDOUT, "  Current time: {$now->format('Y-m-d H:i:s T')}\n");
    fwrite(STDOUT, "  Yesterday: {$yesterday->format('Y-m-d')}\n");
}

function findMaxDatapointDate(PDO $pdo, string $inverterSerial): ?string {
    $stmt = $pdo->prepare(
        'SELECT MAX(sample_date) FROM ' . TABLE_DATAPOINTS . ' WHERE inverter_serial = ?'
    );
    $stmt->execute([$inverterSerial]);
    return $stmt->fetchColumn() ?: null;
}

function calculateCatchupStartDate(?string $maxDate, DateTimeImmutable $yesterday, DateTimeZone $tz): DateTimeImmutable {
    if ($maxDate) {
        fwrite(STDOUT, "  Last imported: {$maxDate}\n");
        return (new DateTimeImmutable($maxDate, $tz))->modify('+1 day');
    }

    fwrite(STDOUT, "  No previous imports found\n");
    return $yesterday->modify('-6 days');
}

function outputCatchupPlan(DateTimeImmutable $from, DateTimeImmutable $to): void {
    $dayCount = (int)$from->diff($to)->days + 1;
    fwrite(STDOUT, "Importing {$dayCount} day(s): from {$from->format('Y-m-d')} to {$to->format('Y-m-d')}\n\n");
}

function handleExplicitDateRange(array $opts): array {
    $tz = new DateTimeZone(DEFAULT_TIMEZONE);

    if (!empty($opts['date'])) {
        $date = new DateTimeImmutable((string)$opts['date'], $tz);
        return [$date, $date];
    }

    $from = new DateTimeImmutable((string)($opts['from'] ?? 'today'), $tz);
    $to = new DateTimeImmutable((string)($opts['to'] ?? $from->format('Y-m-d')), $tz);

    if ($to < $from) {
        throw new InvalidArgumentException('--to must be on or after --from');
    }

    return [$from, $to];
}

function importDataForDateRange(PDO $pdo, GivEnergyClient $client, array $settings, DateTimeImmutable $from, DateTimeImmutable $to): int
{
    $totalDatapointRows = 0;
    $totalEnergyFlowRows = 0;
    $totalOctopusRows = 0;
    $datapointImporter = new DataImporter($pdo);
    $energyFlowImporter = new EnergyFlowImporter($pdo);

    $octopusClient = null;
    $octopusImporter = null;
    if (!empty($settings['octopus_api_key'])) {
        $octopusClient = new OctopusClient($settings['octopus_api_key'], $settings['request_delay_ms']);
        $octopusImporter = new OctopusImporter($pdo);
    }

    // Import both datapoints and energy flows day-by-day
    foreach (iterateDays($from, $to) as $day) {
        $date = $day->format('Y-m-d');
        fwrite(STDOUT, "Importing {$date}...\n");

        // Import GivEnergy datapoints
        $payload = $client->fetchDataPointsForDay($settings['inverter_serial'], $date);
        outputPayloadDebugInfo($payload);

        $rows = normalisePayload($settings['inverter_serial'], $payload);
        outputNormalisedDebugInfo($rows);

        $inserted = $datapointImporter->upsertRows($rows, $payload, $settings['inverter_serial'], $date);
        fwrite(STDOUT, "  Datapoints: upserted {$inserted} row(s)\n");
        $totalDatapointRows += $inserted;

        // Import GivEnergy energy flows
        $energyFlowRows = importEnergyFlowsForDay($energyFlowImporter, $client, $settings, $day);
        fwrite(STDOUT, "  Energy flows: upserted {$energyFlowRows} row(s)\n");
        $totalEnergyFlowRows += $energyFlowRows;

        // Import Octopus Energy consumption data for this day
        if ($octopusClient && $octopusImporter) {
            $octopusRows = importOctopusDataForDay($octopusClient, $octopusImporter, $settings, $date);
            fwrite(STDOUT, "  Octopus: imported {$octopusRows} consumption row(s)\n");
            $totalOctopusRows += $octopusRows;
        }
    }

    fwrite(STDOUT, sprintf(
        "\nTotal: %d datapoint rows, %d energy flow rows, %d Octopus rows\n",
        $totalDatapointRows,
        $totalEnergyFlowRows,
        $totalOctopusRows
    ));

    return $totalDatapointRows;
}

function importOctopusDataForDay(OctopusClient $client, OctopusImporter $importer, array $settings, string $date): int
{
    $totalRows = 0;

    // Import electricity import
    if (!empty($settings['octopus_electric_import_mpan']) && !empty($settings['octopus_electric_import_serial'])) {
        $results = $client->fetchElectricityConsumption(
            $settings['octopus_electric_import_mpan'],
            $settings['octopus_electric_import_serial'],
            $date,
            $date
        );

        $normaliser = new OctopusNormaliser(
            $settings['octopus_electric_import_mpan'],
            $settings['octopus_electric_import_serial'],
            'electricity_import'
        );
        $rows = $normaliser->normaliseConsumption($results);
        $totalRows += $importer->upsertConsumption($rows);
    }

    // Import electricity export
    if (!empty($settings['octopus_electric_export_mpan']) && !empty($settings['octopus_electric_export_serial'])) {
        $results = $client->fetchElectricityConsumption(
            $settings['octopus_electric_export_mpan'],
            $settings['octopus_electric_export_serial'],
            $date,
            $date
        );

        $normaliser = new OctopusNormaliser(
            $settings['octopus_electric_export_mpan'],
            $settings['octopus_electric_export_serial'],
            'electricity_export'
        );
        $rows = $normaliser->normaliseConsumption($results);
        $totalRows += $importer->upsertConsumption($rows);
    }

    // Import gas
    if (!empty($settings['octopus_gas_mprn']) && !empty($settings['octopus_gas_serial'])) {
        $results = $client->fetchGasConsumption(
            $settings['octopus_gas_mprn'],
            $settings['octopus_gas_serial'],
            $date,
            $date
        );

        $normaliser = new OctopusNormaliser(
            $settings['octopus_gas_mprn'],
            $settings['octopus_gas_serial'],
            'gas'
        );
        $rows = $normaliser->normaliseConsumption($results);
        $totalRows += $importer->upsertConsumption($rows);
    }

    return $totalRows;
}

function importEnergyFlowsForDay(EnergyFlowImporter $importer, GivEnergyClient $client, array $settings, DateTimeImmutable $day): int
{
    $startDate = $day->format('Y-m-d');
    $endDate = $day->modify('+1 day')->format('Y-m-d');
    $twoDateAhead = $day->modify('+2 day')->format('Y-m-d');

    $allRows = [];

    // Import 30-minute intervals (grouping=0)
    $halfHourlyData = $client->fetchEnergyFlows($settings['inverter_serial'], $startDate, $endDate, 0);
    $normaliser = new EnergyFlowNormaliser($settings['inverter_serial'], 0);
    $allRows = array_merge($allRows, $normaliser->normaliseData($halfHourlyData));

    // Import daily summary (grouping=1) - API returns 3 periods, filter to only the requested date
    $dailyData = $client->fetchEnergyFlows($settings['inverter_serial'], $startDate, $twoDateAhead, 1);
    $dailyNormaliser = new EnergyFlowNormaliser($settings['inverter_serial'], 1, $startDate);  // Pass filter date
    $allRows = array_merge($allRows, $dailyNormaliser->normaliseData($dailyData));

    fwrite(STDOUT, "  Processed " . count($allRows) . " total energy flow rows (30-min + daily)\n");

    return empty($allRows) ? 0 : $importer->upsertRows($allRows);
}
function outputPayloadDebugInfo(array $payload): void {
    if (empty($payload['data'])) {
        return;
    }

    $times = array_map(fn($row) => $row['time'] ?? 'unknown', $payload['data']);
    fwrite(STDOUT, "  Received " . count($payload['data']) . " rows\n");
    fwrite(STDOUT, "  First: " . reset($times) . "\n");
    fwrite(STDOUT, "  Last: " . end($times) . "\n");
}

function outputNormalisedDebugInfo(array $rows): void {
    if (empty($rows)) {
        return;
    }

    fwrite(STDOUT, "  Normalized to " . count($rows) . " rows\n");
    fwrite(STDOUT, "  First UTC: {$rows[0]['sample_time_utc']}\n");
    fwrite(STDOUT, "  Last UTC: " . end($rows)['sample_time_utc'] . "\n");
}

function outputCompletionSummary(float $startTime, int $totalRows, DateTimeImmutable $from, DateTimeImmutable $to): void {
    $duration = microtime(true) - $startTime;
    $dayCount = (int)$from->diff($to)->days + 1;

    fwrite(STDOUT, sprintf(
        "\nCompleted: imported data for %d day(s) in %.2f seconds\n",
        $dayCount,
        $duration
    ));
}

function usage(): void {
    $text = <<<TXT
Usage:
  php givenergy_import.php --config=/secure/path/givenergy.ini --from=2023-01-01 --to=2026-04-13
  php givenergy_import.php --config=/secure/path/givenergy.ini --date=2023-10-01
  php givenergy_import.php --config=/secure/path/givenergy.ini --auto-catchup
  php givenergy_import.php --config=/secure/path/givenergy.ini --rebuild-summary

Options:
  --auto-catchup    Import from last imported date to yesterday (max 7 days per run)
  --rebuild-summary Rebuild daily summary table from existing data

Note: Both datapoints and hourly energy flows are imported automatically for the date range.
TXT;
    fwrite(STDOUT, $text . PHP_EOL);
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

function iterateDays(DateTimeImmutable $from, DateTimeImmutable $to): Generator {
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        yield $day;
    }
}

function normalisePayload(string $inverterSerial, array $payload): array {
    $data = $payload['data'] ?? [];
    if (!is_array($data)) {
        return [];
    }

    $normaliser = new DataNormaliser($inverterSerial);
    $rows = array_map([$normaliser, 'normaliseRow'], $data);
    $rows = array_filter($rows);

    usort($rows, static fn($a, $b) => strcmp($a['sample_time_local'], $b['sample_time_local']));

    return $rows;
}

function extractTimestamp(array $row): ?string {
    foreach (['time', 'timestamp', 'datetime', 'updated_at', 'recorded_at'] as $key) {
        if (!empty($row[$key]) && is_string($row[$key])) {
            return $row[$key];
        }
    }
    return null;
}

function scalarOrNull(array $row, array $keys): ?string {
    foreach ($keys as $key) {
        $value = getNestedValue($row, $key);
        if ($value !== null && $value !== '') {
            return (string)$value;
        }
    }
    return null;
}

function decimalOrNull(array $row, array $keys): ?float {
    foreach ($keys as $key) {
        $value = getNestedValue($row, $key);
        if ($value !== null && $value !== '') {
            return (float)$value;
        }
    }
    return null;
}

function getNestedValue(array $data, string $path) {
    if (strpos($path, '.') === false) {
        return array_key_exists($path, $data) ? $data[$path] : null;
    }

    $parts = explode('.', $path);
    $current = $data;

    foreach ($parts as $part) {
        $part = is_numeric($part) ? (int)$part : $part;

        if (is_array($current) && array_key_exists($part, $current)) {
            $current = $current[$part];
        } else {
            return null;
        }
    }

    return $current;
}