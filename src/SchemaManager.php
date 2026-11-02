<?php

declare(strict_types=1);

final class SchemaManager
{
    public function __construct(private PDO $pdo) {}

    public function ensureSchema(): void
    {
        $this->createDatapointsTable();
        $this->createImportRunsTable();
        $this->createDailySummaryTable();
        $this->createEnergyFlowsTable();
        $this->createOctopusConsumptionTable();
        $this->createOctopusDailySummaryTable();
        $this->createEdfTables();
    }
    private function createDatapointsTable(): void
    {
        $this->pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS givenergy_datapoints (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inverter_serial VARCHAR(32) NOT NULL,
        sample_time_local DATETIME NOT NULL,
        sample_time_utc DATETIME NOT NULL,
        sample_date DATE NOT NULL,
        status VARCHAR(32) NULL,
        grid_power_w DECIMAL(12,3) NULL,
        grid_voltage_v DECIMAL(12,3) NULL,
        grid_current_a DECIMAL(12,3) NULL,
        grid_frequency_hz DECIMAL(12,3) NULL,
        pv_power_w DECIMAL(12,3) NULL,
        string_generation_1_w DECIMAL(12,3) NULL,
        string_generation_2_w DECIMAL(12,3) NULL,
        string_voltage_1_v DECIMAL(12,3) NULL,
        string_voltage_2_v DECIMAL(12,3) NULL,
        string_current_1_a DECIMAL(12,3) NULL,
        string_current_2_a DECIMAL(12,3) NULL,
        battery_power_w DECIMAL(12,3) NULL,
        battery_charge_w DECIMAL(12,3) NULL,
        battery_discharge_w DECIMAL(12,3) NULL,
        battery_voltage_v DECIMAL(12,3) NULL,
        battery_percentage DECIMAL(8,3) NULL,
        battery_temperature_c DECIMAL(8,3) NULL,
        inverter_power_w DECIMAL(12,3) NULL,
        inverter_voltage_v DECIMAL(12,3) NULL,
        inverter_frequency_hz DECIMAL(12,3) NULL,
        inverter_temperature_c DECIMAL(8,3) NULL,
        demanded_power_w DECIMAL(12,3) NULL,
        eps_output_power_w DECIMAL(12,3) NULL,
        consumption_energy_today_kwh DECIMAL(12,3) NULL,
        solar_generation_energy_today_kwh DECIMAL(12,3) NULL,
        grid_import_energy_today_kwh DECIMAL(12,3) NULL,
        grid_export_energy_today_kwh DECIMAL(12,3) NULL,
        battery_charge_energy_today_kwh DECIMAL(12,3) NULL,
        battery_discharge_energy_today_kwh DECIMAL(12,3) NULL,
        inverter_output_energy_today_kwh DECIMAL(12,3) NULL,
        ac_charge_energy_today_kwh DECIMAL(12,3) NULL,
        raw_json JSON NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_inverter_sample (inverter_serial, sample_time_local),
        KEY idx_sample_date (sample_date),
        KEY idx_sample_time_utc (sample_time_utc),
        KEY idx_inverter_date (inverter_serial, sample_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);
    }

    private function createImportRunsTable(): void
    {
        $this->pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS givenergy_import_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inverter_serial VARCHAR(32) NOT NULL,
        requested_date DATE NOT NULL,
        fetched_at_utc DATETIME NOT NULL,
        row_count INT UNSIGNED NOT NULL DEFAULT 0,
        api_status SMALLINT UNSIGNED NOT NULL,
        notes VARCHAR(255) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_run_date (inverter_serial, requested_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);
    }

    private function createDailySummaryTable(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS givenergy_daily_summary (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inverter_serial VARCHAR(32) NOT NULL,
            summary_date DATE NOT NULL,
            sample_time_local DATETIME NOT NULL,
            sample_time_utc DATETIME NOT NULL,
            consumption_energy_kwh DECIMAL(12,3) NULL,
            solar_generation_energy_kwh DECIMAL(12,3) NULL,
            grid_import_energy_kwh DECIMAL(12,3) NULL,
            grid_export_energy_kwh DECIMAL(12,3) NULL,
            battery_charge_energy_kwh DECIMAL(12,3) NULL,
            battery_discharge_energy_kwh DECIMAL(12,3) NULL,
            inverter_output_energy_kwh DECIMAL(12,3) NULL,
            ac_charge_energy_kwh DECIMAL(12,3) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inverter_date (inverter_serial, summary_date),
            KEY idx_summary_date (summary_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    private function createEnergyFlowsTable(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS givenergy_energy_flows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inverter_serial VARCHAR(32) NOT NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            period_date DATE NOT NULL,
            grouping_type TINYINT UNSIGNED NOT NULL COMMENT '0=30min, 1=hourly, 2=daily',
            pv_to_home_kwh DECIMAL(12,3) NULL COMMENT 'Type 0: Solar energy used immediately for local consumption',
            pv_to_battery_kwh DECIMAL(12,3) NULL COMMENT 'Type 1: Solar energy used to charge the battery',
            pv_to_grid_kwh DECIMAL(12,3) NULL COMMENT 'Type 2: Solar energy exported to the grid',
            grid_to_home_kwh DECIMAL(12,3) NULL COMMENT 'Type 3: Grid energy used immediately for local consumption',
            grid_to_battery_kwh DECIMAL(12,3) NULL COMMENT 'Type 4: Grid energy used to charge the battery',
            battery_to_home_kwh DECIMAL(12,3) NULL COMMENT 'Type 5: Battery energy used for local consumption',
            battery_to_grid_kwh DECIMAL(12,3) NULL COMMENT 'Type 6: Battery energy exported to the grid',
            sum_home_kwh DECIMAL(12,3) AS (COALESCE(pv_to_home_kwh, 0) + COALESCE(grid_to_home_kwh, 0) + COALESCE(battery_to_home_kwh, 0)) STORED COMMENT 'Total home consumption from all sources',
            sum_generation_kwh DECIMAL(12,3) AS (COALESCE(pv_to_home_kwh, 0) + COALESCE(pv_to_battery_kwh, 0) + COALESCE(pv_to_grid_kwh, 0)) STORED COMMENT 'Total solar generation',
            sum_grid_import_kwh DECIMAL(12,3) AS (COALESCE(grid_to_home_kwh, 0) + COALESCE(grid_to_battery_kwh, 0)) STORED COMMENT 'Total grid import',
            sum_grid_export_kwh DECIMAL(12,3) AS (COALESCE(pv_to_grid_kwh, 0) + COALESCE(battery_to_grid_kwh, 0)) STORED COMMENT 'Total grid export',
            raw_json JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inverter_period (inverter_serial, period_start, grouping_type),
            KEY idx_period_date (period_date),
            KEY idx_inverter_date (inverter_serial, period_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }


    private function createOctopusConsumptionTable(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS octopus_consumption (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mpan_mprn VARCHAR(32) NOT NULL COMMENT 'MPAN for electricity or MPRN for gas',
            meter_serial VARCHAR(32) NOT NULL,
            fuel_type ENUM('electricity_import', 'electricity_export', 'gas') NOT NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            period_date DATE NOT NULL,
            consumption_kwh DECIMAL(12,3) NOT NULL COMMENT 'Energy consumed in kWh',
            raw_json JSON NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_meter_period (mpan_mprn, meter_serial, fuel_type, period_start),
            KEY idx_period_date (period_date),
            KEY idx_meter_date (mpan_mprn, meter_serial, period_date),
            KEY idx_fuel_date (fuel_type, period_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    private function createOctopusDailySummaryTable(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS octopus_daily_summary (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mpan_mprn VARCHAR(32) NOT NULL,
            meter_serial VARCHAR(32) NOT NULL,
            fuel_type ENUM('electricity_import', 'electricity_export', 'gas') NOT NULL,
            summary_date DATE NOT NULL,
            total_consumption_kwh DECIMAL(12,3) NOT NULL COMMENT 'Total energy for the day',
            period_count INT UNSIGNED NOT NULL COMMENT 'Number of half-hourly periods',
            min_consumption_kwh DECIMAL(12,3) NULL,
            max_consumption_kwh DECIMAL(12,3) NULL,
            avg_consumption_kwh DECIMAL(12,3) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_meter_date (mpan_mprn, meter_serial, fuel_type, summary_date),
            KEY idx_summary_date (summary_date),
            KEY idx_fuel_date (fuel_type, summary_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    // EDF bills (edf_import.php): one row per bill, per fuel and tariff period on it, per credit, and per import
    // half-hour. Money in pounds as printed; the half-hours' cost in pence, as printed.
    private function createEdfTables(): void
    {
        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS edf_bills (
            bill_ref VARCHAR(16) NOT NULL,
            bill_date DATE NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            balance_before_gbp DECIMAL(10,2) NOT NULL COMMENT 'credit positive',
            balance_after_gbp DECIMAL(10,2) NOT NULL COMMENT 'credit positive',
            payments_gbp DECIMAL(10,2) NOT NULL,
            credits_gbp DECIMAL(10,2) NOT NULL COMMENT 'including VAT, negative',
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bill_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS edf_charges (
            bill_ref VARCHAR(16) NOT NULL,
            supply ENUM('import', 'export', 'gas') NOT NULL,
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            tariff VARCHAR(64) NOT NULL,
            meter VARCHAR(32) NOT NULL,
            read_from DECIMAL(12,4) NULL,
            read_to DECIMAL(12,4) NULL,
            m3 DECIMAL(10,4) NULL,
            calorific_value DECIMAL(4,1) NULL,
            kwh DECIMAL(10,4) NOT NULL,
            unit_rate_p DECIMAL(7,3) NULL COMMENT 'single-rate tariffs',
            off_peak_kwh DECIMAL(10,4) NULL COMMENT 'two-rate tariffs: the cheaper rate',
            off_peak_p DECIMAL(7,3) NULL,
            peak_kwh DECIMAL(10,4) NULL,
            peak_p DECIMAL(7,3) NULL,
            energy_gbp DECIMAL(10,2) NOT NULL COMMENT 'export negative',
            standing_days INT NOT NULL,
            standing_p_per_day DECIMAL(7,3) NOT NULL,
            standing_gbp DECIMAL(10,2) NOT NULL,
            vat_pct DECIMAL(4,1) NOT NULL,
            vat_gbp DECIMAL(10,2) NOT NULL,
            total_gbp DECIMAL(10,2) NOT NULL,
            PRIMARY KEY (bill_ref, supply, period_from),
            KEY idx_supply_period (supply, period_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS edf_credits (
            bill_ref VARCHAR(16) NOT NULL,
            line_no TINYINT UNSIGNED NOT NULL,
            credit_date DATE NOT NULL,
            description VARCHAR(128) NOT NULL,
            gbp DECIMAL(10,2) NOT NULL COMMENT 'before VAT, negative',
            PRIMARY KEY (bill_ref, line_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS edf_halfhours (
            start_utc DATETIME NOT NULL,
            local_date DATE NOT NULL,
            slot TINYINT UNSIGNED NOT NULL COMMENT '0 = from local midnight; 46 or 50 a day when the clocks change',
            rate_p DECIMAL(7,3) NOT NULL,
            kwh DECIMAL(8,4) NOT NULL,
            cost_p DECIMAL(9,3) NOT NULL,
            bill_ref VARCHAR(16) NOT NULL,
            PRIMARY KEY (start_utc),
            KEY idx_local_date (local_date),
            KEY idx_bill (bill_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
