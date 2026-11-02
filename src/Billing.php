<?php

declare(strict_types=1);

/*
 * What the meters and bills say, against what GivEnergy saw: the car's charging (the charger is outside GivEnergy's
 * grid sensor, so the meter's import minus GivEnergy's is the car), EDF's bill lines, and the latest billed rates.
 * The meter's import is Octopus's half-hours until the EDF bills' half-hours begin.
 * Also what the battery did in each Axle grid event.
 */
final class Billing
{
    // A day the meter read this much more than GivEnergy is a day the car charged (they agree to 0.01 kWh otherwise).
    private const CHARGING_DAY_KWH = 2.0;

    public function __construct(private PDO $pdo) {}

    /**
     * By month ('Y-m'): meter import, GivEnergy import, the difference (the car), days counted and days the car
     * charged. Only whole days of meter data count (46-50 half-hours).
     */
    public function carByMonth(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            WITH meter AS (
                SELECT period_date AS d, SUM(consumption_kwh) AS kwh
                FROM octopus_consumption
                WHERE fuel_type = 'electricity_import' AND period_date BETWEEN :from1 AND :to1
                  AND period_date NOT IN (SELECT local_date FROM edf_halfhours)
                GROUP BY period_date
                HAVING COUNT(*) >= 46
                UNION ALL
                SELECT local_date, SUM(kwh) FROM edf_halfhours WHERE local_date BETWEEN :from2 AND :to2 GROUP BY local_date
            )
            SELECT DATE_FORMAT(m.d, '%Y-%m') AS month, COUNT(*) AS days,
                   SUM(m.kwh) AS meter, SUM(g.grid_import_energy_kwh) AS givenergy,
                   SUM(m.kwh - g.grid_import_energy_kwh) AS car,
                   SUM(m.kwh - g.grid_import_energy_kwh >= :charging) AS car_days
            FROM meter m JOIN givenergy_daily_summary g ON g.summary_date = m.d
            GROUP BY month
            ORDER BY month
            SQL);
        $stmt->execute(['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to, 'charging' => self::CHARGING_DAY_KWH]);

        $months = [];
        foreach ($stmt as $row) {
            $months[$row['month']] = array_map('floatval', array_diff_key($row, ['month' => 1]));
        }
        return $months;
    }

    /** Every EDF bill line, newest bill first, with its bill's date and credits, and GivEnergy's import and export over the same days. */
    public function charges(): array
    {
        return $this->pdo->query(<<<SQL
            SELECT c.*, b.bill_date, b.credits_gbp,
                   SUM(g.grid_import_energy_kwh) AS givenergy_import,
                   SUM(g.grid_export_energy_kwh) AS givenergy_export
            FROM edf_charges c
            JOIN edf_bills b ON b.bill_ref = c.bill_ref
            LEFT JOIN givenergy_daily_summary g ON g.summary_date BETWEEN c.period_from AND c.period_to
            GROUP BY c.bill_ref, c.supply, c.period_from
            ORDER BY b.bill_date DESC, c.period_from DESC
            SQL)->fetchAll();
    }

    /**
     * Standing charges and the gas unit rate from the latest bill for each supply, or null with no bills.
     *
     * @return array{elec_standing_p: ?float, gas_p: ?float, gas_standing_p: ?float, as_of: ?string}
     */
    public function latestRates(): array
    {
        $latest = fn(string $supply) => $this->pdo->query(
            "SELECT * FROM edf_charges WHERE supply = '$supply' ORDER BY period_to DESC LIMIT 1")->fetch() ?: null;
        $import = $latest('import');
        $gas = $latest('gas');
        return [
            'elec_standing_p' => $import ? (float)$import['standing_p_per_day'] : null,
            'gas_p' => $gas ? (float)$gas['unit_rate_p'] : null,
            'gas_standing_p' => $gas ? (float)$gas['standing_p_per_day'] : null,
            'as_of' => max($import['period_to'] ?? '', $gas['period_to'] ?? '') ?: null,
        ];
    }

    /**
     * Gas kWh from $from to $to: EDF's billed kWh for whole bill periods inside it, and before EDF's first gas bill,
     * Octopus's readings (m3) x $kwhPerM3.
     *
     * @return array{from: string, to: string, days: int, kwh: float, billed_kwh: float, m3: float}
     */
    public function gasYear(string $from, string $to, float $kwhPerM3): array
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT MIN(period_from) AS first, MAX(period_to) AS last, SUM(kwh) AS kwh, SUM(DATEDIFF(period_to, period_from) + 1) AS days
            FROM edf_charges WHERE supply = 'gas' AND period_from >= :from AND period_to <= :to
            SQL);
        $stmt->execute(['from' => $from, 'to' => $to]);
        $billed = $stmt->fetch();
        $octopusTo = $billed['first'] ? date('Y-m-d', strtotime($billed['first'] . ' -1 day')) : $to;

        $stmt = $this->pdo->prepare(<<<SQL
            SELECT MIN(period_date) AS first, MAX(period_date) AS last, COUNT(DISTINCT period_date) AS days, SUM(consumption_kwh) AS m3
            FROM octopus_consumption WHERE fuel_type = 'gas' AND period_date BETWEEN :from AND :to
            SQL);
        $stmt->execute(['from' => $from, 'to' => $octopusTo]);
        $octopus = $stmt->fetch();

        return [
            'from' => $octopus['first'] ?? $billed['first'],
            'to' => $billed['last'] ?? $octopus['last'],
            'days' => (int)$octopus['days'] + (int)$billed['days'],
            'kwh' => (float)$octopus['m3'] * $kwhPerM3 + (float)$billed['kwh'],
            'billed_kwh' => (float)$billed['kwh'],
            'm3' => (float)$octopus['m3'],
        ];
    }

    /**
     * Each Axle event ([date, start, end, £ paid], local time) with GivEnergy's counters over it: kWh exported,
     * imported and out of the battery, and the battery's % at the start and end. Null figures: no samples then.
     */
    public function gridEvents(array $events): array
    {
        $at = $this->pdo->prepare(<<<SQL
            SELECT grid_export_energy_today_kwh AS export, grid_import_energy_today_kwh AS import,
                   battery_discharge_energy_today_kwh AS battery_out, battery_percentage AS soc
            FROM givenergy_datapoints
            WHERE sample_date = :date AND sample_time_local BETWEEN :time AND :time_to
            ORDER BY sample_time_local
            LIMIT 1
            SQL);
        $sample = function (string $date, string $time) use ($at) {
            $at->execute(['date' => $date, 'time' => "$date $time",
                'time_to' => date('Y-m-d H:i:s', strtotime("$date $time") + 600)]);
            return $at->fetch() ?: null;
        };

        $out = [];
        foreach ($events as [$date, $start, $end, $paid]) {
            $a = $sample($date, $start);
            $b = $sample($date, $end);
            $delta = fn(string $k) => $a && $b ? (float)$b[$k] - (float)$a[$k] : null;
            $out[] = ['date' => $date, 'start' => $start, 'end' => $end, 'paid' => (float)$paid,
                'export' => $delta('export'), 'import' => $delta('import'), 'battery_out' => $delta('battery_out'),
                'soc_start' => $a['soc'] ?? null, 'soc_end' => $b['soc'] ?? null];
        }
        return $out;
    }
}
