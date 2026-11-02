<?php

declare(strict_types=1);

/*
 * The Shelly data the report uses (shelly_import.php loads it): energy by metered device, the always-on baseload,
 * and the garage's humidity against outside and its dehumidifier. Hours are stored in UTC; peak and off-peak are by
 * the UK hour. See README.md.
 */
final class ShellyData
{
    // A dehumidifier hour over this is a running hour (standby is a few Wh).
    private const RUNNING_WH = 50.0;
    // Outside air at least this much drier (g/m3) is worth letting in.
    private const DRIER_BY = 0.5;
    private const RH_LIMIT = 70.0;

    private DateTimeZone $uk;

    public function __construct(private PDO $pdo)
    {
        $this->uk = new DateTimeZone('Europe/London');
    }

    /** UTC bounds of UK days $from to $to inclusive. */
    public function utcRange(string $from, string $to): array
    {
        $at = fn(string $d) => (new DateTimeImmutable($d, $this->uk))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [$at($from), $at(date('Y-m-d', strtotime("$to +1 day")))];
    }

    /**
     * kWh by metered device (UK days $from to $to): off-peak, peak, and the first day with data, largest first.
     *
     * @return list<array{name: string, off: float, peak: float, first: string}>
     */
    public function deviceUse(string $from, string $to): array
    {
        $out = [];
        foreach ($this->powerHours($from, $to) as [$key, $name, $hour, $wh]) {
            $o = &$out[$key];
            $o ??= ['name' => $name, 'off' => 0.0, 'peak' => 0.0, 'first' => substr($hour, 0, 10)];
            $o[$this->offPeak($hour) ? 'off' : 'peak'] += $wh / 1000;
            unset($o);
        }
        $out = array_values($out);
        usort($out, fn($a, $b) => ($b['off'] + $b['peak']) <=> ($a['off'] + $a['peak']));
        return $out;
    }

    /**
     * The always-on draw: for the house (GivEnergy's demanded power) and each metered device, each day's lowest
     * hourly average, median over the days from $from to $to. (Not a fixed night window: loads timed for the cheap
     * rate would count as always on.)
     *
     * @return array{house_w: ?float, devices: array<string, float>}
     */
    public function baseload(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT sample_date, MIN(w) FROM (
                SELECT sample_date, HOUR(sample_time_local) AS h, AVG(demanded_power_w) AS w
                FROM givenergy_datapoints
                WHERE sample_date BETWEEN :from AND :to AND status = 'NORMAL'
                GROUP BY sample_date, h
                HAVING COUNT(*) >= 6
            ) hours
            GROUP BY sample_date
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);
        $house = array_map('floatval', $stmt->fetchAll(PDO::FETCH_COLUMN, 1));

