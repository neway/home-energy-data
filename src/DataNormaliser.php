<?php

declare(strict_types=1);

final class DataNormaliser
{
    public function __construct(private string $inverterSerial) {}

    public function normaliseRow(array $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $timestamp = extractTimestamp($row);
        if ($timestamp === null) {
            return null;
        }

        [$local, $utc] = $this->parseTimestamps($timestamp);

        return [
            'inverter_serial' => $this->inverterSerial,
            'sample_time_local' => $local->format('Y-m-d H:i:s'),
            'sample_time_utc' => $utc->format('Y-m-d H:i:s'),
            'sample_date' => $local->format('Y-m-d'),
            'status' => scalarOrNull($row, ['status']),
            ...$this->extractGridMetrics($row),
            ...$this->extractSolarMetrics($row),
            ...$this->extractBatteryMetrics($row),
            ...$this->extractInverterMetrics($row),
            ...$this->extractEnergyTotals($row),
            'raw_json' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function parseTimestamps(string $timestamp): array
    {
        $utcTz = new DateTimeZone('UTC');
        $localTz = new DateTimeZone(DEFAULT_TIMEZONE);

        $utc = new DateTimeImmutable($timestamp, $utcTz);
        $local = $utc->setTimezone($localTz);

        return [$local, $utc];
    }

    private function extractGridMetrics(array $row): array
    {
        return [
            'grid_power_w' => decimalOrNull($row, ['power.grid.power', 'grid_power']),
            'grid_voltage_v' => decimalOrNull($row, ['power.grid.voltage', 'grid_voltage']),
            'grid_current_a' => decimalOrNull($row, ['power.grid.current', 'grid_current']),
            'grid_frequency_hz' => decimalOrNull($row, ['power.grid.frequency', 'grid_frequency']),
        ];
    }

    private function extractSolarMetrics(array $row): array
    {
        return [
            'pv_power_w' => decimalOrNull($row, ['power.solar.power', 'pv_power', 'ppv', 'solar_power']),
            'string_generation_1_w' => decimalOrNull($row, ['power.solar.arrays.0.power', 'string_generation_1', 'ppv1']),
            'string_generation_2_w' => decimalOrNull($row, ['power.solar.arrays.1.power', 'string_generation_2', 'ppv2']),
            'string_voltage_1_v' => decimalOrNull($row, ['power.solar.arrays.0.voltage', 'string_voltage_1', 'vpv1']),
            'string_voltage_2_v' => decimalOrNull($row, ['power.solar.arrays.1.voltage', 'string_voltage_2', 'vpv2']),
            'string_current_1_a' => decimalOrNull($row, ['power.solar.arrays.0.current', 'string_current_1', 'ipv1']),
            'string_current_2_a' => decimalOrNull($row, ['power.solar.arrays.1.current', 'string_current_2', 'ipv2']),
        ];
    }

    private function extractBatteryMetrics(array $row): array
    {
        return [
            'battery_power_w' => decimalOrNull($row, ['power.battery.power', 'battery_power']),
            'battery_charge_w' => decimalOrNull($row, ['battery_charge']),
            'battery_discharge_w' => decimalOrNull($row, ['battery_discharge']),
            'battery_voltage_v' => decimalOrNull($row, ['battery_voltage']),
            'battery_percentage' => decimalOrNull($row, ['power.battery.percent', 'battery_percentage', 'soc']),
            'battery_temperature_c' => decimalOrNull($row, ['power.battery.temperature', 'battery_temperature']),
        ];
    }

    private function extractInverterMetrics(array $row): array
    {
        return [
            'inverter_power_w' => decimalOrNull($row, ['power.inverter.power', 'inverter_power', 'pinv']),
            'inverter_voltage_v' => decimalOrNull($row, ['power.inverter.output_voltage', 'inverter_voltage']),
            'inverter_frequency_hz' => decimalOrNull($row, ['power.inverter.output_frequency', 'inverter_frequency']),
            'inverter_temperature_c' => decimalOrNull($row, ['power.inverter.temperature', 'inverter_temperature', 'temperature']),
            'demanded_power_w' => decimalOrNull($row, ['power.consumption.power', 'demanded_power', 'load_power']),
            'eps_output_power_w' => decimalOrNull($row, ['power.inverter.eps_power', 'eps_output_power']),
        ];
    }

    private function extractEnergyTotals(array $row): array
    {
        return [
            'consumption_energy_today_kwh' => decimalOrNull($row, ['today.consumption', 'consumption_energy_today', 'eload_today']),
            'solar_generation_energy_today_kwh' => decimalOrNull($row, ['today.solar', 'solar_generation_energy_today', 'epv_today']),
            'grid_import_energy_today_kwh' => decimalOrNull($row, ['today.grid.import', 'grid_import_energy_today', 'e_grid_in_day']),
            'grid_export_energy_today_kwh' => decimalOrNull($row, ['today.grid.export', 'grid_export_energy_today', 'e_grid_out_day']),
            'battery_charge_energy_today_kwh' => decimalOrNull($row, ['today.battery.charge', 'battery_charge_energy_today', 'e_battery_charge_day']),
            'battery_discharge_energy_today_kwh' => decimalOrNull($row, ['today.battery.discharge', 'battery_discharge_energy_today', 'e_battery_discharge_day']),
            'inverter_output_energy_today_kwh' => decimalOrNull($row, ['inverter_output_energy_today', 'eac_today']),
            'ac_charge_energy_today_kwh' => decimalOrNull($row, ['today.ac_charge', 'ac_charge_energy_today']),
        ];
    }
}