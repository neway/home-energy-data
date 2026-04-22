<?php

declare(strict_types=1);

final class EnergyFlowImporter
{
    public function __construct(private PDO $pdo) {}

    public function upsertRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $count = $this->insertEnergyFlows($rows);
            $this->pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertEnergyFlows(array $rows): int
    {
        $sql = $this->buildInsertSql();
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach ($rows as $row) {
            // raw_json is already set in the normaliser
            $stmt->execute($row);
            $count++;
        }

        return $count;
    }

    private function buildInsertSql(): string
    {
        $columns = $this->getEnergyFlowColumns();
        $placeholders = ':' . implode(', :', $columns);
        $updates = $this->buildUpdateClause($columns);

        return "INSERT INTO " . TABLE_ENERGY_FLOWS . " (" . implode(', ', $columns) . ") 
                VALUES ({$placeholders}) 
                ON DUPLICATE KEY UPDATE {$updates}";
    }

    private function getEnergyFlowColumns(): array
    {
        return [
            'inverter_serial',
            'period_start',
            'period_end',
            'period_date',
            'grouping_type',
            'pv_to_home_kwh',
            'pv_to_battery_kwh',
            'pv_to_grid_kwh',
            'grid_to_home_kwh',
            'grid_to_battery_kwh',
            'battery_to_home_kwh',
            'battery_to_grid_kwh',
            'raw_json'
        ];
    }

    private function buildUpdateClause(array $columns): string
    {
        $skipColumns = ['inverter_serial', 'period_start', 'grouping_type'];
        $updates = [];

        foreach ($columns as $col) {
            if (!in_array($col, $skipColumns, true)) {
                $updates[] = "{$col} = VALUES({$col})";
            }
        }

        return implode(', ', $updates);
    }
}