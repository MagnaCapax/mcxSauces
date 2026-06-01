<?php
declare(strict_types=1);

/**
 * Eternal Väinämöinen campaign — slot-release decision engine.
 *
 * WHY THIS EXISTS
 * ---------------
 * The campaign offers a real service at a fixed, deeply discounted price, but releases its availability
 * OVER TIME rather than all at once. Releasing everything immediately would dump months of capacity into
 * one day (no sustained event, no scarcity, and normal full-price stock swamped); releasing on a fixed
 * schedule is predictable and gameable. What is wanted instead is a steady, slightly-unpredictable drip
 * that paces toward a target over the campaign, holds back a reserve so normal sales always have stock,
 * weights releases toward where real capacity exists, and — crucially — publishes its exact behaviour so
 * customers and AI agents can verify it is fair. Published-but-unverifiable odds are worthless: an open
 * algorithm whose published odds EQUAL its enforced odds is the entire point.
 *
 * This is a timed-availability release of a fixed-price service — the customer always pays a fixed price
 * for a real service, and only the TIMING of when it becomes buyable is controlled.
 *
 * Origin: operator directives, 2026-05-31 (capacity = free − N; multi-horizon real-time pacing; per-type
 * weights; a pool of service types sharing physical capacity; factor-per-method pipeline; FOSS-reviewable;
 * a live operator-control knob). Industry grounding: ad-budget pacing (recompute remaining/time-left),
 * airline revenue-management protection levels (free − N), cumulative-weight selection.
 *
 * WHAT THIS DOES
 * -------------
 * A pure decision engine — inputs in, decision out; no database, clock, or I/O — so it is unit-testable
 * and publicly auditable. A thin cron is the only impure part: it gathers live state, calls this, applies
 * one qty++, and publishes the returned odds. Each FACTOR is a method, composed incrementally, in a fixed
 * order: decide IF a drop happens first, then WHICH slot type last.
 *
 * 1. Rate (whether a drop fires this tick). dropProbability() uses THREE real-time scales (a Pacing): the
 *    DAILY rate is a FLOOR so the chance is never quite zero while the campaign runs; the MONTHLY (700/mo)
 *    and TOTAL (4900) needs pull the rate UP when behind (max, not min). The TOTAL is the hard end —
 *    totalRemaining <= 0 stops the campaign. Real-time (remaining/seconds_left × interval) so the cron
 *    cadence (30s, 5min) does not change the realized rate. factorAccountCap() bounds it;
 *    factorOperatorControl() applies the operator's live knob (0 = pause, <1 throttle, >1 boost), part of
 *    the PUBLISHED config so live tuning stays verifiable.
 *
 * 2. Selection (which slot type, only if a drop fired). weights() chains the weight factors:
 *    factorCapacity (max(0, free − N), the protection level), factorStockSuppression (released-but-unsold
 *    stock → 0, tying releases to actual claims), factorTierWeight, factorWithinTierBias, factorMultiplier.
 *    One slot type is picked by cumulative-weight selection. publishedOdds() returns the exact normalized
 *    weights — what the page shows IS what the code uses.
 *
 * Rare / premium drops are simply a second stream of slot types: the caller runs decide() once per stream
 * and applies at most one release per tick.
 *
 * KNOWN LIMITATIONS
 * -----------------
 *   - Caller contract (net-active): pacing `remaining` must be computed against NET-ACTIVE customers, not
 *     gross releases, or it double-counts with stock suppression. The pure engine cannot enforce this.
 *   - Caller contract (shared-pool interplay): several slot types share one physical tier pool. Each
 *     type's `free` must be derived from the SHARED pool and recomputed each tick, or the engine
 *     over-releases past real capacity. This is the central correctness dependency and lives entirely in
 *     the caller; the simulator must assert the pool is never over-allocated.
 *   - One drop per tick across streams: when the workhorse and rare streams both run, a thin coordinator
 *     must ensure at most one release per tick.
 *   - Tightly bounded, can undershoot: the rate is capped at 1.0/tick and dropProbability returns 0 once
 *     net-active reaches the target (totalRemaining ≤ 0), so the drip stops there rather than running away.
 *     When behind, the monthly/total needs pull the rate UP (the max) to catch up within the cap; it can
 *     undershoot if demand or capacity lags, and a hard catch-up (e.g. after a long pause) can tip the
 *     realized total ~1 past target via in-flight demand on already-released stock. Bounded, not exact.
 *   - RNG: the default mt_rand is not reproducible. For publicly-auditable fairness, inject a seeded /
 *     commit-reveal RNG via the constructor.
 *
 * Security:
 *   - Pure function — no database, no I/O, no superglobals; there is nothing to inject.
 *   - No customer PII is touched; inputs are opaque ids plus integers/floats.
 *   - Slot-type ids are cast to (string) before use as array keys.
 *
 * Performance:
 *   - O(number of slot types) per tick — a handful of array passes. Negligible.
 *
 * Compatibility: PHP 8.0+ (constructor property promotion, arrow functions, named arguments).
 *
 * Made for pulsedmedia.com
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * @author    Aleksi Ursin
 * @copyright 2026 Magna Capax Finland Oy
 * @license   Apache-2.0
 */
