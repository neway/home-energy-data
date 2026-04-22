<?php

declare(strict_types=1);

final class DailySummaryManager
{
    public function __construct(private PDO $pdo) {}

    public function rebuildForInverter(string $inverterSerial): void
    {
        fwrite(STDOUT, "Rebuilding daily summary for inverter {$inverterSerial}...\n");

        $dates = $this->getDistinctDates($inverterSerial);
        $count = 0;

        foreach ($dates as $date) {
            $this->updateForDate($inverterSerial, $date);
            $count++;

            if ($count % 100 === 0) {
                fwrite(STDOUT, "Processed {$count} days...\n");
            }
        }

        fwrite(STDOUT, "Completed: rebuilt {$count} daily summaries\n");
    }

    private function getDistinctDates(string $inverterSerial): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT sample_date FROM ' . TABLE_DATAPOINTS . ' 
             WHERE inverter_serial = ? ORDER BY sample_date'
        );
        $stmt->execute([$inverterSerial]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function updateForDate(string $inverterSerial, string $date): void
    {
        $sql = "INSERT INTO " . TABLE_DAILY_SUMMARY . " (
                inverter_serial, summary_date, sample_time_local, sample_time_utc,
                consumption_energy_kwh, solar_generation_energy_kwh,
                grid_import_energy_kwh, grid_export_energy_kwh,
                battery_charge_energy_kwh, battery_discharge_energy_kwh,
                inverter_output_energy_kwh, ac_charge_energy_kwh
            )
            SELECT
                inverter_serial, sample_date, sample_time_local, sample_time_utc,
                consumption_energy_today_kwh, solar_generation_energy_today_kwh,
                grid_import_energy_today_kwh, grid_export_energy_today_kwh,
                battery_charge_energy_today_kwh, battery_discharge_energy_today_kwh,
                inverter_output_energy_today_kwh, ac_charge_energy_today_kwh
            FROM " . TABLE_DATAPOINTS . "
            WHERE inverter_serial = :inverter_serial
              AND sample_date = :date
              AND consumption_energy_today_kwh IS NOT NULL
            ORDER BY sample_time_utc DESC
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                sample_time_local = VALUES(sample_time_local),
                sample_time_utc = VALUES(sample_time_utc),
                consumption_energy_kwh = VALUES(consumption_energy_kwh),
                solar_generation_energy_kwh = VALUES(solar_generation_energy_kwh),
                grid_import_energy_kwh = VALUES(grid_import_energy_kwh),
                grid_export_energy_kwh = VALUES(grid_export_energy_kwh),
                battery_charge_energy_kwh = VALUES(battery_charge_energy_kwh),
                battery_discharge_energy_kwh = VALUES(battery_discharge_energy_kwh),
                inverter_output_energy_kwh = VALUES(inverter_output_energy_kwh),
                ac_charge_energy_kwh = VALUES(ac_charge_energy_kwh)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'inverter_serial' => $inverterSerial,
            'date' => $date,
        ]);
    }
}