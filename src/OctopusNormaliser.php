<?php

declare(strict_types=1);

final class OctopusNormaliser
{
    public function __construct(
        private string $mpanMprn,
        private string $meterSerial,
        private string $fuelType
    ) {}

    public function normaliseConsumption(array $results): array
    {
        $rows = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $intervalStart = $result['interval_start'] ?? null;
            $intervalEnd = $result['interval_end'] ?? null;
            $consumption = $result['consumption'] ?? null;

            if (!$intervalStart || !$intervalEnd || $consumption === null) {
                continue;
            }

            $row = $this->createRow($intervalStart, $intervalEnd, (float)$consumption, $result);
            $rows[] = $row;
        }

        // Sort by period start
        usort($rows, fn($a, $b) => strcmp($a['period_start'], $b['period_start']));

        return $rows;
    }

    private function createRow(string $intervalStart, string $intervalEnd, float $consumption, array $rawData): array
    {
        $utcTz = new DateTimeZone('UTC');
        $localTz = new DateTimeZone(DEFAULT_TIMEZONE);

        $startUtc = new DateTimeImmutable($intervalStart, $utcTz);
        $endUtc = new DateTimeImmutable($intervalEnd, $utcTz);

        $startLocal = $startUtc->setTimezone($localTz);

        return [
            'mpan_mprn' => $this->mpanMprn,
            'meter_serial' => $this->meterSerial,
            'fuel_type' => $this->fuelType,
            'period_start' => $startLocal->format('Y-m-d H:i:s'),
            'period_end' => $endUtc->setTimezone($localTz)->format('Y-m-d H:i:s'),
            'period_date' => $startLocal->format('Y-m-d'),
            'consumption_kwh' => $consumption,
            'raw_json' => json_encode($rawData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}