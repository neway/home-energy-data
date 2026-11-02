<?php

declare(strict_types=1);

/*
 * Capacity the battery has actually demonstrated, two ways (see README.md):
 *  - slices: kWh out per 1% SOC drop in each 10% band, x10, summed over the ten bands
 *  - full cycles: a discharge from >= 99% to <= 2% on one day with no charging between, read off the discharge counter
 */
final class BatteryCapacity
{
    // A band needs this much SOC drop in the window before its kWh/% is trusted.
    private const MIN_BAND_DROP_PCT = 5;

    public function __construct(private PDO $pdo) {}

    /** @return array{bands: array<int, ?float>, capacity_kwh: ?float} kWh per 1% for bands 0 (0-9%) .. 9 (90-100%) */
    public function slices(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT FLOOR(LEAST(prev_soc, 99) / 10) AS band,
                   SUM(prev_soc - soc) AS soc_drop,
                   SUM(dis - prev_dis) AS kwh
            FROM (
                SELECT sample_date, sample_time_local,
                       battery_percentage AS soc,
                       battery_power_w AS bp,
                       battery_discharge_energy_today_kwh AS dis,
                       battery_charge_energy_today_kwh AS chg,
                       LAG(sample_date) OVER w AS prev_date,
                       LAG(sample_time_local) OVER w AS prev_time,
                       LAG(battery_percentage) OVER w AS prev_soc,
                       LAG(battery_power_w) OVER w AS prev_bp,
                       LAG(battery_discharge_energy_today_kwh) OVER w AS prev_dis,
                       LAG(battery_charge_energy_today_kwh) OVER w AS prev_chg
                FROM givenergy_datapoints
                WHERE sample_date BETWEEN :from AND :to
                WINDOW w AS (ORDER BY sample_time_local)
            ) steps
            WHERE prev_date = sample_date
              AND TIMESTAMPDIFF(SECOND, prev_time, sample_time_local) BETWEEN 1 AND 900
              AND prev_bp > 50 AND bp > 50
              AND chg = prev_chg AND dis >= prev_dis
            GROUP BY band
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);

        $bands = array_fill(0, 10, null);
        foreach ($stmt as $row) {
            if ((float)$row['soc_drop'] >= self::MIN_BAND_DROP_PCT) {
                $bands[(int)$row['band']] = (float)$row['kwh'] / (float)$row['soc_drop'];
            }
        }

        $complete = !in_array(null, $bands, true);
        return [
            'bands' => $bands,
            'capacity_kwh' => $complete ? array_sum($bands) * 10 : null,
        ];
    }

    /** @return list<array{date: string, start: string, end: string, kwh: float}> */
    public function fullCycles(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT sample_date, sample_time_local, battery_percentage AS soc,
                   battery_discharge_energy_today_kwh AS dis, battery_charge_energy_today_kwh AS chg
            FROM givenergy_datapoints
            WHERE sample_date BETWEEN :from AND :to
            ORDER BY sample_time_local
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);

        // From the first sample at the top once charging stops, to the last sample at the bottom before it charges again.
        $cycles = [];
        $top = $bottom = null;
        foreach ($stmt as $row) {
            if ($top !== null && ($top['sample_date'] !== $row['sample_date'] || $row['chg'] - $top['chg'] > 0.15)) {
                if ($bottom !== null) {
                    $cycles[] = $this->cycle($top, $bottom);
                }
                $top = $bottom = null;
            }
            if ($top === null) {
                $top = $row['soc'] >= 99 ? $row : null;
            } elseif ($row['soc'] <= 2) {
                $bottom = $row;
            }
        }
        if ($bottom !== null) {
            $cycles[] = $this->cycle($top, $bottom);
        }

        return $cycles;
    }

    private function cycle(array $top, array $bottom): array
    {
        return [
            'date' => $bottom['sample_date'],
            'start' => substr($top['sample_time_local'], 11, 5),
            'end' => substr($bottom['sample_time_local'], 11, 5),
            'kwh' => (float)$bottom['dis'] - (float)$top['dis'],
        ];
    }
}
