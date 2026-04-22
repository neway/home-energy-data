<?php

declare(strict_types=1);

final class DataImporter
{
    public function __construct(private PDO $pdo) {}

    public function upsertRows(array $rows, array $payload, string $inverterSerial, string $date): int
    {
        $this->pdo->beginTransaction();
        try {
            $count = $this->insertDatapoints($rows);
            $this->recordImportRun($inverterSerial, $date, $rows, $payload);

            $summary = new DailySummaryManager($this->pdo);
            $summary->updateForDate($inverterSerial, $date);

            $this->pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertDatapoints(array $rows): int
    {
        $sql = $this->buildInsertSql();
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach ($rows as $row) {
            $stmt->execute($row);
            $count++;
        }

        return $count;
    }

    private function buildInsertSql(): string
    {
        $columns = $this->getDatapointColumns();
        $placeholders = ':' . implode(', :', $columns);
        $updates = $this->buildUpdateClause($columns);

        return "INSERT INTO " . TABLE_DATAPOINTS . " (" . implode(', ', $columns) . ") 
                VALUES ({$placeholders}) 
                ON DUPLICATE KEY UPDATE {$updates}";
    }

    private function getDatapointColumns(): array
    {
        return [
            'inverter_serial', 'sample_time_local', 'sample_time_utc', 'sample_date', 'status',
            'grid_power_w', 'grid_voltage_v', 'grid_current_a', 'grid_frequency_hz',
            'pv_power_w', 'string_generation_1_w', 'string_generation_2_w', 'string_voltage_1_v',
            'string_voltage_2_v', 'string_current_1_a', 'string_current_2_a',
            'battery_power_w', 'battery_charge_w', 'battery_discharge_w', 'battery_voltage_v',
            'battery_percentage', 'battery_temperature_c',
            'inverter_power_w', 'inverter_voltage_v', 'inverter_frequency_hz', 'inverter_temperature_c',
            'demanded_power_w', 'eps_output_power_w',
            'consumption_energy_today_kwh', 'solar_generation_energy_today_kwh',
            'grid_import_energy_today_kwh', 'grid_export_energy_today_kwh',
            'battery_charge_energy_today_kwh', 'battery_discharge_energy_today_kwh',
            'inverter_output_energy_today_kwh', 'ac_charge_energy_today_kwh',
            'raw_json'
        ];
    }

    private function buildUpdateClause(array $columns): string
    {
        $skipColumns = ['inverter_serial'];
        $updates = [];

        foreach ($columns as $col) {
            if (!in_array($col, $skipColumns, true)) {
                $updates[] = "{$col} = VALUES({$col})";
            }
        }

        return implode(', ', $updates);
    }

    private function recordImportRun(string $inverterSerial, string $date, array $rows, array $payload): void
    {
        $sql = "INSERT INTO " . TABLE_IMPORT_RUNS . " 
                (inverter_serial, requested_date, fetched_at_utc, row_count, api_status, notes)
                VALUES (:inverter_serial, :requested_date, UTC_TIMESTAMP(), :row_count, 200, :notes)
                ON DUPLICATE KEY UPDATE
                    fetched_at_utc = VALUES(fetched_at_utc),
                    row_count = VALUES(row_count),
                    api_status = VALUES(api_status),
                    notes = VALUES(notes)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'inverter_serial' => $inverterSerial,
            'requested_date' => $date,
            'row_count' => count($rows),
            'notes' => isset($payload['meta']) ? json_encode($payload['meta'], JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}