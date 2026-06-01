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

// --- Stage 1: rate (the 3-scale floor model) ---
$check(abs($e->dropProbability(new Pacing(0.08, 0.0, 0.0, 100.0), 1.0, 1.0) - 0.08) < 1e-9,
    'daily floor holds when ahead (chance never quite zero)');
$check($e->dropProbability(new Pacing(0.08, 0.5, 0.5, 0.0), 1.0, 1.0) === 0.0,
    'totalRemaining <= 0 => hard stop at the target');
$check(abs($e->dropProbability(new Pacing(0.08, 0.20, 0.05, 100.0), 1.0, 1.0) - 0.20) < 1e-9,
    'monthly/total need pulls the rate UP when behind (catch-up)');
$check($e->dropProbability(new Pacing(0.08, 0.9, 0.9, 100.0), 1.0, 0.0) === 0.0,
    'operator control 0 = pause (kill switch)');
$check(abs($e->dropProbability(new Pacing(0.9, 0.9, 0.9, 100.0), 0.1, 1.0) - 0.1) < 1e-9,
    'account cap bounds the rate');

// --- Stage 2: selection weights (free-N protection level + suppression) ---
$types = [
    'A' => new SlotType('A', 10, 3, 0, 1, 1.0, 1.0, 1.0),   // free-N = 7
    'B' => new SlotType('B',  5, 5, 0, 1, 1.0, 1.0, 1.0),   // free-N = 0 (at reserve)
    'C' => new SlotType('C', 10, 2, 1, 1, 1.0, 1.0, 1.0),   // stock 1 >= threshold 1 => suppressed
];
$w = $e->weights($types);
$check($w['A'] === 7.0, 'weight A = free - N = 10 - 3 = 7');
$check($w['B'] === 0.0, 'weight B = free - N = 5 - 5 = 0 (reserve protected)');
$check($w['C'] === 0.0, 'weight C = 0 (unsold stock suppresses)');
$check(abs(array_sum($e->publishedOdds($types)) - 1.0) < 1e-9, 'published odds sum to 1 when any eligible');

// --- Stage 2: cumulative-weight pick is proportional (statistical, 7:3 -> ~0.70) ---
$t2 = [
    'A' => new SlotType('A', 10, 3, 0, 9, 1.0, 1.0, 1.0),   // weight 7
    'D' => new SlotType('D',  6, 3, 0, 9, 1.0, 1.0, 1.0),   // weight 3
];
mt_srand(1);
$algo = new EternalVainamoinenRelease(static fn (): float => mt_rand() / (mt_getrandmax() + 1.0));
$cnt = ['A' => 0, 'D' => 0];
for ($i = 0; $i < 20000; $i++) {
    $c = $algo->decide(new Pacing(1.0, 1.0, 1.0, 1e9), 1.0, $t2, 1.0);   // pacing forces a drop every tick
    if ($c !== null) { $cnt[$c]++; }
}
$shareA = $cnt['A'] / ($cnt['A'] + $cnt['D']);
$check(abs($shareA - 0.7) < 0.03, 'cumulative-weight pick proportional ~70/30 (got ' . round($shareA, 3) . ')');

echo $fails === 0 ? "ALL $tests TESTS PASSED\n" : "$fails of $tests FAILED\n";
exit($fails === 0 ? 0 : 1);
