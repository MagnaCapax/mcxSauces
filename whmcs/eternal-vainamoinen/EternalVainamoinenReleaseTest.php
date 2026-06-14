<?php
declare(strict_types=1);

/**
 * Eternal Väinämöinen campaign — algorithm tests (plain PHP, no framework, FOSS-friendly).
 *
 * Locks every reviewed behaviour as a regression. Run: `php EternalVainamoinenReleaseTest.php` (non-zero exit on fail).
 *
 * Made for pulsedmedia.com
 * SPDX-License-Identifier: Apache-2.0
 * @author Aleksi Ursin  @copyright 2026 Magna Capax Finland Oy
 */

require __DIR__ . '/EternalVainamoinenRelease.php';

$tests = 0; $fails = 0;
$check = function (bool $cond, string $msg) use (&$tests, &$fails): void {
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $msg\n"; }
};

$e = new EternalVainamoinenRelease(static fn (): float => 0.5);

// --- Stage 1: rate = MIN over the four window rates (tightest governs); 0 once any window is at/ahead ---
$check($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 0.0), 1.0, 1.0) === 0.0,
    'totalRemaining <= 0 => already released the whole target => stop');
$check($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0), 1.0, 0.0) === 0.0,
    'operator control 0 = pause (kill switch)');
$check(abs($e->dropProbability(new Pacing([0.3, 0.1, 0.5, 0.4], 100.0), 1.0, 1.0) - 0.1) < 1e-9,
    'tightest window governs: chance = min of the four window rates');
$check($e->dropProbability(new Pacing([0.5, 0.0, 0.5, 0.5], 100.0), 1.0, 1.0) === 0.0,
    'any window at/ahead of schedule (rate 0) brakes the whole drip to 0');
$check(abs($e->dropProbability(new Pacing([1.0, 1.0, 1.0, 1.0], 100.0), 0.1, 1.0) - 0.1) < 1e-9,
    'account cap bounds the rate');

// --- Stage 1: the RATE is independent of over-supply — over-supply is a STAGE-2 (which-SKU) concern only ---
$check(abs($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 25.0), 1.0, 1.0) - 0.5) < 1e-9,
    'totalOpen does NOT damp the rate (25 open => chance still 0.5) — over-supply lives in Stage 2, not the rate');
$check(abs($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 999.0), 1.0, 1.0) - 0.5) < 1e-9,
    'even a huge open glut does NOT zero the rate — Stage 2 weights stop releasing an over-supplied SKU');

// --- Stage 2: selection weights (free-N protection level + over-supply open-slot curb) ---
$types = [
    // A/C/E/F have free >= MAX(15), so the dynamic ceiling min(MAX, free) = MAX — static-cap behaviour:
    'A' => new SlotType('A', 10, 3,  0, 1.0, 1.0, 1.0),   // free-N = 7, open 0 (< K) => no curb
    'B' => new SlotType('B',  5, 5,  0, 1.0, 1.0, 1.0),   // free-N = 0 (at reserve)
    'C' => new SlotType('C', 20, 3, 13, 1.0, 1.0, 1.0),   // free-N = 17, open 13 => ÷(1+(13-3)/10)=÷2 => 8.5
    'E' => new SlotType('E', 30, 3, 15, 1.0, 1.0, 1.0),   // open 15 >= min(MAX=15, free=30) => hard 0
    'F' => new SlotType('F', 20, 3,  3, 1.0, 1.0, 1.0),   // free-N = 17, open 3 == K => no curb (one-sided)
    // G/H have free < MAX, so the DYNAMIC ceiling = free binds — LOAD-BEARING reserve-safety property:
    'G' => new SlotType('G',  8, 3,  8, 1.0, 1.0, 1.0),   // open 8 >= min(15, free=8) => hard 0 (true-capacity ceiling)
    'H' => new SlotType('H',  8, 3,  6, 1.0, 1.0, 1.0),   // open 6 < ceiling 8 => (8-3)/(1+(6-3)/10)=5/1.3
];
$w = $e->weights($types);
$check($w['A'] === 7.0, 'weight A = free - N = 10 - 3 = 7 (open below K, no curb)');
$check($w['B'] === 0.0, 'weight B = free - N = 5 - 5 = 0 (reserve protected)');
$check(abs($w['C'] - 8.5) < 1e-9, 'weight C = (20-3) / (1+(13-3)/10) = 17/2 = 8.5 (over-supply divider)');
$check($w['E'] === 0.0, 'weight E = 0 (open 15 >= min(MAX,free) hard stop)');
$check(abs($w['F'] - 17.0) < 1e-9, 'weight F = 17, open 3 == K so NO curb (one-sided: no ramp at/below K)');
$check($w['G'] === 0.0, 'weight G = 0 (open 8 >= dynamic ceiling free=8 — LOAD-BEARING: open never exceeds true capacity)');
$check(abs($w['H'] - (5.0 / 1.3)) < 1e-9, 'weight H = (8-3)/(1+(6-3)/10) = 5/1.3 (dynamic ceiling free=8 not yet reached)');
$check(abs(array_sum($e->publishedOdds($types)) - 1.0) < 1e-9, 'published odds sum to 1 when any eligible');

// --- Stage 2: cumulative-weight pick is proportional (statistical, 7:3 -> ~0.70) ---
$t2 = [
    'A' => new SlotType('A', 10, 3, 0, 1.0, 1.0, 1.0),   // weight 7 (open 0 => no curb)
    'D' => new SlotType('D',  6, 3, 0, 1.0, 1.0, 1.0),   // weight 3 (open 0 => no curb)
];
mt_srand(1);
$algo = new EternalVainamoinenRelease(static fn (): float => mt_rand() / (mt_getrandmax() + 1.0));
$cnt = ['A' => 0, 'D' => 0];
for ($i = 0; $i < 20000; $i++) {
    $c = $algo->decide(new Pacing([1.0, 1.0, 1.0, 1.0], 1e9), 1.0, $t2, 1.0);   // all windows maxed => chance 1.0, forces a drop every tick
    if ($c !== null) { $cnt[$c]++; }
}
$shareA = $cnt['A'] / ($cnt['A'] + $cnt['D']);
$check(abs($shareA - 0.7) < 0.03, 'cumulative-weight pick proportional ~70/30 (got ' . round($shareA, 3) . ')');

echo $fails === 0 ? "ALL $tests TESTS PASSED\n" : "$fails of $tests FAILED\n";
exit($fails === 0 ? 0 : 1);