        $days = [];
        foreach ($this->powerHours($from, $to) as [, $name, $hour, $wh]) {
            $day = $this->local($hour)->format('Y-m-d');
            $days[$name][$day] = min($days[$name][$day] ?? INF, $wh);
        }
        $devices = array_map(fn($byDay) => self::median(array_values($byDay)), $days);
        arsort($devices);
        return ['house_w' => $house ? self::median($house) : null, 'devices' => $devices];
    }

    /**
     * The garage by period: 'Last 7 days' to $to, then each month (newest first) from $from. Inside and outside
     * temperature, RH and absolute humidity, hours over RH_LIMIT, the dehumidifier's kWh (off-peak, peak) and running
     * hours, hours when outside was DRIER_BY drier, and the leakiness: hours for the garage to take up 63% of a
     * change in outside moisture.
     */
    public function garage(string $inside, string $outside, string $dehumidifier, string $from, string $to): array
    {
        $in = $this->sensorHours($inside, $from, $to);
        $out = $this->sensorHours($outside, $from, $to);
        $wh = [];
        foreach ($this->powerHours($from, $to, $dehumidifier) as [, , $hour, $w]) {
            $wh[$hour] = $w;
        }

        $hours = [];
        foreach ($in as $hour => [$ti, $hi]) {
            if (!isset($out[$hour])) {
                continue;
            }
            [$te, $he] = $out[$hour];
            $hours[$hour] = ['ti' => $ti, 'hi' => $hi, 'te' => $te, 'he' => $he, 'ai' => self::absolute($ti, $hi),
                'ae' => self::absolute($te, $he), 'wh' => $wh[$hour] ?? 0.0];
        }

        $periods = ['Last 7 days' => []];
        $weekFrom = $this->utcRange(date('Y-m-d', strtotime("$to -6 days")), $to)[0];
        foreach ($hours as $hour => $h) {
            if ($hour >= $weekFrom) {
                $periods['Last 7 days'][$hour] = $h;
            }
            $periods[$this->local($hour)->format('Y-m')][$hour] = $h;
        }
        $last = array_shift($periods);
        krsort($periods);
        $periods = ['Last 7 days' => $last] + $periods;

        return array_map(fn($p) => $p ? $this->garageStats($p) : null, $periods);
    }

    private function garageStats(array $hours): array
    {
        $n = count($hours);
        $mean = fn(string $k) => array_sum(array_column($hours, $k)) / $n;
        $off = $peak = 0.0;
        foreach ($hours as $hour => $h) {
            $this->offPeak($hour) ? $off += $h['wh'] / 1000 : $peak += $h['wh'] / 1000;
        }
        return [
            'hours' => $n,
            'ti' => $mean('ti'), 'te' => $mean('te'), 'hi' => $mean('hi'), 'he' => $mean('he'),
            'ai' => $mean('ai'), 'ae' => $mean('ae'),
            'over_limit' => count(array_filter($hours, fn($h) => $h['hi'] > self::RH_LIMIT)) / $n,
            'dehum_off' => $off, 'dehum_peak' => $peak,
            'running' => count(array_filter($hours, fn($h) => $h['wh'] > self::RUNNING_WH)),
            'drier' => count(array_filter($hours, fn($h) => $h['ae'] < $h['ai'] - self::DRIER_BY)) / $n,
            'leak_hours' => self::leakHours($hours),
        ];
    }

    /**
     * How fast outside moisture reaches the garage: over 3-hour steps, the share b of an outside change that shows
     * inside (least squares), as the time constant -3 / ln(1 - b) hours. Null when it can't be measured.
     */
    private static function leakHours(array $hours): ?float
    {
        $xs = $ys = [];
        foreach ($hours as $hour => $h) {
            $later = gmdate('Y-m-d H:i:s', strtotime("$hour UTC") + 3 * 3600);
            if (isset($hours[$later])) {
                $xs[] = $hours[$later]['ae'] - $h['ae'];
                $ys[] = $hours[$later]['ai'] - $h['ai'];
            }
        }
        if (count($xs) < 48) {
            return null;
        }
        $mx = array_sum($xs) / count($xs);
        $my = array_sum($ys) / count($ys);
        $sxx = $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0;
        return $b > 0 && $b < 1 ? -3 / log(1 - $b) : null;
    }

    /** kWh by UK month ('Y-m') for one meter: ['off' => ..., 'peak' => ...]. */
    public function energyByMonth(string $device, int $channel, string $from, string $to): array
    {
        $months = [];
        foreach ($this->powerHours($from, $to, $device, $channel) as [, , $hour, $wh]) {
            $m = &$months[$this->local($hour)->format('Y-m')];
            $m ??= ['off' => 0.0, 'peak' => 0.0];
            $m[$this->offPeak($hour) ? 'off' : 'peak'] += $wh / 1000;
            unset($m);
        }
        return $months;
    }

    /** A sensor's mean temperature by UK month ('Y-m'). */
    public function temperatureByMonth(string $device, string $from, string $to): array
    {
        $sums = [];
        foreach ($this->sensorHours($device, $from, $to) as $hour => [$t]) {
            $sums[$this->local($hour)->format('Y-m')][] = $t;
        }
        return array_map(fn($ts) => array_sum($ts) / count($ts), $sums);
    }

    /** [key, name, hour_utc, wh] for every power meter (or one meter), UK days $from to $to. */
    private function powerHours(string $from, string $to, ?string $device = null, int $channel = 0): Generator
    {
        [$a, $b] = $this->utcRange($from, $to);
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT h.device_id, h.channel, d.name, h.hour_utc, h.wh
            FROM shelly_hourly h JOIN shelly_devices d USING (device_id, channel)
            WHERE d.kind = 'power' AND h.wh IS NOT NULL AND h.hour_utc >= :a AND h.hour_utc < :b
              AND (:device IS NULL OR (h.device_id = :device2 AND h.channel = :channel))
            ORDER BY h.hour_utc
            SQL);
        $stmt->execute(['a' => $a, 'b' => $b, 'device' => $device, 'device2' => $device, 'channel' => $channel]);
        foreach ($stmt as $r) {
            yield ["{$r['device_id']}_{$r['channel']}", $r['name'], $r['hour_utc'], (float)$r['wh']];
        }
    }

    /**
     * A sensor's hours: [mean temperature, humidity] by hour_utc, from the cloud's hourly history, or where that has
     * no hour (the BLU sensors' history can stop), the average of the polled readings in it.
     */
    private function sensorHours(string $device, string $from, string $to): array
    {
        [$a, $b] = $this->utcRange($from, $to);
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT hour_utc, (min_temperature_c + max_temperature_c) / 2, humidity_pct, 0 AS polled
            FROM shelly_hourly
            WHERE device_id = :d1 AND channel = 0 AND hour_utc >= :a1 AND hour_utc < :b1 AND humidity_pct IS NOT NULL
            UNION ALL
            SELECT DATE_FORMAT(reading_utc, '%Y-%m-%d %H:00:00'), AVG(temperature_c), AVG(humidity_pct), 1
            FROM shelly_sensor_readings
            WHERE device_id = :d2 AND reading_utc >= :a2 AND reading_utc < :b2 AND humidity_pct IS NOT NULL
            GROUP BY 1
            ORDER BY polled
            SQL);
        $stmt->execute(['d1' => $device, 'a1' => $a, 'b1' => $b, 'd2' => $device, 'a2' => $a, 'b2' => $b]);
        $hours = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$hour, $t, $rh]) { // the cloud's hour first
            $hours[$hour] ??= [(float)$t, (float)$rh];
        }
        ksort($hours);
        return $hours;
    }

    private function local(string $hourUtc): DateTimeImmutable
    {
        return (new DateTimeImmutable($hourUtc, new DateTimeZone('UTC')))->setTimezone($this->uk);
    }

    private function offPeak(string $hourUtc): bool
    {
        $h = (int)$this->local($hourUtc)->format('G');
        return $h >= OFF_PEAK_START_HOUR || $h < OFF_PEAK_END_HOUR;
    }

    /** Grams of water per m3 of air, from temperature (C) and relative humidity (%). */
    public static function absolute(float $t, float $rh): float
    {
        return 6.112 * exp(17.67 * $t / ($t + 243.5)) * $rh * 2.1674 / (273.15 + $t);
    }

    private static function median(array $xs): float
    {
        sort($xs);
        $n = count($xs);
        return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }
}
