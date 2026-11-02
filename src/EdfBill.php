<?php

declare(strict_types=1);

/*
 * One EDF bill PDF, read from `pdftotext -raw` (the half-hourly pages only come out in row order that way), and the
 * checks that every figure taken from it adds up. See README.md.
 */
final class EdfBill
{
    // Pennies of rounding allowed when adding up pounds, and kWh when adding up half-hours.
    private const GBP_TOLERANCE = 0.011;
    private const KWH_TOLERANCE = 0.05;
    // EDF's own kWh x rate can be up to 1.5p out on a gas line.
    private const ENERGY_TOLERANCE = 0.02;
    // Readings are to 3 decimal places, billed kWh to 2.
    private const READING_TOLERANCE = 0.006;
    // Gas kWh = m3 x volume correction x calorific value / 3.6; the bill shows the calorific value to 1 decimal place.
    private const GAS_VOLUME_CORRECTION = 1.02264;
    private const GAS_CV_TOLERANCE = 0.002;

    // Page furniture repeated on every page, dropped before reading.
    private const FOOTER = '/^(edfenergy\.com|EDF Energy .*trading name.*|E hello@.*|P \d{4} .*|VAT no\. .*|Page \d+ of \d+|This is not a VAT invoice|E)$/';

    public string $ref;
    public string $date;
    public float $balanceBefore;
    public float $balanceAfter;
    public float $payments = 0.0;
    public float $creditsTotal = 0.0;
    private float $creditsSubtotal = 0.0;
    /** @var list<array{date: string, description: string, gbp: float}> */
    public array $credits = [];
    /** @var list<array<string, mixed>> one per fuel and tariff period */
    public array $charges = [];
    /** @var array<string, list<array{rate_p: float, kwh: float, cost_p: float}>> import half-hours by local date */
    public array $halfHours = [];
    /** @var array<string, float> each day's "Total consumption" */
    public array $dayTotals = [];

    public static function fromPdf(string $path): self
    {
        $text = shell_exec('pdftotext -raw ' . escapeshellarg($path) . ' - 2>/dev/null');
        if (!is_string($text) || $text === '') {
            throw new RuntimeException("pdftotext read nothing from $path (is poppler-utils installed?)");
        }
        return self::fromText($text);
    }

