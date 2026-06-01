<?php
declare(strict_types=1);

/**
 * Eternal Väinämöinen slot delivery mechanism — simulator (FOSS, synthetic data only).
 *
 * WHY THIS EXISTS
 * ---------------
 * Validates the release engine over a FULL campaign without any internal DB values, exercising the parts the
 * pure engine cannot self-enforce. v2 (2026-06-01) fixes two improprieties in v1 and adds real telemetry:
 *   - PROPER three-scale pacing: distinct DAILY floor, MONTHLY need (reset each month), and TOTAL need, so the
 *     max() catch-up behaviour is actually exercised (v1 collapsed monthly≈total — it never tested catch-up).
 *   - REAL two-stream design: workhorse + rare are paced as SEPARATE streams (rare priority, ≤1 release/tick),
 *     so even rare-spread is validated as built (v1 ran a single stream — rare-via-multiplier was never the design).
 *   - RICH telemetry: convergence per month, reserve margin per group, per-SKU free/unsold/floor trajectories,
 *     an empirical PUBLISHED == ENFORCED check (realized drop share vs the odds the engine published), and the
 *     rare inter-drop spread.
 *
 * It models the SHARED-POOL interplay (several SKUs draw free slots from one physical pool) and asserts the
 * invariants every tick: reserve never breached, pool never over-allocated. Inputs are synthetic groups/types;
 * no real capacity or customer data appears anywhere. The engine is auditable by running this.
 *
 * Made for pulsedmedia.com
 * SPDX-License-Identifier: Apache-2.0
 * @author    Aleksi Ursin
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */

require_once __DIR__ . '/EternalVainamoinenRelease.php';

const SIM_MONTH_SECONDS = 30.44 * 86400.0;   // average Gregorian month

final class SimGroup
{
    public function __construct(
        public string $id,
        public float $poolFreeTiB,   // shared physical pool for all SKUs in this group
        public float $reserveTiB,    // storage held off the campaign for normal full-price sales
    ) {}
}

final class SimType
{
    public function __construct(
        public string $id,
        public string $group,
        public string $stream,        // 'workhorse' | 'rare'  (rare = own paced stream for even spread)
        public float $sizeTiB,        // storage each claimed slot consumes from the group pool
        public float $tierWeight,
        public float $withinTierBias,
        public float $multiplier,
        public int $setAsideN,        // per-SKU protection level (the free − N reserve)
        public int $stockThreshold,   // suppress once released-but-unsold reaches this
        public float $claimRate,      // per-tick probability a released-unsold slot is claimed (synthetic demand)
        public int $qty = 0,          // released-but-unsold
        public int $active = 0,       // claimed (live customers)
    ) {}
}

/**
 * One scenario run. The engine is the real EternalVainamoinenRelease; everything here is the impure caller the
 * production cron will mirror (shared-pool free derivation, per-stream pacing, demand, telemetry).
 */
final class EternalVainamoinenSimulator
{
    /**
     * @param array<string,SimGroup> $groups
     * @param array<string,SimType>  $types
     * @param callable():float       $rng
     * @param array{rareTotal?:int,capHeadroom?:float,pauseFrom?:float,pauseTo?:float,sampleEvery?:int} $opts
     */
    public function __construct(
        private array $groups,
        private array $types,
        private int $monthlyTarget,
        private int $campaignMonths,
        private int $intervalSeconds,
        private $rng,
        private array $opts = [],
    ) {}

