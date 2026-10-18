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
 * 1. Rate (whether a drop fires this tick). dropProbability() takes the MIN of the Pacing rates. The live cron
 *    passes ONE rate per stream: rate(target − (Active+Free), seconds left in the campaign, interval) — the mean
 *    pace that lands the target at the campaign end, falling as (Active+Free) catches up. The TOTAL is the hard
 *    end — totalRemaining <= 0 stops the stream. Real-time (remaining/seconds_left × interval) so the cron cadence
 *    (30s, 5min) does not change the realized rate. factorAccountCap() bounds it; factorGlobalOpen() tapers it
 *    as the TOTAL open (unsold) stock across every SKU nears TARGET_OPEN; factorOperatorControl() applies the
 *    operator's live knob (0 = pause, <1 throttle, >1 boost), part of the PUBLISHED config.
 *
 * 1b. Limited releases ("Claimed: N of LIMIT"). A SKU with a published limit is its own stream with a due date.
 *    limitedRate() is its MAXIMUM rate: what may still be released (limit − claimed − open) spread evenly over the
 *    time left to the due date. It is passed as the Pacing rate AND its ceiling (Pacing::maxRate), so no knob can
 *    push the SKU faster than "the whole limit by the due date". withinLimit() is the hard stop the caller
 *    re-checks at the release itself: claimed + open may never reach past the limit.
 *
 * 2. Selection (which slot type, only if a drop fired). weights() chains the weight factors:
 *    factorCapacity (max(0, free − N), the protection level), factorOverSupply (a smooth one-sided divider
 *    on released-but-unsold OPEN slots — chance / (1 + (open − K)/D), hard 0 at a DYNAMIC ceiling min(MAX, free)
 *    — so a SKU that is not selling accumulates open stock and self-curbs, with no per-SKU tuning, and open
 *    can never exceed true free capacity), factorTierWeight,
 *    factorWithinTierBias, factorMultiplier. One slot type is picked by cumulative-weight selection.
 *    publishedOdds() returns the exact normalized weights — what the page shows IS what the code uses.
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
 *     (Active+Free) reaches the target (totalRemaining ≤ 0), so the drip stops there rather than running away.
 *     When behind, the remaining/time-left rate rises to catch up within the cap; it can undershoot if demand
 *     or capacity lags. A limited SKU never passes its limit: the caller re-checks withinLimit() at the release.
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
    // Over-supply curb tunables (factorOverSupply) — a single static curve on OPEN unsold slots; the sales
    // feedback loop (open accumulates only when not selling) makes one curve adapt per-SKU with no per-plan
    // figures. Calibrated 2026-06-08 (open=5 → ÷1.2); MAX lowered 15→10 on 2026-07-02, which halves the
    // worst-case simultaneous-claim commitment while capacity is tight; K/D curve unchanged:
    private const OVERSUPPLY_K   = 3.0;    // no curb at/below this many open slots (shopfront buffer)
    private const OVERSUPPLY_D   = 10.0;   // divider slope: weight ÷ (1 + (open − K)/D) above K
    private const OVERSUPPLY_MAX = 10.0;   // marketing upper bound; the LIVE hard 0 is min(this, free) — see factorOverSupply

    // (Removed 2026-06-14) The Stage-1 over-supply-on-the-CHANCE divider/hard-stop was deleted: over-supply is a
    // STAGE-2 (which-SKU) concern only (factorOverSupply). The RATE just paces to the mean; jamming over-supply into
    // the rate strangled the drip (~5/day vs the ~23/day mean) for a handful of unsold slots. Pacing->totalOpen now
    // carries the GLOBAL open count for the buffer governor (factorGlobalOpen, below), not a per-stream curb.

    /** @var callable():float Inject a seeded RNG for reproducible / publicly-auditable draws. */
    private $rng;

    public function __construct(?callable $rng = null)
    {
        $this->rng = $rng ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1.0);
    }

    // ───────── RATE methods: each returns a per-tick drop probability ─────────

    /**
     * Per-tick rate needed to clear `remaining` (still to release) over `secondsLeft` at this interval: the mean
     * pace that lands `remaining` exactly at the end of `secondsLeft`. Real-time, so the cron cadence is free.
     */
    public static function rate(float $remaining, float $secondsLeft, float $intervalSeconds): float
    {
        if ($secondsLeft <= 0.0 || $remaining <= 0.0) {
            return 0.0;
        }
        return min(1.0, max(0.0, ($remaining / $secondsLeft) * $intervalSeconds));
    }

    /**
     * MAXIMUM per-tick rate of a limited SKU ("Claimed: N of LIMIT", sold by a due date): what may still be
     * released, limit − claimed − open, spread evenly over the time left to the due date. 0 at the limit. At or past
     * the due date with stock still allowed, one interval is the horizon (rate = min(1, remaining)), so a SKU the
     * gates held back still finishes, at most one release per tick. `claimed` counts every slot ever taken (sold,
     * including ones later cancelled), the same count the page shows as "Claimed".
     */
    public static function limitedRate(int $limit, int $claimed, int $open, float $secondsToDue, float $intervalSeconds): float
    {
        return self::rate((float) max(0, $limit - $claimed - $open), max($intervalSeconds, $secondsToDue), $intervalSeconds);
    }

    /** Hard stop for a limited SKU: one more release is allowed only while claimed + open stays below the limit. */
    public static function withinLimit(int $limit, int $claimed, int $open): bool
    {
        return $claimed + $open < $limit;
    }

    /** Total-account cap expressed as a per-tick rate ceiling. */
    private function factorAccountCap(float $headroomRate): float
    {
        return min(1.0, max(0.0, $headroomRate));
    }

    // ── Global open-buffer governor (added 2026-08-22, target raised 30 -> 50 on 2026-10-05) ──
    // Scales the total drip RATE down as GLOBAL total open (sum across ALL campaign SKUs) approaches TARGET_OPEN.
    // Full rate while open <= TARGET_OPEN - TAPER_BAND; linear taper to 0 at TARGET_OPEN. Holds total open ~TARGET_OPEN:
    // when slots sell (open falls), the factor rises and the drip refills toward the target. This governs the BUFFER,
    // NOT throughput — as customers claim, open drops and the drip resumes, so the 4900 total is unaffected.
    private const TARGET_OPEN = 50.0;   // operator knob: desired steady-state TOTAL open across all campaign SKUs (30 -> 50 on 2026-10-05)
    private const TAPER_BAND  = 10.0;   // operator knob: width of the ramp below TARGET_OPEN (full->0 over this span)

    /** Governor factor in [0,1]: 1 when total open is low, 0 at/above TARGET_OPEN. Applied to the drip rate. */
    private function factorGlobalOpen(float $globalOpen): float
    {
        return min(1.0, max(0.0, (self::TARGET_OPEN - $globalOpen) / self::TAPER_BAND));
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

    /**
     * Over-supply curb: a smooth one-sided divider on released-but-unsold OPEN stock. As open inventory
     * grows past OVERSUPPLY_K it divides the weight by 1 + (open − K)/D, reaching a hard 0 at a DYNAMIC
     * ceiling = min(OVERSUPPLY_MAX, free). Because open stock only ACCUMULATES when releases outpace claims,
     * this self-curbs a SKU that is not selling (open piles up → weight falls) while a strong seller never
     * accumulates open (no curb) — a single static curve yields per-SKU-adaptive behaviour with NO per-SKU
     * tuning. One-sided by design: below K there is NO ramp, so it only ever curbs over-supply, never boosts
     * scarcity (that would dilute "emphasize strong selling"). K=3/D=10/MAX=10: open=5 → ÷1.2
     * (calibrated 2026-06-08; MAX 15→10 on 2026-07-02).
     *
     * LOAD-BEARING SAFETY — DO NOT REMOVE THE min(.., free): the hard ceiling is the SKU's LIVE true free
     * capacity, not a static number. `free` is recomputed from the shared pool every tick and EXCLUDES
     * released-but-unsold open; if open were allowed past free, claims on that overhang would breach the
     * shared-pool reserve. This is the safety property the OLD binary stock suppression silently held —
     * removing it reintroduces reserve breaches (simulator constrained scenario: 0 → 7265 breaches). The
     * static OVERSUPPLY_MAX is only the marketing upper bound when capacity is ample; `free` binds when tight.
     */
    private function factorOverSupply(array $w, array $types): array
    {
        foreach ($types as $id => $t) {
            $open    = (float) $t->stock;
            $ceiling = min((float) self::OVERSUPPLY_MAX, (float) $t->free);   // dynamic: static cap OR true free capacity
            if ($open >= $ceiling) {
                $w[$id] = 0.0;                                                // at/over true capacity → stop releasing
                continue;
            }
            $excess = max(0.0, $open - self::OVERSUPPLY_K);                   // no curb below K
            $w[$id] /= 1.0 + $excess / self::OVERSUPPLY_D;
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
     * Stage 1 — does a drop fire this tick? Purely a RATE question: the MIN of the Pacing rates (the live cron passes
     * one: remaining / time left), so the rate falls as (Active+Free) catches up and is 0 once the target is
     * released. Then the account cap, the global open buffer, the operator knob and the maxRate ceiling. NOTHING about over-supply
     * lives here — over-supply is a STAGE-2 (which-SKU) concern (factorOverSupply), not a rate concern. Keeping the
     * rate clean is the point: it just draws the campaign toward the mean (e.g. 700/month) and catches up when behind.
     */
    public function dropProbability(Pacing $pace, float $capHeadroomRate, float $operatorControl = 1.0): float
    {
        if ($pace->totalRemaining <= 0.0) {
            return 0.0;                                                       // already released the whole target — stop
        }
        // The tightest needed rate governs (min). No floor — the chance can and should fall to 0 when already-released
        // is ahead of pace.
        $paced = $pace->rates ? min($pace->rates) : 0.0;
        $paced = min($paced, $this->factorAccountCap($capHeadroomRate));
        $paced *= $this->factorGlobalOpen($pace->totalOpen);                  // hold GLOBAL total open ~ TARGET_OPEN
        // maxRate: the ceiling no knob may exceed (a limited SKU's limitedRate(); 1.0 for the streams)
        return min($pace->maxRate, $this->factorOperatorControl(min(1.0, max(0.0, $paced)), $operatorControl));
    }

    /** Stage 2 — per-type selection weights; factor methods chained incrementally. @param SlotType[] $types keyed by id */
    public function weights(array $types): array
    {
        $w = array_fill_keys(array_keys($types), 0.0);
        $w = $this->factorCapacity($w, $types);
        $w = $this->factorOverSupply($w, $types);
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
 * The pacing input for one stream/tick. `rates` are needed per-tick rates; min() governs (the live cron passes one:
 * remaining / time left). totalRemaining <= 0 ends the stream (the hard stop). `maxRate` is a ceiling applied after
 * every factor and knob — a limited SKU passes its limitedRate() here so nothing can drip it faster.
 */
final class Pacing
{
    /** @param float[] $rates needed per-tick release rates; min() governs */
    public function __construct(
        public array $rates,           // needed rate(s); min() governs
        public float $totalRemaining,  // still to release (target − (Active+Free), or limit − claimed − open); <= 0 => stop
        public float $totalOpen = 0.0, // GLOBAL total open (unsold) across ALL SKUs — governs the drip rate toward TARGET_OPEN (factorGlobalOpen)
        public float $maxRate = 1.0,   // ceiling after all factors and the operator knob (limited SKUs: limitedRate())
    ) {}
}

/** Immutable input value object. */
final class SlotType
{
    public function __construct(
        public string $id,            // WHMCS PID (opaque)
        public int $free,             // free slots derived from the SHARED tier pool (caller computes => interplay)
        public int $setAsideN,        // protection level held back for normal full-price sales
        public int $stock,            // released-but-unsold OPEN qty (drives factorOverSupply curb)
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
