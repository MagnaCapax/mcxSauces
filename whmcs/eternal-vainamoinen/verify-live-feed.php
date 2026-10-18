<?php
declare(strict_types=1);

/**
 * verify-live-feed.php — check the live Eternal Väinämöinen drop feed against the published engine.
 *
 * WHY THIS EXISTS
 * The drip publishes, for every limited product ("Claimed: N of LIMIT · due DATE"), the numbers behind its odds.
 * This script recomputes the maximum drop chance from those numbers with the published engine and checks the
 * rules, so anyone can confirm the live campaign does what this code says:
 *   1. claimed + pending + open_now <= limit                      (the limit is never passed)
 *   2. max_drop_chance_per_tick == limitedRate(limit, claimed + pending, open_now, due − generated_at, interval)
 *   3. drop_chance_per_tick <= max_drop_chance_per_tick            (no knob drips faster than the maximum)
 * On a tick where a product was released, open_now already includes that slot, so rule 2 is also accepted with
 * open_now − 1. Prints one line per limited product; exit 0 = all rules hold, 1 = a rule failed, 2 = feed unusable.
 *
 * Usage: php verify-live-feed.php [FEED_URL_OR_FILE]   (default: https://pulsedmedia.com/data/v1/eternal-drops.json)
 *
 * SPDX-License-Identifier: Apache-2.0
 */

require __DIR__ . '/EternalVainamoinenRelease.php';

$src  = $argv[1] ?? 'https://pulsedmedia.com/data/v1/eternal-drops.json';
$feed = json_decode((string) @file_get_contents($src), true);
if (!is_array($feed) || !isset($feed['generated_at'], $feed['tick_interval_seconds'], $feed['live_odds'])) {
    fwrite(STDERR, "feed unusable: $src\n");
    exit(2);
}
$now      = (float) strtotime((string) $feed['generated_at']);
$interval = (float) $feed['tick_interval_seconds'];
$fails = 0; $checked = 0;
foreach ($feed['live_odds'] as $o) {
    if (!isset($o['limit'])) { continue; }
    $checked++;
    $taken = (int) $o['claimed'] + (int) ($o['pending'] ?? 0);
    $open  = (int) $o['open_now'];
    $left  = (float) strtotime((string) $o['due']) - $now;
    $max   = (float) $o['max_drop_chance_per_tick'];
    $want  = EternalVainamoinenRelease::limitedRate((int) $o['limit'], $taken, $open, $left, $interval);
    $wantJustReleased = EternalVainamoinenRelease::limitedRate((int) $o['limit'], $taken, max(0, $open - 1), $left, $interval);
    $r1 = $taken + $open <= (int) $o['limit'];
    $r2 = abs($max - $want) < 1e-5 || abs($max - $wantJustReleased) < 1e-5;
    $r3 = (float) $o['drop_chance_per_tick'] <= $max + 1e-9;
    $ok = $r1 && $r2 && $r3;
    if (!$ok) { $fails++; }
    printf("%s %s: taken %d + open %d of %d, due %s, max %.5f (recomputed %.5f), chance %.5f%s\n",
        $ok ? 'OK  ' : 'FAIL', $o['type'], $taken, $open, $o['limit'], $o['due'], $max, $want,
        (float) $o['drop_chance_per_tick'], $ok ? '' : ' [' . implode(',', array_keys(array_filter(['limit' => !$r1, 'max' => !$r2, 'chance' => !$r3]))) . ']');
}
echo $checked === 0 ? "no limited products in the feed\n" : ($fails === 0 ? "ALL $checked LIMITED PRODUCTS PASS\n" : "$fails of $checked FAILED\n");
exit($fails === 0 ? 0 : 1);