    public function run(): array
    {
        // ── HOW ONE TICK WORKS (a tick = one cron run) ────────────────────────────────────────────
        // Each pass of the main loop below does four things, in order:
        //   1. Locate this tick in time — which campaign month, and seconds left in the month and campaign.
        //   2. For each stream (rare first, so it wins ties), rebuild every SKU's free-slot count from its
        //      SHARED group pool, then ask the engine: drop this tick? which SKU? At most ONE release/tick.
        //   3. Synthetic demand — some released-but-unsold slots get claimed; each claim consumes the shared
        //      pool (this is what makes a group's SKUs compete for the same physical space).
        //   4. Invariants — assert the reserve was never breached and the pool never over-allocated.
        // The loop ends when active reaches the target (the mean) or the clock runs out. Everything returned
        // at the bottom is telemetry: convergence, reserve margins, and the published==enforced gap (realized
        // drop-share vs the odds the engine published) — the empirical fairness proof.
        // ──────────────────────────────────────────────────────────────────────────────────────────
        $algo            = new EternalVainamoinenRelease($this->rng);
        $campaignSeconds = SIM_MONTH_SECONDS * $this->campaignMonths;
        $totalTarget     = $this->monthlyTarget * $this->campaignMonths;
        $ticks           = (int) floor($campaignSeconds / $this->intervalSeconds);
        $rareTotal       = (int) ($this->opts['rareTotal'] ?? 0);
        $capHeadroom     = (float) ($this->opts['capHeadroom'] ?? 1.0);
        $pauseFrom       = (float) ($this->opts['pauseFrom'] ?? -1.0);   // operator-control pause window (seconds)
        $pauseTo         = (float) ($this->opts['pauseTo'] ?? -1.0);
        $sampleEvery     = (int) ($this->opts['sampleEvery'] ?? 250);

        // split types into streams; rare evaluated first (priority), ≤ 1 release/tick total
        $streamTypes = ['rare' => [], 'workhorse' => []];
        foreach ($this->types as $id => $t) { $streamTypes[$t->stream][$id] = $t; }
        $streamTarget = ['rare' => $rareTotal, 'workhorse' => $totalTarget - $rareTotal];

        $ids = array_keys($this->types);
        $released      = array_fill_keys($ids, 0);
        $maxUnsold     = array_fill_keys($ids, 0);
        $minFree       = array_fill_keys($ids, PHP_INT_MAX);
        $ticksAtFloor  = array_fill_keys($ids, 0);
        $dropTicks     = array_fill_keys($ids, []);
        $minMargin     = [];
        foreach ($this->groups as $gid => $g) { $minMargin[$gid] = $g->poolFreeTiB - $g->reserveTiB; }

        // published == enforced accumulators (workhorse stream — enough drops for statistics)
        $whDrops = 0; $whSumOdds = array_fill_keys(array_keys($streamTypes['workhorse']), 0.0);

        $reserveBreaches = 0; $overAlloc = 0; $dropsTotal = 0;
        $activeAtMonthStart = ['rare' => 0, 'workhorse' => 0];
        $monthEndActive = [];        // monthIndex => cumulative active at month end
        $weeklyDrops = [];           // weekIndex => drops
        $probSeries  = [];           // [tickFraction, p_workhorse] samples
        $lastMonth = 0;

        $now = 0.0; $i = 0;
        for (; $i < $ticks; $i++, $now += $this->intervalSeconds) {
            $totalActive = $this->sumActive($ids);
            if ($totalActive >= $totalTarget) { break; }     // hit the mean — done

            // ── 1. locate this tick in time: current month + seconds left in month and in campaign ──
            $monthIndex = (int) floor($now / SIM_MONTH_SECONDS);
            if ($monthIndex > $lastMonth) {
                $monthEndActive[$lastMonth] = $totalActive;
                foreach (['rare', 'workhorse'] as $s) {
                    $activeAtMonthStart[$s] = $this->sumActive(array_keys($streamTypes[$s]));
                }
                $lastMonth = $monthIndex;
            }
            $monthSecondsLeft = max(1.0, SIM_MONTH_SECONDS - ($now - $monthIndex * SIM_MONTH_SECONDS));
            $totalSecondsLeft = max(1.0, $campaignSeconds - $now);

            // operator-control knob: 0 during a pause window, else 1
            $opControl = ($pauseFrom >= 0.0 && $now >= $pauseFrom && $now < $pauseTo) ? 0.0 : 1.0;

            // ── 2. per stream (rare first = priority): rebuild free from the shared pool, ask the engine, release ≤1 ──
            $releasedThisTick = false;
            foreach (['rare', 'workhorse'] as $s) {            // rare first => priority
                if ($releasedThisTick || !$streamTypes[$s]) { continue; }

                $slotTypes = [];
                foreach ($streamTypes[$s] as $id => $t) {
                    $g = $this->groups[$t->group];
                    // shared-pool interplay (the crux): this SKU's free slots = how many of its size fit in
                    // (pool − reserve). Every SKU in the group divides the SAME pool, so a claim on one shrinks
                    // the free count of all its siblings — the production cron must derive free the same way.
                    $free = (int) floor(max(0.0, $g->poolFreeTiB - $g->reserveTiB) / $t->sizeTiB);
                    $slotTypes[$id] = new SlotType($id, $free, $t->setAsideN, $t->qty, $t->stockThreshold,
                        $t->tierWeight, $t->withinTierBias, $t->multiplier);
                    if ($free < $minFree[$id])               { $minFree[$id] = $free; }
                    if ($free - $t->setAsideN <= 0)          { $ticksAtFloor[$id]++; }   // at/below the reserve floor
                }

                $streamActive   = $this->sumActive(array_keys($streamTypes[$s]));
                $monthlyTgt     = $streamTarget[$s] / max(1, $this->campaignMonths);
                $monthlyRemain  = max(0.0, $monthlyTgt - ($streamActive - $activeAtMonthStart[$s]));
                $totalRemain    = max(0.0, (float) ($streamTarget[$s] - $streamActive));
                // The rare stream can opt OUT of the never-zero floor (rareDailyFloor=0) so it slows down when
                // ahead and spreads evenly, instead of racing to a small target and stopping (front-loading).
                $dailyFloor = ($s === 'rare' && array_key_exists('rareDailyFloor', $this->opts))
                    ? (float) $this->opts['rareDailyFloor']
                    : EternalVainamoinenRelease::dailyFloorRate($monthlyTgt, $this->intervalSeconds);
                $pace = new Pacing(
                    $dailyFloor,
                    EternalVainamoinenRelease::rate($monthlyRemain, $monthSecondsLeft, $this->intervalSeconds),
                    EternalVainamoinenRelease::rate($totalRemain,  $totalSecondsLeft, $this->intervalSeconds),
                    $totalRemain,
                );
                $ev = $algo->evaluate($pace, $capHeadroom, $slotTypes, $opControl);

                if ($s === 'workhorse' && $i % $sampleEvery === 0) {
                    $probSeries[] = [round($now / $campaignSeconds, 4), round($ev->dropProbability, 5)];
                }
                if ($ev->chosenTypeId !== null) {
                    $cid = $ev->chosenTypeId;
                    $this->types[$cid]->qty++;
                    $released[$cid]++;
                    if ($this->types[$cid]->qty > $maxUnsold[$cid]) { $maxUnsold[$cid] = $this->types[$cid]->qty; }
                    $dropTicks[$cid][] = $i;
                    $dropsTotal++; $releasedThisTick = true;
                    $weeklyDrops[(int) floor($now / (7 * 86400))] = ($weeklyDrops[(int) floor($now / (7 * 86400))] ?? 0) + 1;
                    if ($s === 'workhorse') {
                        $whDrops++;
                        foreach ($ev->publishedOdds as $id => $po) { $whSumOdds[$id] += $po; }   // odds at the moment of each drop
                    }
                }
            }

            // ── 3. synthetic demand: released-unsold slots get claimed; each claim consumes the shared pool ──
            foreach ($this->types as $id => $t) {
                if ($t->qty > 0 && ($this->rng)() < $t->claimRate) {
                    $t->qty--; $t->active++;
                    $g = $this->groups[$t->group];
                    $g->poolFreeTiB -= $t->sizeTiB;
                    $margin = $g->poolFreeTiB - $g->reserveTiB;
                    if ($margin < $minMargin[$t->group]) { $minMargin[$t->group] = $margin; }
                }
            }

            // ── 4. invariants: reserve never breached, pool never over-allocated ──
            foreach ($this->groups as $gid => $g) {
                if ($g->poolFreeTiB < $g->reserveTiB - 1e-9) { $reserveBreaches++; }
                if ($g->poolFreeTiB < -1e-9)                 { $overAlloc++; }
            }
        }
        $monthEndActive[$lastMonth] = $this->sumActive($ids);

        // published == enforced: expected per-SKU share (avg published odds at drops) vs realized drop share
        $publishedExpected = []; $realizedShare = [];
        foreach (array_keys($streamTypes['workhorse']) as $id) {
            $publishedExpected[$id] = $whDrops > 0 ? $whSumOdds[$id] / $whDrops : 0.0;
            $realizedShare[$id]     = $whDrops > 0 ? $released[$id] / $whDrops : 0.0;
        }
        $maxOddsGap = 0.0;
        foreach ($publishedExpected as $id => $exp) {
            $maxOddsGap = max($maxOddsGap, abs($exp - $realizedShare[$id]));
        }

        return [
            'ticks_run'         => $i,
            'drops_total'       => $dropsTotal,
            'active_end'        => $this->sumActive($ids),
            'target'            => $totalTarget,
            'reserve_breaches'  => $reserveBreaches,
            'over_allocations'  => $overAlloc,
            'min_reserve_margin_tib' => $minMargin,          // smallest (pool − reserve) reached; >= 0 means never breached
            'released_by_type'  => $released,
            'claimed_by_type'   => array_combine($ids, array_map(fn ($id) => $this->types[$id]->active, $ids)),
            'max_unsold_by_type'=> $maxUnsold,
            'min_free_by_type'  => $minFree,
            'ticks_at_floor'    => $ticksAtFloor,            // ticks a SKU sat at/below its reserve floor (eligible weight 0)
            'month_end_active'  => $monthEndActive,
            'weekly_drops'      => $weeklyDrops,
            'prob_series'       => $probSeries,              // [campaignFraction, workhorse drop probability]
            'published_expected'=> $publishedExpected,       // avg published odds at drop (workhorse)
            'realized_share'    => $realizedShare,           // realized drop share (workhorse)
            'published_enforced_max_gap' => $maxOddsGap,     // |expected − realized| max; ~0 proves published == enforced
            'rare_drop_ticks'   => array_values(array_filter($dropTicks, fn ($d, $id) => $this->types[$id]->stream === 'rare', ARRAY_FILTER_USE_BOTH)),
        ];
    }

    /** @param string[] $ids */
    private function sumActive(array $ids): int
    {
        $s = 0;
        foreach ($ids as $id) { $s += $this->types[$id]->active; }
        return $s;
    }
}
