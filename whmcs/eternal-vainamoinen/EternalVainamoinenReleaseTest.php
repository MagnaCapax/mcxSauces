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

// --- Stage 1: GLOBAL open-buffer governor: drip rate tapers as total open -> TARGET_OPEN (50), band 10 ---
$check(abs($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 0.0), 1.0, 1.0) - 0.5) < 1e-9,
    'governor: 0 open (<= TARGET-BAND=40) => factor 1 => full rate 0.5');
$check(abs($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 40.0), 1.0, 1.0) - 0.5) < 1e-9,
    'governor: 40 open (== TARGET-BAND) => factor 1 => full rate 0.5 (taper starts just above)');
$check(abs($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 45.0), 1.0, 1.0) - 0.25) < 1e-9,
    'governor: 45 open (halfway in band) => factor (50-45)/10=0.5 => rate 0.5*0.5=0.25');
$check($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 50.0), 1.0, 1.0) === 0.0,
    'governor: 50 open (== TARGET_OPEN) => factor 0 => rate 0 (holds total open ~50)');
$check($e->dropProbability(new Pacing([0.5, 0.5, 0.5, 0.5], 100.0, 999.0), 1.0, 1.0) === 0.0,
    'governor: huge glut (999 open) => factor 0 => rate 0 (never releases above target)');

// --- Stage 2: selection weights (free-N protection level + over-supply open-slot curb) ---
$types = [
    // A/C/E/F have free >= MAX(10), so the dynamic ceiling min(MAX, free) = MAX — static-cap behaviour:
    // (MAX lowered 15->10 on 2026-07-02)
    'A' => new SlotType('A', 10, 3,  0, 1.0, 1.0, 1.0),   // free-N = 7, open 0 (< K) => no curb
    'B' => new SlotType('B',  5, 5,  0, 1.0, 1.0, 1.0),   // free-N = 0 (at reserve)
    'C' => new SlotType('C', 20, 3,  8, 1.0, 1.0, 1.0),   // free-N = 17, open 8 => ÷(1+(8-3)/10)=÷1.5 => 11.333
    'E' => new SlotType('E', 30, 3, 15, 1.0, 1.0, 1.0),   // open 15 >= min(MAX=10, free=30) => hard 0
    'F' => new SlotType('F', 20, 3,  3, 1.0, 1.0, 1.0),   // free-N = 17, open 3 == K => no curb (one-sided)
    // G/H have free < MAX, so the DYNAMIC ceiling = free binds — LOAD-BEARING reserve-safety property:
    'G' => new SlotType('G',  8, 3,  8, 1.0, 1.0, 1.0),   // open 8 >= min(10, free=8) => hard 0 (true-capacity ceiling)
    'H' => new SlotType('H',  8, 3,  6, 1.0, 1.0, 1.0),   // open 6 < ceiling 8 => (8-3)/(1+(6-3)/10)=5/1.3
];
$w = $e->weights($types);
$check($w['A'] === 7.0, 'weight A = free - N = 10 - 3 = 7 (open below K, no curb)');
$check($w['B'] === 0.0, 'weight B = free - N = 5 - 5 = 0 (reserve protected)');
$check(abs($w['C'] - (17.0 / 1.5)) < 1e-9, 'weight C = (20-3) / (1+(8-3)/10) = 17/1.5 = 11.333 (over-supply divider)');
$check($w['E'] === 0.0, 'weight E = 0 (open 15 >= min(MAX=10,free) hard stop)');
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

// --- Limited releases ("Claimed: N of LIMIT" by a due date): maximum rate, hard stop, knob ceiling ---
$day = 86400.0; $tick = 300.0;
$check(abs(EternalVainamoinenRelease::limitedRate(49, 0, 0, 100 * $day, $tick) - 49 / (100 * $day / $tick)) < 1e-12,
    'limitedRate: 49 still to release over 100 days = 49 / (ticks in 100 days) per tick');
$check(abs(EternalVainamoinenRelease::limitedRate(49, 40, 3, 6 * $day, $tick) - 6 / (6 * $day / $tick)) < 1e-12,
    'limitedRate: still to release = limit - claimed - open (49-40-3 = 6) over the time left');
$check(EternalVainamoinenRelease::limitedRate(49, 49, 0, 74 * $day, $tick) === 0.0,
    'limitedRate: 0 at the limit (49 of 49), however long until the due date');
$check(EternalVainamoinenRelease::limitedRate(49, 47, 5, 74 * $day, $tick) === 0.0,
    'limitedRate: 0 when claimed + open already exceeds the limit');
$check(EternalVainamoinenRelease::limitedRate(49, 45, 0, -3600.0, $tick) === 1.0,
    'limitedRate: past the due date with 4 still allowed => 1 per tick (finish what the gates held back)');
$check(EternalVainamoinenRelease::withinLimit(49, 48, 0) && !EternalVainamoinenRelease::withinLimit(49, 49, 0)
    && !EternalVainamoinenRelease::withinLimit(49, 48, 1),
    'withinLimit: 48 claimed + 0 open may release one more; 49+0 or 48+1 may not');
$max = EternalVainamoinenRelease::limitedRate(24, 10, 0, 14 * $day, $tick);
$check(abs($e->dropProbability(new Pacing([$max], 14.0, 0.0, $max), 1.0, 3.0) - $max) < 1e-12,
    'maxRate ceiling: an operator boost (x3) can NOT push a limited SKU above its limitedRate');
$check(abs($e->dropProbability(new Pacing([$max], 14.0, 0.0, $max), 1.0, 0.5) - 0.5 * $max) < 1e-12,
    'maxRate ceiling: a throttle (x0.5) still slows a limited SKU');

// Seeded whole-horizon run: a 49-limit SKU, 74 days to its due date, every released slot bought within the tick
// (the fastest possible demand). The limit is never passed, and it is spread to the due date.
$soldOutAt = [];
foreach (range(1, 20) as $seed) {
    mt_srand($seed);
    $sim = new EternalVainamoinenRelease(static fn (): float => mt_rand() / (mt_getrandmax() + 1.0));
    $limit = 49; $claimed = 0; $ticks = (int) (74 * $day / $tick); $over = false; $last = null;
    for ($i = 0; $i < $ticks + 288; $i++) {
        $r = EternalVainamoinenRelease::limitedRate($limit, $claimed, 0, ($ticks - $i) * $tick, $tick);
        $type = ['L' => new SlotType('L', 1000, 0, 0, 1.0, 1.0, 1.0)];
        if ($sim->decide(new Pacing([$r], (float) ($limit - $claimed), 0.0, $r), 1.0, $type, 1.0) !== null
            && EternalVainamoinenRelease::withinLimit($limit, $claimed, 0)) {
            $claimed++; $last = $i;                                  // released and bought at once
        }
        if ($claimed > $limit) { $over = true; }
    }
    $check(!$over && $claimed === $limit, "seed $seed: all 49 released, never above the limit (got $claimed)");
    $soldOutAt[] = $last / $ticks;
}
sort($soldOutAt);
$median = $soldOutAt[intdiv(count($soldOutAt), 2)];
$check($median > 0.95, 'median sell-out lands in the last 5% before the due date (got ' . round($median, 3) . ' of the horizon)');
$check($soldOutAt[0] > 0.9, 'earliest sell-out of 20 seeds is after 90% of the horizon (got ' . round($soldOutAt[0], 3) . ')');
echo $fails === 0 ? "ALL $tests TESTS PASSED\n" : "$fails of $tests FAILED\n";
exit($fails === 0 ? 0 : 1);
