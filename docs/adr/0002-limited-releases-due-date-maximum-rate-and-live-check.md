# ADR-0002: Limited releases have a due date, a maximum drop rate and a hard limit, checkable on the live feed

## Metadata
- Author: Väinämöinen (MCX)
- Date: 2026-10-18
- Status: Accepted

## Context
Some products in the Eternal Väinämöinen campaign are sold with a published limit ("Claimed: N of LIMIT"). Until
2026-10-18 the engine in `whmcs/eternal-vainamoinen/` had no notion of such a limit. The production cron paced every
product by its stream's campaign-wide target, and the limit was enforced, if at all, outside the published code. One
product reached its limit 74 days before the campaign end and then sold one slot over it. The fix had to do three
things: tie each limited product's pace to its own limit, make the limit a hard stop, and keep the rule inside the
published engine, so that the open code still describes what the live campaign does.

## Options Considered
- **Keep the limit rules in the private cron.** The least code to change, but the published engine would no longer
  describe how limited products are paced, and nobody outside could check them. Rejected: the repository's point is
  that published equals enforced.
- **Release a limited product on a fixed schedule (one every N ticks).** Even spacing, but predictable and gameable,
  and a different mechanism from every other product. Rejected.
- **Pace each limited product to its own due date with the existing remaining / time-left rate, cap it, and publish
  the inputs.** The same mechanism the streams already use, so one rule describes both. Chosen.

## Decision
- A limited product has a **due date**: its own date if one is set, otherwise the campaign end.
- `EternalVainamoinenRelease::limitedRate(limit, taken, open, secondsToDue, interval)` gives its **maximum** per-tick
  drop chance: what may still be released (limit − taken − open) spread evenly over the time left to the due date.
  `taken` = claimed (every slot ever taken, cancelled ones included) + pending (unpaid orders, each of which holds a
  slot). At the limit the rate is 0. At or past the due date with stock still allowed, the rate is min(1, remaining).
- `Pacing` gains `maxRate` (default 1.0, so existing callers are unchanged). It is applied after every factor and the
  operator knob, so the knob can still slow a limited product but can never make it faster than its maximum.
- `EternalVainamoinenRelease::withinLimit(limit, taken, open)` is the hard stop the caller re-checks at the release.
  The caller also trims open stock back to `limit − taken` every tick for every limited product, including one-shot
  batches that are not dripped, because a cancelled order hands its slot back to stock.
- The live feed publishes, per limited product, `limit`, `claimed`, `pending`, `due` and `max_drop_chance_per_tick`,
  plus the knob value `operator_control`. `verify-live-feed.php` recomputes the maximum from those fields with the
  published engine and checks the three rules (limit not passed, maximum as published, chance not above it).
- The unused `dailyFloorRate()` is removed; the live cron has not used a daily floor since 2026-06-18.

## Consequences
- Anyone can run `php verify-live-feed.php` against the live feed and see whether every limited product obeys this
  code right now. The tests cover the rate, the ceiling and a 20-seed run of a 49-limit product (never above 49,
  sell-out between 93.5% and 99.9% of the time to the due date).
- A product whose maximum is below its normal stream pace now drips more slowly than before. That is the intent.
- A one-shot batch (all slots on sale at once) is not in the drip feed; its page card shows Available and Claimed,
  and the per-tick trim keeps their sum at or under the limit.
- Accepted limitation: if an order is cancelled between the cron's two reads in one tick, the trim can withhold one
  slot. It never oversells; for a one-shot batch that slot is not re-released.

## References
- `whmcs/eternal-vainamoinen/README.md` — "Limited releases" and "Check the live campaign".
- `whmcs/eternal-vainamoinen/EternalVainamoinenReleaseTest.php` — limited-release tests.
- [ADR-0001](0001-architecture-layout-and-languages.md) — layout; `whmcs/eternal-vainamoinen/` now also holds `verify-live-feed.php`.

## Amendment 2026-10-25: the open-stock buffer counts only the limited product's own stock

The global open-stock buffer (`factorGlobalOpen`, total unsold stock near 50) also slowed limited products. With
other products' unsold stock near the buffer, a limited product ran at a fraction of its maximum and could not reach
its due date; a large manual restock of another product stopped it entirely. The live drip now gives a limited
product's pacing its own open stock as the buffer input, so other products' stock cannot hold it back. Its maximum
rate, the hard limit, the capacity and over-supply checks, the operator knob and the one-release-per-tick rule are
unchanged. The simulator and README follow the same rule.
