<?php

declare(strict_types=1);

/*
 * EDF bill importer: reads every PDF in ../home_energy_private/edf_bills (pdftotext, from poppler-utils) into the
 * edf_* tables. A bill is only loaded if every figure on it adds up (EdfBill::problems); re-running replaces each
 * bill's rows, so it is safe to run every night.
 *
 * Usage:
 *   php edf_import.php --config=/path/to/givenergy.ini
 */

const BILLS_DIR = __DIR__ . '/../home_energy_private/edf_bills';

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/SchemaManager.php';
require_once __DIR__ . '/src/EdfBill.php';

main();

function main(): void {
    $opts = getopt('', ['config::', 'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::']);
    $pdo = connectPdo(loadDbSettings($opts, loadConfigFile($opts)));
    (new SchemaManager($pdo))->ensureSchema();

    $files = glob(BILLS_DIR . '/*.pdf');
    if (!$files) {
        fwrite(STDERR, 'No bills in ' . BILLS_DIR . "\n");
        exit(1);
    }

    $refused = 0;
    foreach ($files as $file) {
        $name = basename($file);
        try {
            $bill = EdfBill::fromPdf($file);
            $problems = $bill->problems();
        } catch (RuntimeException $e) {
            $problems = [$e->getMessage()];
        }
        if ($problems) {
            $refused++;
            echo "$name: NOT LOADED, doesn't add up:\n  " . implode("\n  ", $problems) . "\n";
            continue;
        }
        save($pdo, $bill, $name);
        printf("%s: bill %s (%s), %d charges, %d credits, %d half-hours\n", $name, $bill->ref, $bill->date,
            count($bill->charges), count($bill->credits), array_sum(array_map('count', $bill->halfHours)));
    }
    exit($refused ? 1 : 0);
}

function save(PDO $pdo, EdfBill $bill, string $fileName): void {
    $pdo->beginTransaction();
    foreach (['edf_halfhours', 'edf_credits', 'edf_charges', 'edf_bills'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE bill_ref = ?")->execute([$bill->ref]);
    }

    $pdo->prepare('INSERT INTO edf_bills (bill_ref, bill_date, file_name, balance_before_gbp, balance_after_gbp,
            payments_gbp, credits_gbp) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$bill->ref, $bill->date, $fileName, $bill->balanceBefore, $bill->balanceAfter, $bill->payments,
            $bill->creditsTotal]);

    $charge = $pdo->prepare('INSERT INTO edf_charges (bill_ref, supply, period_from, period_to, tariff, meter,
            read_from, read_to, m3, calorific_value, kwh, unit_rate_p, off_peak_kwh, off_peak_p, peak_kwh, peak_p,
            energy_gbp, standing_days, standing_p_per_day, standing_gbp, vat_pct, vat_gbp, total_gbp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($bill->charges as $c) {
        if (count($c['rates']) > 2) {
            throw new RuntimeException("Bill {$bill->ref}: more than two rates, edf_charges holds off-peak and peak only");
        }
        usort($c['rates'], fn($a, $b) => $a['rate_p'] <=> $b['rate_p']);
        [$off, $peak] = [$c['rates'][0] ?? null, $c['rates'][1] ?? null];
        $charge->execute([$bill->ref, $c['supply'], $c['period_from'], $c['period_to'], $c['tariff'], $c['meter'],
            $c['read_from'], $c['read_to'], $c['m3'], $c['calorific_value'], $c['kwh'], $c['unit_rate_p'],
            $off['kwh'] ?? null, $off['rate_p'] ?? null, $peak['kwh'] ?? null, $peak['rate_p'] ?? null,
            $c['energy_gbp'], $c['standing_days'], $c['standing_p_per_day'], $c['standing_gbp'], $c['vat_pct'],
            $c['vat_gbp'], $c['total_gbp']]);
    }

    $credit = $pdo->prepare('INSERT INTO edf_credits (bill_ref, line_no, credit_date, description, gbp) VALUES (?, ?, ?, ?, ?)');
    foreach ($bill->credits as $i => $c) {
        $credit->execute([$bill->ref, $i + 1, $c['date'], $c['description'], $c['gbp']]);
    }

    $halfHour = $pdo->prepare('INSERT INTO edf_halfhours (start_utc, local_date, slot, rate_p, kwh, cost_p, bill_ref)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($bill->halfHours as $date => $rows) {
        foreach ($rows as $slot => $r) {
            $halfHour->execute([EdfBill::slotStartUtc($date, $slot), $date, $slot, $r['rate_p'], $r['kwh'], $r['cost_p'],
                $bill->ref]);
        }
    }
    $pdo->commit();
}