final class EternalVainamoinenRelease
{
    // ── WHERE TO START READING ──────────────────────────────────────────────────────────────────
    // evaluate() is the entry point — call it once per stream per tick. It runs two stages in order:
    //   Stage 1, dropProbability(): does a drop fire this tick? (the pacing rate, capped + operator knob)
    //   Stage 2, weights() → pickByWeight(): IF it fired, which slot type? (capacity × suppression × biases)
    // The factor* methods are the individual ingredients of each stage — read them after evaluate(). And
    // publishedOdds() is exactly what Stage 2 enforces, so "the page shows this" is literally true.
    // ────────────────────────────────────────────────────────────────────────────────────────────
    /** @var callable():float Inject a seeded RNG for reproducible / publicly-auditable draws. */
    private $rng;

    public function __construct(?callable $rng = null)
    {
        $this->rng = $rng ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1.0);
    }

    // ───────── RATE factor methods: each returns a per-tick drop probability; combined by MAX (need pulls up, floor holds) ─────────

    /**
     * Per-tick rate needed to clear `remaining` (net-active) over `secondsLeft` at this interval. The caller
     * uses this to build a Pacing (daily floor + monthly + total needs). Real-time, so the cron cadence is free.
     */
    public static function rate(float $remaining, float $secondsLeft, float $intervalSeconds): float
    {
        if ($secondsLeft <= 0.0 || $remaining <= 0.0) {
            return 0.0;
        }
        return min(1.0, max(0.0, ($remaining / $secondsLeft) * $intervalSeconds));
    }

    /**
     * Default daily floor rate: the steady minimum (monthlyTarget / 30.44 days) per tick, so the chance never
     * quite reaches zero while the campaign runs. 30.44 = the average Gregorian month (365.25 / 12). This is the
     * sensible default (it equals the nominal pace); pass a smaller dailyFloor to Pacing if you want more
     * slow-down when ahead. Not an operator decision — a derived default.
     */
    public static function dailyFloorRate(float $monthlyTarget, float $intervalSeconds): float
    {
        return self::rate($monthlyTarget, 30.44 * 86400.0, $intervalSeconds);
    }

    /** Total-account cap expressed as a per-tick rate ceiling. */
    private function factorAccountCap(float $headroomRate): float
    {
        return min(1.0, max(0.0, $headroomRate));
    }

    /**
     * Operator's live control knob on the drop rate: 0 = pause / kill-switch, <1 throttle, >1 boost.
     * This (and the per-type `multiplier` / `setAsideN` config) is how the operator tunes live over the
     * campaign. It is part of the PUBLISHED config — the live odds reflect it, so tuning stays verifiable.
     */
    private function factorOperatorControl(float $p, float $controlMultiplier): float
    {
        return min(1.0, max(0.0, $p * $controlMultiplier));
    }

    // ───────── WEIGHT factor methods: each transforms per-type weights incrementally ─────────

    /** Base weight = max(0, free - N): the protection level / booking limit (normal stock always reserved). */
    private function factorCapacity(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            $w[$id] = (float) max(0, $t->free - $t->setAsideN);
        }
        return $w;
    }

    /** Released-but-unsold stock suppresses the type (claims are the bottleneck — don't pile up inventory). */
    private function factorStockSuppression(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            if ($t->stock >= $t->stockThreshold) {
                $w[$id] = 0.0;
            }
        }
        return $w;
    }

    private function factorTierWeight(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            $w[$id] *= $t->tierWeight;            // storage tiers heavier
        }
        return $w;
    }

    private function factorWithinTierBias(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            $w[$id] *= $t->withinTierBias;        // storage SKU > seedbox SKU within a tier
        }
        return $w;
    }

    private function factorMultiplier(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            $w[$id] *= $t->multiplier;            // per-slot-type knob
        }
        return $w;
    }

    // ───────── composition: rate first (whether), weights last (which) ─────────

    /**
     * Stage 1 — does a drop fire this tick?  p = min over rate methods.
     * @param array<int,array{0:float,1:float}> $horizons each [remainingActive, secondsLeft] (short/month/campaign)
     */
    public function dropProbability(Pacing $pace, float $capHeadroomRate, float $operatorControl = 1.0): float
    {
        if ($pace->totalRemaining <= 0.0) {
            return 0.0;                                                       // campaign target reached — hard stop
        }
        // The MONTHLY and TOTAL needs pull the rate UP when behind; the DAILY rate is a FLOOR so the chance is
        // never quite zero while the campaign runs. Then the account cap bounds it and the operator knob scales it.
        $paced = max($pace->dailyFloor, $pace->monthlyNeed, $pace->totalNeed);
        $paced = min($paced, $this->factorAccountCap($capHeadroomRate));
        return $this->factorOperatorControl(min(1.0, max(0.0, $paced)), $operatorControl);
    }

    /** Stage 2 — per-type selection weights; factor methods chained incrementally. @param SlotType[] $types keyed by id */
    public function weights(array $types): array
    {
        $w = array_fill_keys(array_keys($types), 0.0);
        $w = $this->factorCapacity($w, $types);
        $w = $this->factorStockSuppression($w, $types);
        $w = $this->factorTierWeight($w, $types);
        $w = $this->factorWithinTierBias($w, $types);
        $w = $this->factorMultiplier($w, $types);
        return $w;
    }

    /** Published effective odds (== enforced — the page reads exactly this). */
    public function publishedOdds(array $types): array
    {
        $w = $this->weights($types);
        $sum = array_sum($w);
        if ($sum <= 0.0) {
            return array_map(static fn (): float => 0.0, $w);
        }
        return array_map(static fn (float $x): float => $x / $sum, $w);
    }

    /**
     * Full evaluation — the decision AND every value behind it, so the caller can append one auditable
     * record per tick to the public drop feed. No I/O happens here (this stays pure); the cron does the
     * logging. This is what makes the campaign agent-followable: each tick's exact numbers are published.
     */
    public function evaluate(Pacing $pace, float $capHeadroomRate, array $types, float $operatorControl = 1.0): DropEvaluation
    {
        $dropProbability = $this->dropProbability($pace, $capHeadroomRate, $operatorControl);
        $weights         = $this->weights($types);
        $totalWeight     = array_sum($weights);
        $publishedOdds   = $totalWeight <= 0.0
            ? array_map(static fn (): float => 0.0, $weights)
            : array_map(static fn (float $x): float => $x / $totalWeight, $weights);

        $chosenTypeId = null;
        if (($this->rng)() < $dropProbability && $totalWeight > 0.0) {
            $chosenTypeId = $this->pickByWeight($weights, $totalWeight);
        }
        return new DropEvaluation($dropProbability, $chosenTypeId, $publishedOdds, $weights);
    }

    /** Decide ONE stream: returns the chosen slot-type id, or null for no drop this tick. */
    public function decide(Pacing $pace, float $capHeadroomRate, array $types, float $operatorControl = 1.0): ?string
    {
        return $this->evaluate($pace, $capHeadroomRate, $types, $operatorControl)->chosenTypeId;
    }

    /** The basic weighted pick: sum the weights, roll a point in [0, total), walk the cumulative sum. */
    private function pickByWeight(array $weights, float $totalWeight): string
    {
        $point = ($this->rng)() * $totalWeight;
        $cumulative = 0.0;
        foreach ($weights as $id => $weight) {
            $cumulative += $weight;
            if ($point < $cumulative) {
                return (string) $id;
            }
        }
        return (string) array_key_last($weights);              // float-rounding safety net
    }
}