    public static function fromText(string $text): self
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn(string $l) => $l !== '' && !preg_match(self::FOOTER, $l)
        ));
        $flat = implode(' ', $lines);
        $bill = new self();

        self::must('/Bill reference: (\d+) \((\d+ \S+ \d{4})\)/', $flat, $m);
        $bill->ref = $m[1];
        $bill->date = self::date($m[2]);
        self::must('/Balance on your last bill (£[\d,.]+) (credit|debit)/', $flat, $m);
        $bill->balanceBefore = self::balance($m[1], $m[2]);
        self::must('/Your new balance (£[\d,.]+) (credit|debit)/', $flat, $m);
        $bill->balanceAfter = self::balance($m[1], $m[2]);

        if (preg_match('/ Payments (.*?) Your new balance/', $flat, $m)) {
            preg_match_all('/\d{1,2} [A-Z][a-z]{2,3}\.? \d{4} (£[\d,.]+)/', $m[1], $pays);
            $bill->payments = array_sum(array_map([self::class, 'gbp'], $pays[1]));
        }

        $bill->readCharges($lines);
        $bill->readCredits($flat);
        $bill->readHalfHours($lines);
        return $bill;
    }

    /** What doesn't add up; empty when the bill is consistent with itself. */
    public function problems(): array
    {
        $p = [];
        $chargesTotal = 0.0;
        foreach ($this->charges as $c) {
            $label = "{$c['supply']} {$c['period_from']}..{$c['period_to']}";
            $chargesTotal += $c['total_gbp'];

            $days = (int)(new DateTime($c['period_from']))->diff(new DateTime($c['period_to']))->days + 1;
            self::near($p, "$label: standing charge days", $c['standing_days'], $days, 0);
            self::near($p, "$label: standing charge", $c['standing_gbp'], $c['standing_days'] * $c['standing_p_per_day'] / 100);
            self::near($p, "$label: energy + standing = subtotal", $c['energy_gbp'] + $c['standing_gbp'], $c['subtotal_gbp']);
            self::near($p, "$label: VAT", $c['vat_gbp'], $c['subtotal_gbp'] * $c['vat_pct'] / 100);
            self::near($p, "$label: subtotal + VAT = total", $c['subtotal_gbp'] + $c['vat_gbp'], $c['total_gbp']);

            if ($c['rates']) {
                self::near($p, "$label: rates add up to kWh", array_sum(array_column($c['rates'], 'kwh')), $c['kwh'], 0.001);
                foreach ($c['rates'] as $r) {
                    self::near($p, "$label: {$r['rate_p']}p cost", $r['gbp'], $r['kwh'] * $r['rate_p'] / 100);
                }
                self::near($p, "$label: rate costs = energy", array_sum(array_column($c['rates'], 'gbp')), $c['energy_gbp']);
                $p = [...$p, ...$this->halfHourProblems($c, $label, $days)];
            } else {
                $sign = $c['supply'] === 'export' ? -1 : 1;
                self::near($p, "$label: kWh x rate", $c['energy_gbp'], $sign * $c['kwh'] * $c['unit_rate_p'] / 100, self::ENERGY_TOLERANCE);
            }

            if ($c['read_from'] !== null) {
                self::near($p, "$label: meter readings", $c['read_to'] - $c['read_from'], $c['m3'] ?? $c['kwh'], self::READING_TOLERANCE);
            }
            if ($c['supply'] === 'gas') {
                $kwh = $c['m3'] * self::GAS_VOLUME_CORRECTION * $c['calorific_value'] / 3.6;
                self::near($p, "$label: m3 to kWh", $kwh, $c['kwh'], $c['kwh'] * self::GAS_CV_TOLERANCE);
            }
        }

        self::near($p, 'credits add up', array_sum(array_column($this->credits, 'gbp')), $this->creditsSubtotal);
        self::near($p, 'balance: before - charges - credits + payments = after',
            $this->balanceBefore - $chargesTotal - $this->creditsTotal + $this->payments, $this->balanceAfter);

        $covered = array_merge(...array_map(fn($c) => $c['rates'] ? self::dates($c['period_from'], $c['period_to']) : [], $this->charges));
        foreach (array_diff(array_keys($this->halfHours), $covered) as $d) {
            $p[] = "half-hours on $d, outside every import period";
        }
        foreach ($this->halfHours as $d => $rows) {
            self::near($p, "$d: half-hour slots", count($rows), self::slots($d), 0);
            self::near($p, "$d: half-hours = day total", array_sum(array_column($rows, 'kwh')), $this->dayTotals[$d] ?? -1, 0.001);
            foreach ($rows as $i => $r) {
                self::near($p, "$d slot $i: cost", $r['cost_p'], $r['kwh'] * $r['rate_p'], 0.001);
            }
        }
        return $p;
    }

    /** Each half-hour's start in UTC, counting 30 minutes on from local midnight (46 or 50 slots when clocks change). */
    public static function slotStartUtc(string $localDate, int $slot): string
    {
        $t = (new DateTimeImmutable($localDate, new DateTimeZone('Europe/London')))->getTimestamp() + $slot * 1800;
        return gmdate('Y-m-d H:i:s', $t);
    }

    private function readCharges(array $lines): void
    {
        foreach ($lines as $i => $line) {
            if (!preg_match('/^(Electricity|Gas) charges for meter (\S+)$/', $line, $meter)
                || !preg_match('/^(.+) \((\d+ \S+ \d{4}) - (\d+ \S+ \d{4})\)$/', $lines[$i - 1], $head)) {
                continue;
            }
            // The block runs to its "Total ... charges for this period" line.
            for ($j = $i + 1; $j < count($lines) && !preg_match('/^Total \w+ charges for this period/', $lines[$j]); $j++);
            $block = implode(' ', array_slice($lines, $i + 1, $j - $i));

            $c = [
                'supply' => $meter[1] === 'Gas' ? 'gas' : (str_starts_with($head[1], 'Export') ? 'export' : 'import'),
                'tariff' => $head[1],
                'meter' => $meter[2],
                'period_from' => self::date($head[2]),
                'period_to' => self::date($head[3]),
                'read_from' => null, 'read_to' => null, 'm3' => null, 'calorific_value' => null,
                'kwh' => null, 'unit_rate_p' => null, 'energy_gbp' => null, 'rates' => [],
            ];

            preg_match_all('/\d{1,2} [A-Z][a-z]{2} \d{4} ([\d.]+) [A-Za-z ]*?reading/', $block, $reads);
            if ($reads[1]) {
                $c['read_from'] = (float)$reads[1][0];
                $c['read_to'] = (float)end($reads[1]);
            }

            if ($c['supply'] === 'gas') {
                self::must('/Consumption ([\d.]+) Units/', $block, $m);
                $c['m3'] = (float)$m[1];
                self::must('/Energy used\* ([\d.]+) kWh @ ([\d.]+)p\/kWh (-?£[\d,.]+)/', $block, $m);
                [$c['kwh'], $c['unit_rate_p'], $c['energy_gbp']] = [(float)$m[1], (float)$m[2], self::gbp($m[3])];
            } elseif (preg_match('/Breakdown by rate Rate Consumption Cost (.*?) Total consumption ([\d.]+) kWh @ [\d.]+p\/kWh † (£[\d,.]+)/', $block, $m)) {
                preg_match_all('/([\d.]+)p\/kWh ([\d.]+) kWh (£[\d,.]+)/', $m[1], $rates, PREG_SET_ORDER);
                $c['rates'] = array_map(fn($r) => ['rate_p' => (float)$r[1], 'kwh' => (float)$r[2], 'gbp' => self::gbp($r[3])], $rates);
                [$c['kwh'], $c['energy_gbp']] = [(float)$m[2], self::gbp($m[3])];
            } else {
                self::must('/Electricity used ([\d.]+) kWh @ ([\d.]+)p\/kWh (-?£[\d,.]+)/', $block, $m);
                [$c['kwh'], $c['unit_rate_p'], $c['energy_gbp']] = [(float)$m[1], (float)$m[2], self::gbp($m[3])];
            }

            self::must('/Standing charge (\d+) days @ ([\d.]+)p\/day (-?£[\d,.]+)/', $block, $m);
            [$c['standing_days'], $c['standing_p_per_day'], $c['standing_gbp']] = [(int)$m[1], (float)$m[2], self::gbp($m[3])];
            self::must('/Subtotal of charges before VAT (-?£[\d,.]+)/', $block, $m);
            $c['subtotal_gbp'] = self::gbp($m[1]);
            self::must('/VAT @ ([\d.]+)% (-?£[\d,.]+)/', $block, $m);
            [$c['vat_pct'], $c['vat_gbp']] = [(float)$m[1], self::gbp($m[2])];
            self::must('/Total \w+ charges for this period (-?£[\d,.]+)/', $block, $m);
            $c['total_gbp'] = self::gbp($m[1]);

            if ($c['supply'] === 'gas') {
                // The calorific value is on the tariff panel after the block.
                $after = implode(' ', array_slice($lines, $j, 60));
                self::must('/[\d.]+ × [\d.]+ × ([\d.]+) †? ?÷ 3\.6/u', $after, $m);
                $c['calorific_value'] = (float)$m[1];
            }
            $this->charges[] = $c;
        }
        if (!$this->charges) {
            throw new RuntimeException('No charges found');
        }
    }

    private function readCredits(string $flat): void
    {
        if (!preg_match('/.* Credits (.*?) Subtotal of other credits before VAT (-?£[\d,.]+) .*?Total of other credits (-?£[\d,.]+)/', $flat, $m)) {
            return;
        }
        preg_match_all('/(\d{1,2} [A-Z][a-z]{2} \d{4}) (.+?) (-£[\d,.]+)/', $m[1], $items, PREG_SET_ORDER);
        foreach ($items as $it) {
            $this->credits[] = ['date' => self::date($it[1]), 'description' => $it[2], 'gbp' => self::gbp($it[3])];
        }
        $this->creditsSubtotal = self::gbp($m[2]);
        $this->creditsTotal = self::gbp($m[3]);
    }

    private function readHalfHours(array $lines): void
    {
        $day = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\d{1,2})(?:st|nd|rd|th) ([A-Z][a-z]+ \d{4})$/', $line, $m)) {
                $day = self::date("$m[1] $m[2]");
                $this->halfHours[$day] = [];
            } elseif ($day && $line === 'Total consumption' && preg_match('/^([\d.]+) kWh$/', $lines[$i + 1] ?? '', $m)) {
                $this->dayTotals[$day] = (float)$m[1];
            } elseif ($day && preg_match('/^\d\d:\d\d - \d\d:\d\d ([\d.]+) ([\d.]+) ([\d.]+)$/', $line, $m)) {
                $this->halfHours[$day][] = ['rate_p' => (float)$m[1], 'kwh' => (float)$m[2], 'cost_p' => (float)$m[3]];
            }
        }
    }

    /** An import period's half-hours: one full day per day, adding up to the kWh billed at each rate. */
    private function halfHourProblems(array $c, string $label, int $days): array
    {
        $p = [];
        $byRate = array_fill_keys(array_map(fn($r) => (string)$r['rate_p'], $c['rates']), 0.0);
        $found = 0;
        foreach (self::dates($c['period_from'], $c['period_to']) as $d) {
            if (!isset($this->halfHours[$d])) {
                continue;
            }
            $found++;
            foreach ($this->halfHours[$d] as $r) {
                $key = (string)$r['rate_p'];
                if (!isset($byRate[$key])) {
                    $p[] = "$label: half-hour on $d at {$r['rate_p']}p, not a billed rate";
                    continue;
                }
                $byRate[$key] += $r['kwh'];
            }
        }
        self::near($p, "$label: days of half-hours", $found, $days, 0);
        foreach ($c['rates'] as $r) {
            self::near($p, "$label: half-hours at {$r['rate_p']}p", $byRate[(string)$r['rate_p']], $r['kwh'], self::KWH_TOLERANCE);
        }
        return $p;
    }

    private static function near(array &$problems, string $what, float $got, float $want, float $tolerance = self::GBP_TOLERANCE): void
    {
        if (abs($got - $want) > $tolerance) {
            $problems[] = sprintf('%s: %s, expected %s', $what, round($got, 4), round($want, 4));
        }
    }

    private static function must(string $pattern, string $subject, ?array &$m): void
    {
        if (!preg_match($pattern, $subject, $m)) {
            throw new RuntimeException("Not found in bill: $pattern");
        }
    }

    /** "27 Sept. 2026", "16 Aug. 2026", "27 September 2026" as Y-m-d. */
    private static function date(string $s): string
    {
        $s = str_replace('.', '', preg_replace('/\bSept\b/', 'Sep', $s));
        $d = DateTime::createFromFormat('!j M Y', $s) ?: DateTime::createFromFormat('!j F Y', $s);
        if (!$d) {
            throw new RuntimeException("Unreadable date: $s");
        }
        return $d->format('Y-m-d');
    }

    private static function gbp(string $s): float
    {
        return (float)str_replace(['£', ','], '', $s);
    }

    private static function balance(string $amount, string $creditOrDebit): float
    {
        return $creditOrDebit === 'credit' ? self::gbp($amount) : -self::gbp($amount);
    }

    private static function dates(string $from, string $to): array
    {
        $out = [];
        for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    private static function slots(string $localDate): int
    {
        $tz = new DateTimeZone('Europe/London');
        $start = new DateTimeImmutable($localDate, $tz);
        return intdiv($start->modify('+1 day')->getTimestamp() - $start->getTimestamp(), 1800);
    }
}
