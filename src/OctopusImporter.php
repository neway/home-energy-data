<?php

declare(strict_types=1);

final class OctopusImporter
{
    public function __construct(private PDO $pdo) {}

    public function upsertConsumption(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $count = $this->insertConsumption($rows);
            $this->updateDailySummaries($rows);
            $this->pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertConsumption(array $rows): int
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
        $columns = ['mpan_mprn', 'meter_serial', 'fuel_type', 'period_start', 'period_end', 'period_date', 'consumption_kwh', 'raw_json'];
        $placeholders = ':' . implode(', :', $columns);

        return "INSERT INTO " . TABLE_OCTOPUS_CONSUMPTION . " (" . implode(', ', $columns) . ") 
                VALUES ({$placeholders}) 
                ON DUPLICATE KEY UPDATE
                    period_end = VALUES(period_end),
                    consumption_kwh = VALUES(consumption_kwh),
                    raw_json = VALUES(raw_json)";
    }

    private function updateDailySummaries(array $rows): void
    {
        // Group by meter and date
        $grouped = [];
        foreach ($rows as $row) {
            $key = "{$row['mpan_mprn']}|{$row['meter_serial']}|{$row['fuel_type']}|{$row['period_date']}";
            $grouped[$key][] = $row;
        }

        foreach ($grouped as $key => $dayRows) {
            $this->updateDailySummary($dayRows);
        }
    }

    private function updateDailySummary(array $dayRows): void
    {
        $first = $dayRows[0];
        $consumptions = array_column($dayRows, 'consumption_kwh');

        $sql = "INSERT INTO " . TABLE_OCTOPUS_DAILY_SUMMARY . " 
                (mpan_mprn, meter_serial, fuel_type, summary_date, total_consumption_kwh, period_count, min_consumption_kwh, max_consumption_kwh, avg_consumption_kwh)
                VALUES (:mpan_mprn, :meter_serial, :fuel_type, :summary_date, :total, :count, :min, :max, :avg)
                ON DUPLICATE KEY UPDATE
                    total_consumption_kwh = VALUES(total_consumption_kwh),
                    period_count = VALUES(period_count),
                    min_consumption_kwh = VALUES(min_consumption_kwh),
                    max_consumption_kwh = VALUES(max_consumption_kwh),
                    avg_consumption_kwh = VALUES(avg_consumption_kwh)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'mpan_mprn' => $first['mpan_mprn'],
            'meter_serial' => $first['meter_serial'],
            'fuel_type' => $first['fuel_type'],
            'summary_date' => $first['period_date'],
            'total' => array_sum($consumptions),
            'count' => count($consumptions),
            'min' => min($consumptions),
            'max' => max($consumptions),
            'avg' => array_sum($consumptions) / count($consumptions),
        ]);
    }
}