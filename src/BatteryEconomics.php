<?php

declare(strict_types=1);

/*
 * Energy flows by month, from two sources that check each other:
 *  - the 5-min samples, split into peak and off-peak, and replayed as if there were no battery
 *  - the inverter's own daily counters
 * Also replays the real peak-time imports through an extra battery. See README.md.
 */
final class BatteryEconomics
{
    // Samples further apart than this are a gap in the data, not 5 minutes of steady power.
    private const MAX_GAP_SECONDS = 900;

    public function __construct(private PDO $pdo) {}

    /**
     * kWh by month ('Y-m') from the samples: actual grid flows, the same load and solar with no battery,
     * the whole load (grid only), and time and energy above the extra inverter's power.
     */
    public function sampledFlows(string $from, string $to): array
    {
        $months = [];
        $lastDate = null;

        foreach ($this->steps($from, $to) as [$date, $offPeak, $loadKwh, $pvKwh, $gridKwh, $hours]) {
            $m = &$months[substr($date, 0, 7)];
            $m ??= array_fill_keys(['days', 'hours', 'hours_peak', 'pv', 'load_off', 'load_peak', 'imp_off', 'imp_peak', 'exp',
                'exp_battery',
                'nb_imp_off', 'nb_imp_peak', 'nb_exp', 'over_hours', 'over_kwh', 'over_hours_peak', 'over_kwh_peak'], 0.0);
            if ($date !== $lastDate) {
                $m['days']++;
                $lastDate = $date;
            }
            $period = $offPeak ? 'off' : 'peak';

            $m['hours'] += $hours;
            $m['hours_peak'] += $offPeak ? 0 : $hours;
            $m['pv'] += $pvKwh;
            $m["load_$period"] += $loadKwh;
            if ($gridKwh < 0) {
                $m["imp_$period"] -= $gridKwh;
            } else {
                // Export beyond what solar had spare at that moment came from the battery.
                $m['exp'] += $gridKwh;
                $m['exp_battery'] += $gridKwh - min($gridKwh, max($pvKwh - $loadKwh, 0));
            }

            $net = $loadKwh - $pvKwh;
            if ($net > 0) {
                $m["nb_imp_$period"] += $net;
            } else {
                $m['nb_exp'] -= $net;
            }

            $over = $loadKwh - EXTRA_INVERTER_KW * $hours;
            if ($over > 0) {
                $m['over_hours'] += $hours;
                $m['over_kwh'] += $over;
                if (!$offPeak) {
                    $m['over_hours_peak'] += $hours;
                    $m['over_kwh_peak'] += $over;
                }
            }
            unset($m);
        }

        ksort($months);
        return $months;
    }

    /**
     * kWh by month ('Y-m') from the inverter's daily counters. (Not givenergy_energy_flows: the API's flow data has
     * impossible spikes, e.g. 15.8 kWh battery to grid in half an hour.)
     */
    public function inverterTotals(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT DATE_FORMAT(summary_date, '%Y-%m') AS month,
                   SUM(solar_generation_energy_kwh) AS solar,
                   SUM(consumption_energy_kwh) AS home,
                   SUM(grid_import_energy_kwh) AS import,
                   SUM(grid_export_energy_kwh) AS export,
                   SUM(battery_charge_energy_kwh) AS battery_in,
                   SUM(ac_charge_energy_kwh) AS grid_to_battery,
                   SUM(battery_discharge_energy_kwh) AS battery_out
            FROM givenergy_daily_summary
            WHERE summary_date BETWEEN :from AND :to
            GROUP BY month
            ORDER BY month
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);

        $months = [];
        foreach ($stmt as $row) {
            $months[$row['month']] = array_map('floatval', array_diff_key($row, ['month' => 1]));
        }
        return $months;
    }

    /**
     * An extra battery alongside the existing system, for the house's own use only: fills off-peak, stores solar
     * that was being exported, and covers the peak-time imports that actually happened. Never exports.
     *
     * @return array{shifted_kwh: float, saving_gbp: float, stored_kwh: float}
     */
    public function extraCapacity(string $from, string $to, float $capacityKwh): array
    {
        $soc = $capacityKwh;
        $pence = 0.0;
        $shifted = 0.0;
        $stored = 0.0;

        foreach ($this->steps($from, $to) as [, $offPeak, , $pvKwh, $gridKwh, $hours]) {
            if ($offPeak) {
                $pence += ($capacityKwh - $soc) / BATTERY_EFFICIENCY * OFF_PEAK_P;
                $soc = $capacityKwh;
                continue;
            }

            if ($gridKwh > 0) {
                $store = min($gridKwh, $pvKwh, ($capacityKwh - $soc) / BATTERY_EFFICIENCY);
                $soc += $store * BATTERY_EFFICIENCY;
                $pence += $store * EXPORT_P;
                $stored += $store;
            } else {
                $give = min(-$gridKwh, $soc * BATTERY_EFFICIENCY, EXTRA_INVERTER_KW * $hours);
                $soc -= $give / BATTERY_EFFICIENCY;
                $shifted += $give;
                $pence -= $give * PEAK_P;
            }
        }

        return ['shifted_kwh' => $shifted, 'saving_gbp' => -$pence / 100, 'stored_kwh' => $stored];
    }

    /** Yields [date, offPeak, loadKwh, pvKwh, gridKwh (+ export, - import), hours] per step between NORMAL samples. */
    private function steps(string $from, string $to): Generator
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT sample_date, sample_time_local, demanded_power_w, pv_power_w, grid_power_w
            FROM givenergy_datapoints
            WHERE sample_date BETWEEN :from AND :to AND status = 'NORMAL'
            ORDER BY sample_time_local
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);

        $prev = null;
        foreach ($stmt as $row) {
            $time = strtotime($row['sample_time_local']);
            if ($prev !== null && $time - $prev > 0 && $time - $prev <= self::MAX_GAP_SECONDS) {
                $hours = ($time - $prev) / 3600;
                $hour = (int)substr($row['sample_time_local'], 11, 2);
                yield [
                    $row['sample_date'],
                    $hour >= OFF_PEAK_START_HOUR || $hour < OFF_PEAK_END_HOUR,
                    (float)$row['demanded_power_w'] * $hours / 1000,
                    (float)$row['pv_power_w'] * $hours / 1000,
                    (float)$row['grid_power_w'] * $hours / 1000,
                    $hours,
                ];
            }
            $prev = $time;
        }
    }
}
