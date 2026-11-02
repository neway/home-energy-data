<?php

declare(strict_types=1);

/*
 * Battery health and value report: demonstrated capacity, energy by month, what the system saves (with workings),
 * payback, and what extra capacity would add. Method and assumptions: README.md.
 *
 * Usage:
 *   php battery_report.php --config=/path/to/givenergy.ini [--html]
 */

// Your tariff, system, costs and thresholds: copy config.sample.php there and set them (INSTALL.md).
require_once __DIR__ . '/../home_energy_private/config.php';

// One-way efficiency of a battery (charge or discharge); round trip is this squared.
const BATTERY_EFFICIENCY = 0.95;

// Red/amber/green. Capacity: share of a healthy pack (rated kWh / 100 per 1% of charge).
// Export: share of solar generation (amber from EXPORT_AMBER_FROM, red over 100%).
const RAG_GREEN_FROM = 0.85;
const RAG_AMBER_FROM = 0.6;
const EXPORT_AMBER_FROM = 0.9;

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/BatteryCapacity.php';
require_once __DIR__ . '/src/BatteryEconomics.php';
require_once __DIR__ . '/src/Billing.php';

main($argv);

function main(array $argv): void {
    date_default_timezone_set('Europe/London');
    $opts = getopt('', ['config::', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::', 'html']);
    $pdo = connectPdo(loadDbSettings($opts, loadConfigFile($opts)));

    // Whole days only: end yesterday, or earlier if the import hasn't caught up.
    $latest = (string)$pdo->query('SELECT MAX(sample_date) FROM givenergy_datapoints')->fetchColumn();
    $end = min($latest, date('Y-m-d', strtotime('yesterday')));
    $windows = [
        '30 days' => [daysBefore($end, 29), $end],
        '12 months' => [daysBefore($end, 364), $end],
    ];
    [$yearFrom] = $windows['12 months'];

    $capacity = new BatteryCapacity($pdo);
    $economics = new BatteryEconomics($pdo);
    $sampled = $economics->sampledFlows($yearFrom, $end);
    $year = sumMonths($sampled);
    $inverter = $economics->inverterTotals($yearFrom, $end);
    $sinceInstall = sumMonths($economics->sampledFlows(INSTALL_DATE, $end));
    $billing = new Billing($pdo);
    $car = $billing->carByMonth($yearFrom, $end);
    $axleFile = __DIR__ . '/../home_energy_private/axle_events.php';
    $axle = $billing->gridEvents(is_file($axleFile) ? require $axleFile : []);
    $axleYear = array_filter($axle, fn($e) => $e['date'] >= $yearFrom && $e['date'] <= $end);

    [$capacitySection, $kwh30] = capacitySection($capacity, $windows, $end);
    $extra = [];
    for ($n = 1; $n <= EXTRA_MAX_CHUNKS; $n++) {
        $extra[$n] = $economics->extraCapacity($yearFrom, $end, $n * EXTRA_CHUNK_KWH);
    }
    [$decisionSection, $verdict] = decisionSection($year, $extra, $kwh30);
    $title = sprintf('Battery: %s over 30 days, add storage: %s',
        $kwh30 === null ? 'n/a' : sprintf('%.1f kWh (%s of rated)', $kwh30, pct($kwh30 / BATTERY_RATED_KWH)), $verdict);

    $sections = [
        conclusionSection($year, sumMonths($car), $billing->gasYear($yearFrom, $end, GAS_KWH_PER_M3), $billing->latestRates(),
            $axleYear),
        carSection($car),
        billsSection($billing->charges(), $billing->creditsTotal()),
        axleSection($axle, $axleYear),
        $capacitySection,
        cyclesSection($capacity, $windows['12 months']),
        monthlySection($capacity, $sampled, $inverter, $yearFrom, $end),
        exportSection($year, sumMonths($inverter)),
        savingsSection($year, sumMonths($inverter)),
        paybackSection($year, $sinceInstall, $end),
        inverterSection($year),
        extraSection($extra, $year, $kwh30),
        $decisionSection,
    ];

    $intro = sprintf('Data to %s. Tariff %.2fp peak, %.2fp off-peak (%02d:00-%02d:00), %.2fp export.',
        $end, PEAK_P, OFF_PEAK_P, OFF_PEAK_START_HOUR, OFF_PEAK_END_HOUR, EXPORT_P);
    print isset($opts['html']) ? renderHtml($title, $intro, $sections) : renderText($title, $intro, $sections);
}

function conclusionSection(array $s, array $car, array $gas, array $rates, array $axle): array {
    $offPeak = $s['imp_off'] * OFF_PEAK_P / 100;
    $peak = $s['imp_peak'] * PEAK_P / 100;
    $solarExport = ($s['exp'] - $s['exp_battery']) * EXPORT_P / 100;
    $batteryExport = $s['exp_battery'] * EXPORT_P / 100;
    $houseNet = $offPeak + $peak - $solarExport - $batteryExport;
    $elecStanding = 365 * ($rates['elec_standing_p'] ?? 0) / 100;
    $carCost = ($car['car'] ?? 0) * OFF_PEAK_P / 100;
    $electricity = $houseNet + $elecStanding + $carCost;
    $axlePaid = array_sum(array_column($axle, 'paid'));
    $electricityNet = $electricity - $axlePaid;
    // Gas at the latest billed rates; the assumed ones only without bills.
    $gasP = $rates['gas_p'] ?? GAS_P;
    $gasStandingP = $rates['gas_standing_p'] ?? GAS_STANDING_P_PER_DAY;
    $gasCost = ($gas['kwh'] * $gasP + $gas['days'] * $gasStandingP) / 100;
    $energy = $electricityNet + $gasCost;
    // Moving a kWh from peak to off-peak costs it twice over in losses: into the battery and out again.
    $ceiling = $s['imp_peak'] * (PEAK_P - OFF_PEAK_P / BATTERY_EFFICIENCY ** 2) / 100;
    $billed = $rates['as_of'] ? "latest EDF bill, to {$rates['as_of']}" : 'assumed: no EDF bills';

    return [
        'title' => 'Conclusion: where the money goes',
        'text' => [sprintf('The house\'s electricity nets out at about nothing over the year (%s): export income pays for the '
            . 'imports. With the standing charge (%s) and the car (%s), electricity costs %s a year; less Axle\'s %s, net '
            . 'electricity spend is %s. Gas is the main bill left (%s): all energy comes to %s a year. Moving every last peak-time kWh to off-peak would save at most %s a year: the ceiling for any '
            . 'battery upgrade.',
            gbp($houseNet), gbp($elecStanding), gbp($carCost), gbp($electricity), gbp($axlePaid), gbp($electricityNet),
            gbp($gasCost), gbp($energy), gbp($ceiling))],
        'head' => ['', 'Workings', '£ a year'],
        'rows' => [
            ['Electricity import, off-peak', sprintf('%s kWh x %.2fp', kwh($s['imp_off']), OFF_PEAK_P), gbp($offPeak)],
            ['Electricity import, peak', sprintf('%s kWh x %.2fp', kwh($s['imp_peak']), PEAK_P), gbp($peak)],
            ['Export of solar', sprintf('%s kWh x %.2fp', kwh($s['exp'] - $s['exp_battery']), EXPORT_P), gbp(-$solarExport)],
            ['Export from the battery (real today, not planned on)', sprintf('%s kWh x %.2fp', kwh($s['exp_battery']), EXPORT_P),
                gbp(-$batteryExport)],
            ['House electricity, net', 'last 12 months, before standing charge', gbp($houseNet)],
            ['Electricity standing charge', sprintf('365 days x %.2fp (%s)', $rates['elec_standing_p'] ?? 0, $billed),
                gbp($elecStanding)],
            ['Car charging', sprintf('%s kWh x %.2fp (meter - GivEnergy, %d days)', kwh($car['car'] ?? 0), OFF_PEAK_P,
                $car['days'] ?? 0), gbp($carCost)],
            ['Electricity, all in', 'house + standing charge + car', gbp($electricity)],
            ['Axle grid events (needs the battery)', sprintf('%d events paid, since %s', count($axle),
                $axle ? min(array_column($axle, 'date')) : '-'), gbp(-$axlePaid)],
            ['Net electricity spend', 'all in, less Axle', gbp($electricityNet)],
            ['Gas', sprintf('%s kWh x %.2fp + %d days x %.2fp (%s to %s)', kwh($gas['kwh']), $gasP, $gas['days'],
                $gasStandingP, $gas['from'], $gas['to']), gbp($gasCost)],
            ['All energy', 'net electricity spend + gas', gbp($energy)],
            ['Battery ceiling: all peak import moved off-peak', sprintf('%s kWh x (%.2fp - %.2fp / %.2f²)', kwh($s['imp_peak']),
                PEAK_P, OFF_PEAK_P, BATTERY_EFFICIENCY), gbp($ceiling)],
        ],
        'notes' => [sprintf('House electricity from the 5-minute samples, priced at today\'s tariff (the car charger is '
            . 'outside what the GivEnergy sensors see). The car is priced at the off-peak rate, as it charges in cheap '
            . 'slots. Gas: %s kWh billed by EDF, before that %s m3 of Octopus readings x %.1f, all at the %s. Before VAT.',
            kwh($gas['billed_kwh']), number_format($gas['m3'], 0), GAS_KWH_PER_M3, $billed)],
    ];
}

function carSection(array $car): array {
    $rows = [];
    foreach (array_reverse($car, true) as $month => $c) {
        $rows[] = carRow(date('M Y', strtotime("$month-01")), $c);
    }
    $rows[] = carRow('12 months', sumMonths($car));

    return [
        'title' => 'Car charging, last 12 months (kWh)',
        'text' => ['The charger is outside GivEnergy\'s grid sensor, so the meter\'s import minus GivEnergy\'s is the car. '
            . 'On days the car didn\'t charge the two agree to 0.01 kWh. Meter: Octopus half-hours until the EDF bills\' '
            . 'half-hours begin, so the latest days wait for the next bill.'],
        'head' => ['Month', 'Days', 'Meter import', 'GivEnergy import', 'Car', 'Of import', 'Charging days',
            sprintf('Car at %.2fp', OFF_PEAK_P)],
        'rows' => $rows,
        'notes' => ['Days: whole days with meter data (part days, like the day of the switch, are left out).'],
    ];
}

function carRow(string $label, array $c): array {
    return [$label, (int)$c['days'], kwh($c['meter']), kwh($c['givenergy']), kwh($c['car']), pct($c['car'] / $c['meter']),
        (int)$c['car_days'], gbp($c['car'] * OFF_PEAK_P / 100)];
}

function billsSection(array $charges, float $credits): array {
    $rows = [];
    $totals = [];
    foreach ($charges as $c) {
        $period = date('j M', strtotime($c['period_from'])) . ' - ' . date('j M Y', strtotime($c['period_to']));
        $kwh = (float)$c['kwh'];
        [$givenergy, $check] = match ($c['supply']) {
            'import' => [(float)$c['givenergy_import'], 'car ' . kwh($kwh - $c['givenergy_import'])],
            'export' => [(float)$c['givenergy_export'], billedShare($kwh, (float)$c['givenergy_export'])],
            'gas' => [null, sprintf('%.1f m3, CV %.1f', $c['m3'], $c['calorific_value'])],
        };
        $rows[] = [$period, $c['supply'], $c['tariff'], kwh($kwh), $c['peak_kwh'] === null ? '' : kwh((float)$c['peak_kwh']),
            gbp((float)$c['energy_gbp'], 2), sprintf('%d x %.2fp', $c['standing_days'], $c['standing_p_per_day']),
            gbp((float)$c['standing_gbp'], 2), gbp((float)$c['vat_gbp'], 2), gbp((float)$c['total_gbp'], 2),
            $givenergy === null ? '' : kwh($givenergy), $check];
        $t = &$totals[$c['supply']];
        $t ??= array_fill_keys(['kwh', 'energy', 'standing', 'vat', 'total'], 0.0);
        $t['kwh'] += $kwh;
        $t['energy'] += $c['energy_gbp'];
        $t['standing'] += $c['standing_gbp'];
        $t['vat'] += $c['vat_gbp'];
        $t['total'] += $c['total_gbp'];
        unset($t);
    }
    foreach ($totals as $supply => $t) {
        $rows[] = ["All bills: $supply", '', '', kwh($t['kwh']), '', gbp($t['energy'], 2), '', gbp($t['standing'], 2),
            gbp($t['vat'], 2), gbp($t['total'], 2), '', ''];
    }
    $rows[] = ['All bills: credits', '', 'loyalty, smart charging...', '', '', '', '', '', '', gbp($credits, 2), '', ''];
    $rows[] = ['All bills: net', '', '', '', '', '', '', gbp(array_sum(array_column($totals, 'standing')), 2), '',
        gbp(array_sum(array_column($totals, 'total')) + $credits, 2), '', ''];

    return [
        'title' => 'EDF bills against GivEnergy',
        'text' => ['Each bill line as billed (units and standing charge before VAT), newest first, against GivEnergy\'s '
            . 'own counters for the same days. Import: the difference is the car. Export: what EDF credited as a share '
            . 'of what GivEnergy sent out; green within 5%. Gas: the m3 and calorific value EDF converted from.'],
        'head' => ['Period', 'Supply', 'Tariff', 'kWh', 'At peak', 'Units', 'Standing days x rate', 'Standing', 'VAT', 'Total', 'GivEnergy',
            'Check'],
        'rows' => $rows ?: [['no bills imported (edf_import.php)', '', '', '', '', '', '', '', '', '', '', '']],
        'notes' => ['An export line far from GivEnergy usually runs from an older opening reading (e.g. the first bill '
            . 'after a switch). A line for part of a switch day won\'t match GivEnergy\'s whole day.'],
    ];
}

function axleSection(array $events, array $year): array {
    $rows = array_map(fn($e) => [date('D j M Y', strtotime($e['date'])), "{$e['start']}-{$e['end']}", gbp($e['paid'], 2),
        orDash($e['export'], 1), orDash($e['import'], 1), orDash($e['battery_out'], 1),
        $e['soc_start'] === null ? '-' : sprintf('%d%% to %d%%', $e['soc_start'], $e['soc_end']),
        $e['export'] ? sprintf('%.0fp', 100 * $e['paid'] / $e['export']) : '-'], $events);
    $paid = array_sum(array_column($year, 'paid'));
    $export = array_sum(array_column($year, 'export'));
    $rows[] = ['12 months', count($year) . ' events', gbp($paid, 2), kwh($export, 1),
        kwh(array_sum(array_column($year, 'import')), 1), kwh(array_sum(array_column($year, 'battery_out')), 1), '',
        $export ? sprintf('%.0fp', 100 * $paid / $export) : '-'];

    return [
        'title' => 'Axle grid events',
        'text' => [sprintf('Each event as paid (from the Axle app, kept in home_energy_private/axle_events.php), newest '
            . 'first, with GivEnergy\'s counters from its start to its end. EDF also credits the export at %.2fp, but '
            . 'it\'s battery energy, so not SEG-eligible, and the battery then has that much less for the house.', EXPORT_P)],
        'head' => ['Date', 'Time', 'Paid', 'Exported', 'Imported', 'Battery out', 'Battery', 'Paid per kWh exported'],
        'rows' => $rows,
        'notes' => ['"-": no samples at the start or end of the event. An evening event can leave the battery low, so '
            . 'the house imports at peak afterwards: worth it while Axle pays well over the peak rate per kWh.'],
    ];
}

function orDash(?float $kwh, int $decimals = 0): string {
    return $kwh === null ? '-' : kwh($kwh, $decimals);
}

/** What EDF billed as a share of what GivEnergy measured: green within 5%. */
function billedShare(float $billed, float $measured): array {
    $share = $billed / max($measured, 1e-9);
    return rag(pct($share) . ' of GivEnergy', abs($share - 1) <= 0.05 ? 'green' : 'amber');
}

function capacitySection(BatteryCapacity $capacity, array $windows, string $end): array {
    $bands30 = $capacity->slices(...$windows['30 days'])['bands'];
    $rows = [];
    for ($d = 0; $d < 7; $d++) {
        $day = daysBefore($end, $d);
        $rows[] = capacityRow(date('D j M', strtotime($day)), $capacity->slices($day, $day)['bands'], $bands30);
    }
    foreach (array_keys($windows) as $label) {
        $rows[] = capacityRow($label, $capacity->slices(...$windows[$label])['bands'], $bands30);
    }
    $kwh30 = in_array(null, $bands30, true) ? null : array_sum($bands30) * 10;

    return [[
        'title' => sprintf('Demonstrated capacity (rated %.2f kWh)', BATTERY_RATED_KWH),
        'text' => [sprintf('kWh delivered per 1%% of charge in each 10%% band, x10, added up. Healthy is %.3f in every band. '
            . 'Green: %s or more of healthy, amber: %s to %s, red: below %s.', BATTERY_RATED_KWH / 100,
            pct(RAG_GREEN_FROM), pct(RAG_AMBER_FROM), pct(RAG_GREEN_FROM), pct(RAG_AMBER_FROM))],
        'head' => ['Day / window', 'Capacity', 'Of rated', '0-9%', '10-19', '20-29', '30-39', '40-49', '50-59', '60-69',
            '70-79', '80-89', '90-100'],
        'rows' => $rows,
        'notes' => ['* the battery did not pass through every band that day: the missing ones are taken from the 30 days.'],
    ], $kwh30];
}

function capacityRow(string $label, array $bands, array $fallback): array {
    $borrowed = in_array(null, $bands, true);
    $filled = array_map(fn ($b, $f) => $b ?? $f, $bands, $fallback);
    $kwh = in_array(null, $filled, true) ? null : array_sum($filled) * 10;

    return array_merge(
        [$label . ($borrowed ? ' *' : ''), $kwh === null ? 'n/a' : kwh($kwh, 1),
            $kwh === null ? '' : rag(pct($kwh / BATTERY_RATED_KWH), healthRag($kwh / BATTERY_RATED_KWH))],
        array_map(fn ($b) => $b === null ? '-' : rag(sprintf('%.3f', $b), healthRag($b / (BATTERY_RATED_KWH / 100))), $bands)
    );
}

function cyclesSection(BatteryCapacity $capacity, array $window): array {
    $rows = array_map(fn ($c) => [$c['date'], "{$c['start']}-{$c['end']}", kwh($c['kwh'], 1),
        rag(pct($c['kwh'] / BATTERY_RATED_KWH), healthRag($c['kwh'] / BATTERY_RATED_KWH))],
        array_reverse($capacity->fullCycles(...$window)));

    return [
        'title' => 'Full 100% to 0% discharges, last 12 months',
        'text' => ['Read straight off the inverter\'s discharge counter: the direct measure the bands should agree with.'],
        'head' => ['Date', 'Time', 'Delivered', 'Of rated'],
        'rows' => $rows ?: [['none', '', '', '']],
    ];
}

function monthlySection(BatteryCapacity $capacity, array $sampled, array $inverter, string $from, string $to): array {
    $rows = [];
    $total = array_fill_keys(array_keys(reset($inverter)), 0.0);
    foreach (array_reverse($inverter, true) as $month => $i) {
        $s = $sampled[$month] ?? ['imp_peak' => 0.0, 'exp_battery' => 0.0];
        $monthFrom = max("$month-01", $from);
        $monthTo = min(date('Y-m-t', strtotime("$month-01")), $to);
        $slices = $capacity->slices($monthFrom, $monthTo);
        $rows[] = monthRow(date('M Y', strtotime("$month-01")), (int)round((strtotime($monthTo) - strtotime($monthFrom)) / 86400) + 1,
            $i, $s, $slices['capacity_kwh'] === null ? '-' : kwh($slices['capacity_kwh'], 1));
        foreach ($i as $k => $v) {
            $total[$k] += $v;
        }
    }
    $rows[] = monthRow('12 months', array_sum(array_column($rows, 1)), $total, sumMonths($sampled), '');

    return [
        'title' => 'Month by month, last 12 months (kWh)',
        'text' => ['From the inverter\'s own counters, except peak import and export from battery, which are from the '
            . '5-minute samples. Balance gap = (solar + import + battery out) - (home + export + battery in): '
            . 'conversion losses, so it should stay small.'],
        'head' => ['Month', 'Days', 'Solar', 'Home use', 'Import', 'At peak', 'Export', 'Of solar', 'From battery',
            'Battery in', 'From grid', 'Battery out', 'Balance gap', 'Capacity'],
        'rows' => $rows,
        'notes' => ['Capacity "-": the battery didn\'t run low enough that month to measure every band (sunny months).'],
    ];
}

function monthRow(string $label, int $days, array $i, array $s, string $capacity): array {
    $gap = ($i['solar'] + $i['import'] + $i['battery_out']) - ($i['home'] + $i['export'] + $i['battery_in']);
    return [$label, $days, kwh($i['solar']), kwh($i['home']), kwh($i['import']), kwh($s['imp_peak']), kwh($i['export']),
        exportShare($i['export'], $i['solar']), kwh($s['exp_battery']), kwh($i['battery_in']), kwh($i['grid_to_battery']),
        kwh($i['battery_out']), kwh($gap), $capacity];
}

function exportSection(array $s, array $i): array {
    $fromSolar = $s['exp'] - $s['exp_battery'];
    return [
        'title' => 'Export against generation, last 12 months',
        'text' => ['EDF\'s SEG pays only for solar export: if a meter reading looks like it includes battery energy, EDF '
            . 'decide how much of it to pay for. The yardstick is the MCS estimate of yearly generation, which EDF can '
            . 'see. Totals from the inverter\'s counters; the split from the 5-minute samples (export up to the solar '
            . 'surplus at that moment is solar, the rest came out of the battery).'],
        'head' => ['', 'kWh', 'Share'],
        'rows' => [
            ['MCS estimate of generation', kwh(MCS_ESTIMATE_KWH), ''],
            ['Solar generated', kwh($i['solar']), pct($i['solar'] / MCS_ESTIMATE_KWH) . ' of MCS estimate'],
            ['Exported in total', kwh($i['export']), exportShare($i['export'], MCS_ESTIMATE_KWH, ' of MCS estimate')],
            ['  from solar', kwh($fromSolar), pct($fromSolar / $s['exp']) . ' of export'],
            ['  from battery', kwh($s['exp_battery']), pct($s['exp_battery'] / $s['exp']) . ' of export'],
            ['Headroom to the MCS estimate', kwh(MCS_ESTIMATE_KWH - $i['export']), ''],
            ['Battery charged', kwh($i['battery_in']), ''],
            ['  from grid', kwh($i['grid_to_battery']), pct($i['grid_to_battery'] / $i['battery_in']) . ' of battery charge'],
        ],
        'notes' => [sprintf('Battery export earns %s a year at %.2fp, but isn\'t SEG-eligible: nothing below relies on it.',
            gbp($s['exp_battery'] * EXPORT_P / 100), EXPORT_P)],
    ];
}

function savingsSection(array $s, array $i): array {
    $actual = price($s['imp_off'], $s['imp_peak'], $s['exp']);
    $noBattery = price($s['nb_imp_off'], $s['nb_imp_peak'], $s['nb_exp']);
    $gridOnly = price($s['load_off'], $s['load_peak'], 0);
    $sampledImport = $s['imp_off'] + $s['imp_peak'];
    $sampledLoad = $s['load_off'] + $s['load_peak'];

    return [
        'title' => 'What the system saves, last 12 months',
        'text' => [sprintf('Each scenario costs off-peak kWh x %.2fp + peak kWh x %.2fp - export kWh x %.2fp. All three use '
            . 'the same 5-minute load and solar; only where the energy comes from changes.', OFF_PEAK_P, PEAK_P, EXPORT_P)],
        'head' => ['Scenario', 'Off-peak import', 'Peak import', 'Export', 'Cost'],
        'rows' => [
            ['Actual (real grid flows)', kwh($s['imp_off']), kwh($s['imp_peak']), kwh($s['exp']), gbp($actual)],
            ['No battery (load - solar)', kwh($s['nb_imp_off']), kwh($s['nb_imp_peak']), kwh($s['nb_exp']), gbp($noBattery)],
            ['Grid only (whole load)', kwh($s['load_off']), kwh($s['load_peak']), '0', gbp($gridOnly)],
            ['Battery saves (no battery - actual)', '', '', '', gbp($noBattery - $actual)],
            ['System saves (grid only - actual)', '', '', '', gbp($gridOnly - $actual)],
        ],
        'notes' => [sprintf('Check against the inverter\'s own counters: the 5-minute samples add up to %s of its import, '
            . '%s of its export, %s of its home use and %s of its solar. The gaps are missing samples.',
            pct($sampledImport / $i['import']), pct($s['exp'] / $i['export']), pct($sampledLoad / $i['home']),
            pct($s['pv'] / $i['solar']))],
    ];
}

function paybackSection(array $year, array $sinceInstall, string $end): array {
    $rows = [];
    foreach ([
        'Battery' => [BATTERY_COST_GBP, 'nb'],
        'Whole system' => [SYSTEM_COST_GBP, 'grid'],
    ] as $label => [$cost, $baseline]) {
        $perYear = saving($year, $baseline);
        $saved = saving($sinceInstall, $baseline);
        $left = max($cost - $saved, 0);
        $years = $perYear > 0 ? $left / $perYear : INF;
        $rows[] = [$label, gbp($cost), gbp($saved), gbp($perYear), gbp($left),
            is_finite($years) ? sprintf('%.1f', $years) : 'never',
            is_finite($years) ? date('M Y', strtotime($end) + (int)($years * 365.25 * 86400)) : ''];
    }

    return [
        'title' => 'Payback since ' . INSTALL_DATE,
        'text' => ['Saved so far prices every past year at today\'s tariff, which misprices years on a different '
            . 'tariff: see README.'],
        'head' => ['', 'Cost', 'Saved so far', 'Saving a year', 'Still to pay', 'Years to go', 'Paid off by'],
        'rows' => $rows,
    ];
}

function inverterSection(array $s): array {
    $row = fn ($label, $hours, $overHours, $load, $overKwh) =>
        [$label, sprintf('%.2f%%', 100 * $overHours / $hours), sprintf('%.1f', $overHours), kwh($overKwh, 1),
            sprintf('%.2f%%', 100 * (1 - $overKwh / $load))];

    return [
        'title' => sprintf('Can a %.0f kW inverter cover the house? Last 12 months', EXTRA_INVERTER_KW),
        'text' => [sprintf('5-minute steps where the house drew more than %.0f kW, and the energy above %.0f kW that a battery '
            . 'on that inverter could not supply.', EXTRA_INVERTER_KW, EXTRA_INVERTER_KW)],
        'head' => ['', 'Time over', 'Hours over', 'kWh over', 'Energy it covers'],
        'rows' => [
            $row('All hours', $s['hours'], $s['over_hours'], $s['load_off'] + $s['load_peak'], $s['over_kwh']),
            $row('Peak hours', $s['hours_peak'], $s['over_hours_peak'], $s['load_peak'], $s['over_kwh_peak']),
        ],
        'notes' => ['Off-peak includes EV charging, which the battery does not cover anyway.'],
    ];
}

function extraSection(array $extra, array $year, ?float $usableNow): array {
    $rows = [];
    foreach ($extra as $n => $x) {
        $kwh = $n * EXTRA_CHUNK_KWH;
        $installed = installedCost($n);
        $rows[] = [kwh($kwh), $usableNow === null ? 'n/a' : kwh($usableNow + $kwh, 1), gbp($installed),
            kwh($x['shifted_kwh']), pct($x['shifted_kwh'] / $year['imp_peak']), gbp($x['saving_gbp']),
            sprintf('%.1f', $installed / $x['saving_gbp']),
            exportShare($year['exp'] - $x['stored_kwh'], MCS_ESTIMATE_KWH)];
    }

    return [
        'title' => 'Extra capacity for own use, last 12 months replayed',
        'text' => [sprintf('%.0f kWh chunks at %s on a %.0f kW AC-coupled inverter at %s, plus %s to fit. The extra battery fills '
            . 'off-peak, stores solar that was being exported, and covers the peak imports that actually happened. It never '
            . 'exports. Total usable = the last 30 days\' demonstrated capacity + the extra.', EXTRA_CHUNK_KWH,
            gbp(EXTRA_CHUNK_GBP), EXTRA_INVERTER_KW, gbp(EXTRA_INVERTER_GBP), gbp(EXTRA_INSTALL_GBP))],
        'head' => ['Extra', 'Total usable', 'Installed', 'Peak import moved', 'Of peak import', 'Saves a year',
            'Payback yrs', 'Export of MCS estimate'],
        'rows' => $rows,
    ];
}

function decisionSection(array $year, array $extra, ?float $usableNow): array {
    $chunks = $usableNow === null ? 0
        : (int)min(max(ceil((DECISION_TOTAL_KWH - $usableNow) / EXTRA_CHUNK_KWH), 0), EXTRA_MAX_CHUNKS);
    if ($chunks === 0) {
        $topUp = $usableNow === null ? 'capacity not measured' : 'already there';
        $pays = false;
    } else {
        $payback = installedCost($chunks) / $extra[$chunks]['saving_gbp'];
        $pays = $payback <= DECISION_PAYBACK_YEARS;
        $topUp = sprintf('+%s kWh, %s installed, saves %s a year: %.1f years', kwh($chunks * EXTRA_CHUNK_KWH),
            gbp(installedCost($chunks)), gbp($extra[$chunks]['saving_gbp']), $payback);
    }
    $leaking = $year['imp_peak'] > DECISION_PEAK_IMPORT_KWH;
    $verdict = $pays || $leaking ? 'ADD STORAGE' : 'NOT YET';

    return [[
        'title' => 'When to add storage',
        'text' => [sprintf('Own use only. Top the usable total up to %.0f kWh when A (it pays back within %d years, the '
            . 'warranty) or B (peak import has grown).', DECISION_TOTAL_KWH, DECISION_PAYBACK_YEARS)],
        'head' => ['Test', 'Now', 'Met'],
        'rows' => [
            ['Usable now (last 30 days)', $usableNow === null ? 'n/a' : kwh($usableNow, 1) . ' kWh', ''],
            [sprintf('A. Top up to %.0f kWh, paid back in %d years', DECISION_TOTAL_KWH, DECISION_PAYBACK_YEARS), $topUp,
                yesNo($pays)],
            [sprintf('B. Peak import over %s kWh a year', kwh(DECISION_PEAK_IMPORT_KWH)), kwh($year['imp_peak']) . ' kWh',
                yesNo($leaking)],
            ['Verdict', $verdict, ''],
        ],
    ], $verdict];
}

function installedCost(int $chunks): float {
    return EXTRA_INVERTER_GBP + $chunks * EXTRA_CHUNK_GBP + EXTRA_INSTALL_GBP;
}

function saving(array $s, string $baseline): float {
    $actual = price($s['imp_off'], $s['imp_peak'], $s['exp']);
    return $baseline === 'nb'
        ? price($s['nb_imp_off'], $s['nb_imp_peak'], $s['nb_exp']) - $actual
        : price($s['load_off'], $s['load_peak'], 0) - $actual;
}

function price(float $offPeakKwh, float $peakKwh, float $exportKwh): float {
    return ($offPeakKwh * OFF_PEAK_P + $peakKwh * PEAK_P - $exportKwh * EXPORT_P) / 100;
}

function sumMonths(array $months): array {
    $total = [];
    foreach ($months as $m) {
        foreach ($m as $k => $v) {
            $total[$k] = ($total[$k] ?? 0) + $v;
        }
    }
    return $total;
}

function daysBefore(string $date, int $days): string {
    return date('Y-m-d', strtotime("$date -$days days"));
}

function renderText(string $title, string $intro, array $sections): string {
    $out = "$title\n$intro\n";
    foreach ($sections as $s) {
        $out .= "\n{$s['title']}\n";
        foreach ($s['text'] ?? [] as $line) {
            $out .= "  $line\n";
        }
        $table = array_merge([$s['head']], $s['rows']);
        $widths = [];
        foreach ($table as $row) {
            foreach (array_values($row) as $c => $cell) {
                $widths[$c] = max($widths[$c] ?? 0, mb_strlen(cellText($cell)));
            }
        }
        foreach ($table as $row) {
            $cells = [];
            foreach (array_values($row) as $c => $cell) {
                $text = cellText($cell);
                $pad = $widths[$c] - mb_strlen($text);
                $cells[] = $c === 0 ? $text . str_repeat(' ', $pad) : str_repeat(' ', $pad) . $text;
            }
            $out .= '  ' . implode('  ', $cells) . "\n";
        }
        foreach ($s['notes'] ?? [] as $line) {
            $out .= "  $line\n";
        }
    }
    return $out;
}

function renderHtml(string $title, string $intro, array $sections): string {
    $e = fn ($v) => htmlspecialchars(cellText($v), ENT_QUOTES);
    $cell = 'border:1px solid #ccc;padding:4px 8px;';
    $background = ['green' => '#d4edda', 'amber' => '#fff3cd', 'red' => '#f8d7da'];
    $out = '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title></head>'
        . '<body style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
        . '<h2 style="margin:0 0 4px">' . $e($title) . '</h2><p style="color:#555">' . $e($intro) . '</p>';
    foreach ($sections as $s) {
        $out .= '<h3 style="margin:24px 0 4px">' . $e($s['title']) . '</h3>';
        foreach ($s['text'] ?? [] as $line) {
            $out .= '<p style="margin:0 0 8px;color:#555">' . $e($line) . '</p>';
        }
        $out .= '<table style="border-collapse:collapse"><tr>';
        foreach ($s['head'] as $c => $h) {
            $out .= '<th style="' . $cell . 'background:#f2f2f2;text-align:' . ($c === 0 ? 'left' : 'right') . '">'
                . $e($h) . '</th>';
        }
        $out .= '</tr>';
        $last = count($s['rows']) - 1;
        foreach ($s['rows'] as $r => $row) {
            $bold = $r === $last && in_array($row[0], ['12 months', 'Verdict'], true) ? 'font-weight:bold;' : '';
            $out .= '<tr>';
            foreach (array_values($row) as $c => $v) {
                $colour = is_array($v) ? 'background:' . $background[$v['rag']] . ';' : '';
                $out .= '<td style="' . $cell . $bold . $colour . 'text-align:' . ($c === 0 ? 'left' : 'right')
                    . ';white-space:pre">' . $e($v) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</table>';
        foreach ($s['notes'] ?? [] as $line) {
            $out .= '<p style="margin:4px 0;color:#555;font-size:12px">' . $e($line) . '</p>';
        }
    }
    return $out . '</body></html>';
}

/** A table cell with a red/amber/green background (HTML only). */
function rag(string $text, string $colour): array {
    return ['text' => $text, 'rag' => $colour];
}

function healthRag(float $shareOfHealthy): string {
    return $shareOfHealthy >= RAG_GREEN_FROM ? 'green' : ($shareOfHealthy >= RAG_AMBER_FROM ? 'amber' : 'red');
}

function exportShare(float $exportKwh, float $solarKwh, string $suffix = ''): array {
    $share = $exportKwh / max($solarKwh, 1e-9);
    $colour = $share > 1 ? 'red' : ($share >= EXPORT_AMBER_FROM ? 'amber' : 'green');
    return rag(pct($share) . $suffix, $colour);
}

function cellText(string|int|float|array $cell): string {
    return is_array($cell) ? $cell['text'] : (string)$cell;
}

function kwh(float $kwh, int $decimals = 0): string {
    return number_format($kwh, $decimals);
}

function pct(float $fraction): string {
    return sprintf('%.0f%%', 100 * $fraction);
}

function yesNo(bool $value): string {
    return $value ? 'yes' : 'no';
}

function gbp(float $amount, int $decimals = 0): string {
    return ($amount < 0 ? '-' : '') . '£' . number_format(abs($amount), $decimals);
}
