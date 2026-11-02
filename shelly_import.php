<?php

declare(strict_types=1);

/*
 * Shelly Cloud importer: every power meter's energy and every temperature/humidity sensor's readings, into the
 * shelly_* tables (see SchemaManager). Needs shelly_server and shelly_auth_key in the config (Shelly app: User
 * settings, Authorization cloud key).
 *
 * Usage:
 *   php shelly_import.php --config=... --poll
 *       Live status of every device (one request): each power meter's energy for each of the last three minutes,
 *       and each sensor's latest reading. Run every 2 minutes to keep every minute.
 *   php shelly_import.php --config=... --auto-catchup [--from=2024-06-01]
 *       The cloud's hourly history, one request per device per day: from each device's last imported day (or
 *       --from, else yesterday, for a device with none) to yesterday. The first run with --from is the backfill.
 *   php shelly_import.php --config=... --from=2026-10-01 --to=2026-10-31
 *       The hourly history for those days, for every device.
 *   --device=id[,id...] limits any of these to those devices.
 *
 * Data is keyed on the device's hardware id and channel; shelly_device_names keeps each name a device has had.
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/SchemaManager.php';
require_once __DIR__ . '/src/ShellyClient.php';

// Device models by what they measure (the app's device type). Anything else (e.g. the i4 input) is skipped.
const SHELLY_POWER_MODELS = '/^(SNSW-|SNPL-|S3PM-|S3SW-|SHSW-PM|SHDM-)/';
const SHELLY_SENSOR_MODELS = '/^(SHHT-|SNSN-0013A|SBHT-)/';

main();

function main(): void {
    date_default_timezone_set('UTC');
    $opts = getopt('', ['config::', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'poll', 'auto-catchup',
        'from::', 'to::', 'device::']);
    $config = loadConfigFile($opts);
    validateRequiredSettings($config, ['shelly_server', 'shelly_auth_key']);
    $pdo = connectPdo(loadDbSettings($opts, $config));
    (new SchemaManager($pdo))->ensureSchema();
    $shelly = new ShellyClient($config['shelly_server'], $config['shelly_auth_key']);

    $devices = saveDevices($pdo, $shelly->devices());
    if (isset($opts['device'])) {
        $only = explode(',', $opts['device']);
        $devices = array_values(array_filter($devices, fn($d) => in_array($d['id'], $only, true)));
    }
    if (isset($opts['poll'])) {
        poll($pdo, $shelly->allStatus(), $devices);
        return;
    }

    $yesterday = date('Y-m-d', strtotime('yesterday'));
    $from = $opts['from'] ?? null;
    $to = $opts['to'] ?? $yesterday;
    if (!isset($opts['auto-catchup']) && $from === null) {
        fwrite(STDERR, "Give --poll, --auto-catchup or --from\n");
        exit(1);
    }
    foreach ($devices as $d) {
        if ($d['kind'] === 'other') {
            continue;
        }
        $start = isset($opts['auto-catchup']) ? (lastImportedDay($pdo, $d) ?? $from ?? $yesterday) : $from;
        $rows = 0;
        for ($day = $start; $day <= $to; $day = date('Y-m-d', strtotime("$day +1 day"))) {
            $rows += importDay($pdo, $shelly, $d, $day);
        }
        printf("%-28s %s..%s: %d hours\n", $d['name'], $start, $to, $rows);
    }
}

/** Store the app's device list, and a new name history row when a device is new or renamed; returns it with kinds. */
function saveDevices(PDO $pdo, array $devices): array {
    $stmt = $pdo->prepare('INSERT INTO shelly_devices (device_id, channel, name, model, kind) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), model = VALUES(model), kind = VALUES(kind)');
    $lastName = $pdo->prepare('SELECT name FROM shelly_device_names WHERE device_id = ? AND channel = ?
        ORDER BY from_utc DESC LIMIT 1');
    $newName = $pdo->prepare('INSERT INTO shelly_device_names (device_id, channel, from_utc, name) VALUES (?, ?, ?, ?)');
    foreach ($devices as &$d) {
        $d['kind'] = preg_match(SHELLY_POWER_MODELS, $d['model']) ? 'power'
            : (preg_match(SHELLY_SENSOR_MODELS, $d['model']) ? 'sensor' : 'other');
        $stmt->execute([$d['id'], $d['channel'], $d['name'], $d['model'], $d['kind']]);
        $lastName->execute([$d['id'], $d['channel']]);
        if ($lastName->fetchColumn() !== $d['name']) {
            $newName->execute([$d['id'], $d['channel'], gmdate('Y-m-d H:i:s'), $d['name']]);
        }
    }
    return $devices;
}

function lastImportedDay(PDO $pdo, array $d): ?string {
    $stmt = $pdo->prepare('SELECT DATE(MAX(hour_utc)) FROM shelly_hourly WHERE device_id = ? AND channel = ?');
    $stmt->execute([$d['id'], $d['channel']]);
    return $stmt->fetchColumn() ?: null;
}

/** One day of the cloud's hourly history for one device; its hours are in the timezone the cloud gives. */
function importDay(PDO $pdo, ShellyClient $shelly, array $d, string $day): int {
    $power = $d['kind'] === 'power';
    $r = $power ? $shelly->hourlyEnergy($d['id'], $d['channel'], $day) : $shelly->hourlySensor($d['id'], $day);
    $tz = new DateTimeZone($r['timezone'] ?? 'UTC');
    $stmt = $pdo->prepare('INSERT INTO shelly_hourly (device_id, channel, hour_utc, wh, voltage, min_temperature_c,
            max_temperature_c, humidity_pct) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE wh = VALUES(wh), voltage = VALUES(voltage), min_temperature_c = VALUES(min_temperature_c),
            max_temperature_c = VALUES(max_temperature_c), humidity_pct = VALUES(humidity_pct)');

    $rows = 0;
    foreach ($r['history'] ?? [] as $h) {
        // Sensors list hours they missed as "available": false; power meters list every hour.
        if ($power ? !isset($h['consumption']) : empty($h['available'])) {
            continue;
        }
        $hour = (new DateTimeImmutable($h['datetime'], $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $stmt->execute([$d['id'], $d['channel'], $hour,
            $power ? $h['consumption'] : null, $power ? ($h['voltage'] ?? null) : null,
            $power ? null : ($h['min_temperature'] ?? null), $power ? null : ($h['max_temperature'] ?? null),
            $power ? null : ($h['humidity'] ?? null)]);
        $rows++;
    }
    return $rows;
}

/**
 * Live status: Gen2+ power meters give the energy (mWh) of each of the last three complete minutes, newest first;
 * minute_ts is the start of the current, incomplete minute, so by_minute[0] is the minute before it
 * (shelly-api-docs.shelly.cloud/gen2/ComponentsAndServices/Switch, "aenergy"). Gen1 meters (garden lights, dimmer) don't, so they have hourly history only.
 * Sensors: the latest reading, at the device's own time where it gives one.
 */
function poll(PDO $pdo, array $status, array $devices): void {
    $minute = $pdo->prepare('INSERT INTO shelly_minutes (device_id, channel, minute_utc, wh) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE wh = VALUES(wh)');
    $reading = $pdo->prepare('INSERT IGNORE INTO shelly_sensor_readings (device_id, reading_utc, temperature_c, humidity_pct,
        battery_pct) VALUES (?, ?, ?, ?, ?)');

    [$minutes, $readings] = [0, 0];
    foreach ($devices as $d) {
        $s = $status[$d['id']] ?? null;
        if ($s === null) {
            continue;
        }
        if ($d['kind'] === 'power') {
            $meter = $s["switch:{$d['channel']}"] ?? $s["pm1:{$d['channel']}"] ?? $s["cover:{$d['channel']}"] ?? null;
            $energy = $meter['aenergy'] ?? null;
            if (!isset($energy['by_minute'], $energy['minute_ts'])) {
                continue;
            }
            foreach ($energy['by_minute'] as $i => $mwh) {
                $minute->execute([$d['id'], $d['channel'], gmdate('Y-m-d H:i:s', (int)$energy['minute_ts'] - 60 * ($i + 1)),
                    $mwh / 1000]);
                $minutes++;
            }
        } elseif ($d['kind'] === 'sensor') {
            $at = $s['ts'] ?? $s['sys']['unixtime'] ?? $s['unixtime'] ?? (isset($s['_updated']) ? strtotime($s['_updated'] . ' UTC') : null);
            if ($at === null) {
                continue;
            }
            $reading->execute([$d['id'], gmdate('Y-m-d H:i:s', (int)$at),
                $s['temperature:0']['tC'] ?? $s['tmp']['tC'] ?? null,
                $s['humidity:0']['rh'] ?? $s['hum']['value'] ?? null,
                // A sensor on USB power reports its (absent) battery as 0%.
                empty($s['devicepower:0']['external']['present'])
                    ? ($s['devicepower:0']['battery']['percent'] ?? $s['bat']['value'] ?? null) : null]);
            $readings += $reading->rowCount();
        }
    }
    printf("%s: %d power minutes, %d new sensor readings\n", gmdate('Y-m-d H:i:s'), $minutes, $readings);
}