/**
 * The three real-time pacing scales for one stream. The caller builds this each tick from the clock + net-active
 * counts (use EternalVainamoinenRelease::rate() for each need). dailyFloor is the never-zero minimum; monthlyNeed
 * and totalNeed pull the rate up when behind; totalRemaining <= 0 ends the campaign (the 4900 hard stop).
 */
final class Pacing
{
    public function __construct(
        public float $dailyFloor,      // steady minimum rate (~ monthlyTarget / 30.44 days, per tick)
        public float $monthlyNeed,     // rate() of this month's remaining / seconds left this month
        public float $totalNeed,       // rate() of campaign remaining / seconds left in campaign
        public float $totalRemaining,  // campaign net-active still owed; <= 0 => hard stop
    ) {}
}

/** Immutable input value object. */
final class SlotType
{
    public function __construct(
        public string $id,            // WHMCS PID (opaque)
        public int $free,             // free slots derived from the SHARED tier pool (caller computes => interplay)
        public int $setAsideN,        // protection level held back for normal full-price sales
        public int $stock,            // released-but-unsold qty
        public int $stockThreshold,   // suppress once unsold stock reaches this
        public float $tierWeight,     // storage tiers heavier
        public float $withinTierBias, // storage SKU > seedbox SKU within a tier
        public float $multiplier,     // per-slot-type knob
    ) {}
}

/**
 * One tick's full evaluation — exactly what the cron appends to the public drop feed so anyone
 * (humans, AI agents) can follow the campaign tick by tick: the drop probability used, the slot type
 * chosen (or null), and the published == enforced odds and raw weights behind the choice.
 */
final class DropEvaluation
{
    /**
     * @param array<string,float> $publishedOdds id => P(type | a drop fires)  (what the page shows)
     * @param array<string,float> $weights       id => raw selection weight (0 = ineligible / suppressed)
     */
    public function __construct(
        public float $dropProbability,
        public ?string $chosenTypeId,
        public array $publishedOdds,
        public array $weights,
    ) {}
}
