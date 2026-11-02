<?php

declare(strict_types=1);

// Your household's numbers for battery_report.php. Copy to ../home_energy_private/config.php and set each to yours:
// the values here are examples.

// Tariff (pence per kWh). Off-peak runs from OFF_PEAK_START_HOUR to OFF_PEAK_END_HOUR, overnight.
const PEAK_P = 27.57;
const OFF_PEAK_P = 6.6;
const EXPORT_P = 15.0;
const OFF_PEAK_START_HOUR = 23;
const OFF_PEAK_END_HOUR = 6;

// The system as installed: commissioning date, what it cost, and the battery's rated capacity.
const INSTALL_DATE = '2023-01-01';
const SYSTEM_COST_GBP = 12000.0;
const BATTERY_COST_GBP = 5000.0;
const BATTERY_RATED_KWH = 13.5;

// Extra capacity under consideration: chunks of battery on their own AC-coupled inverter, supply prices.
const EXTRA_CHUNK_KWH = 5.0;
const EXTRA_CHUNK_GBP = 620.0;
const EXTRA_INVERTER_GBP = 1200.0;
const EXTRA_INVERTER_KW = 6.0;
const EXTRA_MAX_CHUNKS = 6;
const EXTRA_INSTALL_GBP = 600.0;

// When to add storage, for the house's own use only (export income isn't counted on): top the usable total up to
// DECISION_TOTAL_KWH if that pays back from own use within DECISION_PAYBACK_YEARS, or once 12-month peak import
// passes DECISION_PEAK_IMPORT_KWH.
const DECISION_TOTAL_KWH = 16.0;
const DECISION_PAYBACK_YEARS = 10;
const DECISION_PEAK_IMPORT_KWH = 900.0;

// Gas: price and standing charge, used only until there are EDF bills (they give the billed rates). kWh per m3
// converts Octopus's smart meter readings, which are m3.
const GAS_P = 6.0;
const GAS_STANDING_P_PER_DAY = 30.0;
const GAS_KWH_PER_M3 = 11.2;


// SEG pays only for solar export. The MCS estimate of yearly generation (on your installer's quote) is the
// yardstick for how close export gets to looking like more than the panels made.
const MCS_ESTIMATE_KWH = 4000.0;

// Shelly devices by role, by hardware id (shelly_devices.device_id, after shelly_import.php has run), so renaming
// them in the app changes nothing. '' leaves the garage section out.
const SHELLY_OUTSIDE_SENSOR = '';
const SHELLY_GARAGE_SENSOR = '';
const SHELLY_GARAGE_DEHUMIDIFIER = '';

// Changes to the house, marked against their month in the report: [date, what].
const HOME_CHANGES = [
    // ['2025-04-14', 'garage wall tanked'],
];

// Where the weather comes from (Open-Meteo, weather_import.php): your town is close enough.
const WEATHER_LATITUDE = 51.5;
const WEATHER_LONGITUDE = -0.13;

// A heat pump to compare with the heating it replaced: its Shelly meter [device id, channel], when it went in, the
// meters on the old heating [device id, channel, what], and a temperature sensor in the room. '' / [] leaves it out.
const OFFICE_HEAT_PUMP = ['', 0];
const OFFICE_HEAT_PUMP_FROM = '';
const OFFICE_HEATING_BEFORE = [];
const OFFICE_SENSOR = '';
