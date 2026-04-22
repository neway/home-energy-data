<?php

declare(strict_types=1);

final class EnergyFlowNormaliser
{
    public function __construct(
        private string $inverterSerial,
        private int $groupingType,
        private ?string $requestedDate = null  // Filter for daily grouping
    ) {}

    public function normaliseData(array $apiResponse): array
    {
        if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
            fwrite(STDERR, "Energy flows normaliser: missing or invalid 'data' key\n");
            return [];
        }

        $rows = [];

        // Each item in the data array is a time period with energy values
        foreach ($apiResponse['data'] as $period) {
            if (!is_array($period)) {
                continue;
            }

            // Extract the period information
            $startTime = $period['start_time'] ?? null;
            $endTime = $period['end_time'] ?? null;
            $energyData = $period['data'] ?? [];

            if (!$startTime || !is_array($energyData)) {
                continue;
            }

            // For daily grouping, only include the period matching the requested date
            // The start_time determines which day the data represents (end_time is buggy)
            if ($this->groupingType === 1 && $this->requestedDate !== null) {
                $periodDate = substr($startTime, 0, 10); // Extract "YYYY-MM-DD" from "2026-04-01 00:00"
                if ($periodDate !== $this->requestedDate) {
                    fwrite(STDOUT, "  Skipping daily period for {$periodDate} (requested {$this->requestedDate})\n");
                    continue;
                }
            }

            $row = $this->createBaseRow($startTime, $endTime);

            // Add energy values for each type
            foreach ($energyData as $type => $value) {
                $row = $this->addEnergyValue($row, (int)$type, $value);
            }

            // Store the raw period data (not the entire API response)
            $row['raw_json'] = json_encode($period, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $rows[] = $row;
        }

        fwrite(STDOUT, "  Processed " . count($rows) . " energy flow periods\n");

        // Sort by period start time
        usort($rows, fn($a, $b) => strcmp($a['period_start'], $b['period_start']));

        return $rows;
    }

    private function createBaseRow(string $startTime, ?string $endTime): array
    {
        $localTz = new DateTimeZone(DEFAULT_TIMEZONE);

        // GivEnergy API returns times in inverter's local time, not UTC
        $startLocal = new DateTimeImmutable($startTime, $localTz);

        // Calculate end time based on grouping type
        // For daily grouping (1), ignore the buggy API end_time and calculate our own
        $endLocal = match($this->groupingType) {
            0 => $startLocal->modify('+30 minutes'),
            1 => new DateTimeImmutable($startLocal->format('Y-m-d') . ' 23:59:59', $localTz),  // End of same day
            default => throw new InvalidArgumentException("Invalid grouping type: {$this->groupingType}")
        };

        return [
            'inverter_serial' => $this->inverterSerial,
            'period_start' => $startLocal->format('Y-m-d H:i:s'),
            'period_end' => $endLocal->format('Y-m-d H:i:s'),
            'period_date' => $startLocal->format('Y-m-d'),
            'grouping_type' => $this->groupingType,
            'pv_to_home_kwh' => null,
            'pv_to_battery_kwh' => null,
            'pv_to_grid_kwh' => null,
            'grid_to_home_kwh' => null,
            'grid_to_battery_kwh' => null,
            'battery_to_home_kwh' => null,
            'battery_to_grid_kwh' => null,
        ];
    }
    private function addEnergyValue(array $row, int $type, $value): array
    {
        $floatValue = $value !== null && $value !== '' ? (float)$value : null;

        $column = match($type) {
            0 => 'pv_to_home_kwh',        // PV to Home: Solar used immediately for consumption
            1 => 'pv_to_battery_kwh',     // PV to Battery: Solar used to charge battery
            2 => 'pv_to_grid_kwh',        // PV to Grid: Solar exported to grid
            3 => 'grid_to_home_kwh',      // Grid to Home: Grid energy used for consumption
            4 => 'grid_to_battery_kwh',   // Grid to Battery: Grid energy used to charge battery
            5 => 'battery_to_home_kwh',   // Battery to Home: Battery energy used for consumption
            6 => 'battery_to_grid_kwh',   // Battery to Grid: Battery energy exported to grid
            default => null
        };

        if ($column !== null) {
            $row[$column] = $floatValue;
        }

        return $row;
    }
}